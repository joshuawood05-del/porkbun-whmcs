<?php
/**
 * Porkbun webhook receiver for the WHMCS registrar module.
 * ---------------------------------------------------------
 * Porkbun pushes signed events as they happen, so WHMCS learns about a
 * completed transfer or an out-of-band renewal within about a minute instead
 * of waiting for the next domain sync cron.
 *
 * This file has two modes:
 *
 *   HTTP  Porkbun POSTs signed events here. Register the public URL with
 *         --register below, then paste the printed secret into the registrar
 *         configuration as "Webhook Signing Secret".
 *
 *   CLI   Setup and inspection:
 *           php webhook.php --register https://billing.example.com/modules/registrars/porkbun/webhook.php
 *           php webhook.php --list
 *           php webhook.php --test [id]
 *           php webhook.php --disable <id>
 *
 * Porkbun only delivers to https:// on port 443 where the hostname resolves to
 * a public address; private, loopback and CGNAT targets are refused. It checks
 * this again immediately before every delivery, not just at registration.
 *
 * The cron-driven Sync stays in place. Webhooks give speed, not certainty: an
 * endpoint that fails 20 deliveries in a row is disabled by Porkbun, and the
 * cron is what notices.
 *
 * @package    WHMCS\Module\Registrar\Porkbun
 * @license    MIT
 */

require __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/PorkbunApi.php';

use WHMCS\Module\Registrar\Porkbun\PorkbunApi;
use Illuminate\Database\Capsule\Manager as Capsule;

const PORKBUN_WEBHOOK_TABLE = 'mod_porkbun_webhook_events';

/** Reject a delivery signed more than this many seconds ago. */
const PORKBUN_WEBHOOK_MAX_AGE = 300;

/** How long processed event ids are kept for deduplication. */
const PORKBUN_WEBHOOK_RETENTION_DAYS = 30;

if (php_sapi_name() === 'cli') {
    exit(porkbun_webhook_cli(array_slice($argv, 1)));
}

porkbun_webhook_serve();

/* ======================================================================
 * HTTP: receive a delivery
 * ==================================================================== */

function porkbun_webhook_serve()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        porkbun_webhook_respond(405, 'POST only');
    }

    // The signature covers the exact bytes received, so read and verify the
    // raw body before it is parsed into anything.
    $body = file_get_contents('php://input');
    $timestamp = porkbun_webhook_header('HTTP_X_PORKBUN_WEBHOOK_TIMESTAMP');
    $signature = porkbun_webhook_header('HTTP_X_PORKBUN_SIGNATURE');
    $eventId = porkbun_webhook_header('HTTP_X_PORKBUN_WEBHOOK_ID');

    $config = porkbun_webhook_config();
    $secret = isset($config['webhookSecret']) ? trim((string) $config['webhookSecret']) : '';

    if ($secret === '') {
        // A 5xx has Porkbun retry with backoff, which is what we want while
        // somebody pastes the secret in. Note that 20 consecutive failures
        // disable the endpoint at Porkbun's end.
        porkbun_webhook_log('Webhook Rejected', array('reason' => 'no signing secret configured'), $body);
        porkbun_webhook_respond(503, 'Webhook signing secret is not configured in WHMCS');
    }

    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    if ($signature === '' || !hash_equals($expected, $signature)) {
        porkbun_webhook_log('Webhook Rejected', array('reason' => 'bad signature', 'eventId' => $eventId), $body);
        porkbun_webhook_respond(400, 'Bad signature');
    }

    if ($timestamp === '' || abs(time() - (int) $timestamp) > PORKBUN_WEBHOOK_MAX_AGE) {
        porkbun_webhook_log('Webhook Rejected', array('reason' => 'stale timestamp', 'eventId' => $eventId), $body);
        porkbun_webhook_respond(400, 'Stale timestamp');
    }

    $event = json_decode($body, true);
    if (!is_array($event) || empty($event['event'])) {
        porkbun_webhook_log('Webhook Rejected', array('reason' => 'unparseable body', 'eventId' => $eventId), $body);
        porkbun_webhook_respond(400, 'Unparseable body');
    }

    if ($eventId === '') {
        $eventId = isset($event['id']) ? (string) $event['id'] : '';
    }

    // Porkbun may deliver the same event more than once, and a manual resend
    // deliberately reuses the original id, so claim the id before doing work.
    if ($eventId !== '' && !porkbun_webhook_claim($eventId, $event)) {
        porkbun_webhook_respond(200, 'Duplicate, already processed');
    }

    try {
        $result = porkbun_webhook_dispatch($event, $config);
        if ($eventId !== '') {
            porkbun_webhook_recordResult($eventId, $result);
        }
        porkbun_webhook_respond(200, $result);
    } catch (\Throwable $e) {
        // Release the claim so Porkbun's retry gets another go at it.
        if ($eventId !== '') {
            porkbun_webhook_release($eventId);
        }
        porkbun_webhook_log('Webhook Failed', array('eventId' => $eventId, 'event' => $event), $e->getMessage());
        porkbun_webhook_respond(500, 'Processing failed: ' . $e->getMessage());
    }
}

/**
 * Route an event to a handler. Unknown events are logged and acknowledged:
 * the endpoint subscribes to "*" so new Porkbun event types arrive here
 * automatically and must not be treated as errors.
 *
 * @param array $event
 * @param array $config
 * @return string Human-readable outcome, stored and returned to Porkbun.
 */
function porkbun_webhook_dispatch(array $event, array $config)
{
    $type = (string) $event['event'];
    $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
    $domain = isset($data['domain']) ? strtolower(trim((string) $data['domain'])) : '';

    switch ($type) {
        case 'domain.transfer.completed':
            return porkbun_webhook_transferCompleted($domain, $config);

        case 'domain.renewed':
            return porkbun_webhook_renewed($domain, $config);

        case 'domain.registered':
            return porkbun_webhook_registered($domain, $config);

        case 'domain.expiring':
            return porkbun_webhook_expiring($domain, $data);

        case 'webhook.test':
            porkbun_webhook_log('Webhook Test', $event, 'Signature verified and receiver reachable.');
            return 'Test event received; signature verified';
    }

    porkbun_webhook_log('Webhook ' . $type, $event, 'Logged; no action taken for this event type.');
    return 'Logged, no action for ' . $type;
}

/* ======================================================================
 * Handlers
 *
 * Every handler that changes WHMCS re-reads the domain from the API first.
 * Only the event envelope is documented as stable; the per-event data object
 * is not, and nothing that touches a customer's record should rest on a
 * payload field rather than on an authenticated call we made ourselves.
 * ==================================================================== */

function porkbun_webhook_transferCompleted($domain, array $config)
{
    if ($domain === '') {
        return 'No domain in payload';
    }

    $row = porkbun_webhook_findDomain($domain);
    if (!$row) {
        return porkbun_webhook_unknownDomain($domain, 'transfer completed');
    }

    $state = porkbun_webhook_registrarState($domain, $config);
    $updates = array();

    // Pending Transfer is the status WHMCS leaves a transfer order in; the
    // cron would otherwise not clear it until its next run.
    if (in_array($row->status, array('Pending', 'Pending Transfer'), true) && $state['active']) {
        $updates['status'] = 'Active';
    }
    if ($state['expirydate'] !== '' && $state['expirydate'] !== $row->expirydate) {
        $updates['expirydate'] = $state['expirydate'];
    }

    return porkbun_webhook_applyUpdates($row, $domain, $updates, 'Transfer completed');
}

function porkbun_webhook_renewed($domain, array $config)
{
    if ($domain === '') {
        return 'No domain in payload';
    }

    $row = porkbun_webhook_findDomain($domain);
    if (!$row) {
        return porkbun_webhook_unknownDomain($domain, 'renewed');
    }

    $state = porkbun_webhook_registrarState($domain, $config);
    $updates = array();

    if ($state['expirydate'] !== '' && $state['expirydate'] !== $row->expirydate) {
        $updates['expirydate'] = $state['expirydate'];
    }
    if ($row->status === 'Expired' && $state['active']) {
        $updates['status'] = 'Active';
    }

    // Deliberately not touching nextduedate or any billing field. A renewal
    // Porkbun performed (auto-renew, or somebody in the dashboard) may have no
    // matching WHMCS invoice, and guessing at the billing side of that is how
    // customers get billed twice or not at all. The expiry date is corrected;
    // the money stays a human decision.
    $outcome = porkbun_webhook_applyUpdates($row, $domain, $updates, 'Renewed at registrar');

    if (!$row->id || empty($updates)) {
        return $outcome;
    }

    logActivity('Porkbun: ' . $domain . ' was renewed at the registrar. WHMCS expiry updated to '
        . (isset($updates['expirydate']) ? $updates['expirydate'] : $row->expirydate)
        . '. Check that billing for this renewal is correct.');

    return $outcome;
}

function porkbun_webhook_registered($domain, array $config)
{
    if ($domain === '') {
        return 'No domain in payload';
    }

    $row = porkbun_webhook_findDomain($domain);
    if (!$row) {
        // WHMCS started nearly every registration, so one it has never heard
        // of is a domain on the account that nobody is being billed for.
        return porkbun_webhook_unknownDomain($domain, 'registered');
    }

    $state = porkbun_webhook_registrarState($domain, $config);
    $updates = array();
    if ($state['expirydate'] !== '' && $state['expirydate'] !== $row->expirydate) {
        $updates['expirydate'] = $state['expirydate'];
    }

    return porkbun_webhook_applyUpdates($row, $domain, $updates, 'Registered');
}

/**
 * Porkbun's 60/30/5-day expiry warnings are used here only to catch WHMCS and
 * the registrar disagreeing. WHMCS sends its own renewal notices from its own
 * dates, so this deliberately alerts rather than acts.
 */
function porkbun_webhook_expiring($domain, array $data)
{
    if ($domain === '') {
        return 'No domain in payload';
    }

    $row = porkbun_webhook_findDomain($domain);
    if (!$row) {
        return porkbun_webhook_unknownDomain($domain, 'expiring');
    }

    $registrarExpiry = '';
    if (!empty($data['expireDate'])) {
        $stamp = strtotime((string) $data['expireDate']);
        if ($stamp !== false) {
            $registrarExpiry = date('Y-m-d', $stamp);
        }
    }

    if ($registrarExpiry !== '' && $registrarExpiry !== $row->expirydate) {
        $message = 'Porkbun says ' . $domain . ' expires ' . $registrarExpiry
            . ' but WHMCS has ' . $row->expirydate . '. One of them is wrong.';
        porkbun_webhook_log('Webhook Expiry Mismatch', array('domain' => $domain), $message);
        logActivity('Porkbun: ' . $message);
        return 'Expiry mismatch flagged';
    }

    porkbun_webhook_log('Webhook Expiring', array('domain' => $domain), 'Registrar expiry matches WHMCS.');
    return 'Expiry agrees with WHMCS';
}

/* ======================================================================
 * WHMCS domain records
 * ==================================================================== */

function porkbun_webhook_findDomain($domain)
{
    return Capsule::table('tbldomains')
        ->where('domain', $domain)
        ->orderBy('id', 'desc')
        ->first();
}

/**
 * Ask the API what the domain actually looks like now.
 *
 * @return array{active: bool, expired: bool, expirydate: string}
 */
function porkbun_webhook_registrarState($domain, array $config)
{
    $api = porkbun_webhook_api($config);
    $response = $api->getDomain($domain);
    $data = isset($response['domain']) && is_array($response['domain']) ? $response['domain'] : array();

    $status = isset($data['status']) ? strtoupper((string) $data['status']) : '';
    $expiry = '';
    if (!empty($data['expireDate'])) {
        $stamp = strtotime((string) $data['expireDate']);
        if ($stamp !== false) {
            $expiry = date('Y-m-d', $stamp);
        }
    }

    return array(
        'active' => ($status === 'ACTIVE'),
        'expired' => ($status === 'EXPIRED'),
        'expirydate' => $expiry,
    );
}

function porkbun_webhook_applyUpdates($row, $domain, array $updates, $because)
{
    if (empty($updates)) {
        porkbun_webhook_log('Webhook ' . $because, array('domain' => $domain), 'WHMCS already matches the registrar; nothing to change.');
        return $because . ': already in step';
    }

    Capsule::table('tbldomains')->where('id', $row->id)->update($updates);

    $summary = array();
    foreach ($updates as $field => $value) {
        $summary[] = $field . ' ' . (isset($row->$field) ? $row->$field : '?') . ' -> ' . $value;
    }
    $summary = implode(', ', $summary);

    porkbun_webhook_log('Webhook ' . $because, array('domain' => $domain, 'domainid' => $row->id), $summary);
    logActivity('Porkbun webhook (' . $because . ') updated domain ' . $domain . ' (#' . $row->id . '): ' . $summary);

    return $because . ': ' . $summary;
}

function porkbun_webhook_unknownDomain($domain, $what)
{
    $message = $domain . ' was ' . $what . ' on the Porkbun account but has no matching WHMCS domain record. '
        . 'If this is not deliberate it is a domain nobody is being billed for.';

    porkbun_webhook_log('Webhook Unmatched Domain', array('domain' => $domain), $message);
    logActivity('Porkbun: ' . $message);

    return 'No WHMCS record for ' . $domain;
}

/* ======================================================================
 * Deduplication ledger
 * ==================================================================== */

function porkbun_webhook_ensureTable()
{
    if (Capsule::schema()->hasTable(PORKBUN_WEBHOOK_TABLE)) {
        return;
    }

    Capsule::schema()->create(PORKBUN_WEBHOOK_TABLE, function ($table) {
        $table->string('event_id', 64)->primary();
        $table->string('event_type', 64)->default('');
        $table->string('domain', 255)->default('');
        $table->text('result')->nullable();
        $table->dateTime('received_at');
        $table->index('received_at');
    });
}

/**
 * Claim an event id. Returns false when this event has already been handled,
 * which is the signal to acknowledge without doing the work twice.
 *
 * @return bool
 */
function porkbun_webhook_claim($eventId, array $event)
{
    porkbun_webhook_ensureTable();

    $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();

    try {
        Capsule::table(PORKBUN_WEBHOOK_TABLE)->insert(array(
            'event_id' => $eventId,
            'event_type' => isset($event['event']) ? substr((string) $event['event'], 0, 64) : '',
            'domain' => isset($data['domain']) ? substr((string) $data['domain'], 0, 255) : '',
            'result' => null,
            'received_at' => date('Y-m-d H:i:s'),
        ));
    } catch (\Exception $e) {
        // Primary key collision: a duplicate delivery, or one already in
        // flight on another request.
        return false;
    }

    Capsule::table(PORKBUN_WEBHOOK_TABLE)
        ->where('received_at', '<', date('Y-m-d H:i:s', strtotime('-' . PORKBUN_WEBHOOK_RETENTION_DAYS . ' days')))
        ->delete();

    return true;
}

function porkbun_webhook_recordResult($eventId, $result)
{
    Capsule::table(PORKBUN_WEBHOOK_TABLE)->where('event_id', $eventId)->update(array('result' => $result));
}

function porkbun_webhook_release($eventId)
{
    Capsule::table(PORKBUN_WEBHOOK_TABLE)->where('event_id', $eventId)->delete();
}

/* ======================================================================
 * Shared helpers
 * ==================================================================== */

function porkbun_webhook_config()
{
    if (!function_exists('getregistrarconfigoptions')) {
        require_once ROOTDIR . '/includes/registrarfunctions.php';
    }

    $config = getregistrarconfigoptions('porkbun');

    return is_array($config) ? $config : array();
}

function porkbun_webhook_api(array $config)
{
    $key = isset($config['apiKey']) ? $config['apiKey'] : '';
    $secret = isset($config['secretKey']) ? $config['secretKey'] : '';

    $redact = array_values(array_filter(array($key, $secret)));

    return new PorkbunApi($key, $secret, function ($action, $request, $response) use ($redact) {
        logModuleCall('porkbun', $action, $request, $response, null, $redact);
    });
}

function porkbun_webhook_header($key)
{
    return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : '';
}

function porkbun_webhook_log($action, $request, $response)
{
    logModuleCall('porkbun', $action, $request, $response);
}

function porkbun_webhook_respond($code, $message)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message . "\n";
    exit;
}

/* ======================================================================
 * CLI: register and inspect the endpoint
 * ==================================================================== */

function porkbun_webhook_cli(array $args)
{
    $command = isset($args[0]) ? $args[0] : '--help';
    $config = porkbun_webhook_config();

    if (empty($config['apiKey']) || empty($config['secretKey'])) {
        fwrite(STDERR, "Porkbun API credentials are not configured on the registrar module.\n");
        return 1;
    }

    $api = porkbun_webhook_api($config);

    try {
        switch ($command) {
            case '--register':
                return porkbun_webhook_cliRegister($api, isset($args[1]) ? $args[1] : '');

            case '--list':
                return porkbun_webhook_cliList($api);

            case '--test':
                return porkbun_webhook_cliTest($api, isset($args[1]) ? $args[1] : '');

            case '--disable':
                if (!isset($args[1])) {
                    fwrite(STDERR, "Usage: php webhook.php --disable <id>\n");
                    return 1;
                }
                $api->webhookUpdate($args[1], array('status' => 'DISABLED'));
                echo "Endpoint {$args[1]} disabled.\n";
                return 0;
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Porkbun: ' . $e->getMessage() . "\n");
        return 1;
    }

    echo "Porkbun webhook setup\n\n"
        . "  php webhook.php --register <https url>   create or reuse an endpoint, print its secret\n"
        . "  php webhook.php --list                   show endpoints, subscriptions and health\n"
        . "  php webhook.php --test [id]              send a webhook.test event\n"
        . "  php webhook.php --disable <id>           pause deliveries to an endpoint\n\n"
        . "The URL must be https:// on port 443 and resolve to a public address.\n"
        . "Paste the printed secret into the registrar config as 'Webhook Signing Secret'.\n";

    return 0;
}

function porkbun_webhook_cliRegister(PorkbunApi $api, $url)
{
    $url = trim($url);
    if ($url === '') {
        fwrite(STDERR, "Usage: php webhook.php --register https://billing.example.com/modules/registrars/porkbun/webhook.php\n");
        return 1;
    }
    if (stripos($url, 'https://') !== 0) {
        fwrite(STDERR, "Porkbun only delivers to https:// URLs on port 443.\n");
        return 1;
    }

    // Re-registering the same URL would just add a second endpoint delivering
    // the same events to the same place, so reuse one if it is already there.
    $existing = null;
    $list = $api->webhookList();
    foreach (porkbun_webhook_endpoints($list) as $endpoint) {
        if (isset($endpoint['url']) && rtrim($endpoint['url'], '/') === rtrim($url, '/')) {
            $existing = $endpoint;
            break;
        }
    }

    if ($existing) {
        echo "Endpoint already registered (id {$existing['id']}).\n";
        if (isset($existing['status']) && $existing['status'] !== 'ACTIVE') {
            $api->webhookUpdate($existing['id'], array('status' => 'ACTIVE'));
            echo "Re-enabled it and reset the failure counter.\n";
        }
        $endpoint = $existing;
    } else {
        // Subscribe to everything: new Porkbun event types then arrive without
        // a re-registration, and the dispatcher logs what it does not handle.
        $response = $api->webhookCreate($url, array('*'));
        $endpoint = isset($response['endpoint']) ? $response['endpoint'] : array();
        echo "Created endpoint id {$endpoint['id']} for {$url}\n";
    }

    if (!empty($endpoint['secret'])) {
        echo "\nSigning secret:\n\n    {$endpoint['secret']}\n\n"
            . "Paste that into Configuration > System Settings > Domain Registrars > Porkbun,\n"
            . "in the 'Webhook Signing Secret' field, and save. Then run:\n\n"
            . "    php webhook.php --test\n";
    }

    return 0;
}

function porkbun_webhook_cliList(PorkbunApi $api)
{
    $endpoints = porkbun_webhook_endpoints($api->webhookList());

    if (!$endpoints) {
        echo "No webhook endpoints registered on this account.\n";
        return 0;
    }

    foreach ($endpoints as $endpoint) {
        $events = isset($endpoint['events']) && is_array($endpoint['events'])
            ? implode(', ', $endpoint['events'])
            : '*';
        echo 'id ' . (isset($endpoint['id']) ? $endpoint['id'] : '?')
            . '  ' . (isset($endpoint['status']) ? $endpoint['status'] : '?')
            . '  ' . (isset($endpoint['url']) ? $endpoint['url'] : '?')
            . "\n    events: " . $events . "\n";
    }

    return 0;
}

function porkbun_webhook_cliTest(PorkbunApi $api, $id)
{
    if ($id === '') {
        $endpoints = porkbun_webhook_endpoints($api->webhookList());
        if (count($endpoints) !== 1) {
            fwrite(STDERR, "Several endpoints exist; name one: php webhook.php --test <id>\n");
            return 1;
        }
        $id = $endpoints[0]['id'];
    }

    $api->webhookTest($id);
    echo "Test event queued for endpoint {$id}. It usually arrives within a minute.\n"
        . "Check Configuration > System Logs > Module Log for 'Webhook Test'.\n";

    return 0;
}

function porkbun_webhook_endpoints($response)
{
    return isset($response['endpoints']) && is_array($response['endpoints'])
        ? array_values($response['endpoints'])
        : array();
}
