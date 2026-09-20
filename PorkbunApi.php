<?php
/**
 * Porkbun API client for the WHMCS registrar module.
 *
 * Thin wrapper around the Porkbun v3 REST API (v3.31).
 * Base URL: https://api.porkbun.com/api/json/v3
 * Auth: apikey / secretapikey in the JSON body (works with live pk1_ keys
 *       and sandbox pk1_sb_ keys against the same host).
 *
 * Docs: https://porkbun.com/api/json/v3/documentation
 */

namespace WHMCS\Module\Registrar\Porkbun;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * A Porkbun "status": "ERROR" response, keeping the machine-readable parts of
 * it that callers need to branch on. Porkbun documents stable error codes and
 * a next_action remediation hint, both of which are lost if all you keep is
 * the message.
 */
class PorkbunApiException extends \Exception
{
    /** @var array */
    protected $response;

    public function __construct($message, array $response = array())
    {
        parent::__construct($message);
        $this->response = $response;
    }

    /**
     * Porkbun's stable error code, e.g. DOMAIN_NOT_FOUND. Named to avoid
     * colliding with \Exception::getCode(), which is an int.
     *
     * @return string
     */
    public function getPorkbunCode()
    {
        return isset($this->response['code']) ? (string) $this->response['code'] : '';
    }

    /**
     * next_action.type, e.g. add_funds or verify_account. Empty when absent.
     *
     * @return string
     */
    public function getNextActionType()
    {
        return isset($this->response['next_action']['type'])
            ? (string) $this->response['next_action']['type']
            : '';
    }

    /**
     * The whole decoded error body, for the fields only some errors carry
     * (suggestedAddress, conflictingRecords, ttlRemaining, ...).
     *
     * @return array
     */
    public function getResponse()
    {
        return $this->response;
    }
}

class PorkbunApi
{
    /** Single host for both live and sandbox; sandbox is selected by the pk1_sb_ key. */
    const ENDPOINT = 'https://api.porkbun.com/api/json/v3';

    /** Domains per bulk availability check. More is refused, not truncated. */
    const CHECK_BATCH_SIZE = 25;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $secretKey;

    /** @var callable|null function(string $action, mixed $request, mixed $response): void */
    protected $logger;

    public function __construct($apiKey, $secretKey, $logger = null)
    {
        $this->apiKey = trim((string) $apiKey);
        $this->secretKey = trim((string) $secretKey);
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /**
     * POST to a Porkbun endpoint with JSON body auth.
     *
     * v3.31 documents some read endpoints as GET-only, but the live API still
     * accepts POST on all of them, so we keep a single request path.
     *
     * @param string $path           Path after the version segment, e.g. "domain/create/example.com".
     * @param array  $payload        Extra body fields (credentials are added automatically).
     * @param string $action         Human label used for the WHMCS module log.
     * @param string $idempotencyKey Optional. On a billable write, makes a retry
     *                               within 24h replay the original response
     *                               instead of charging the account twice.
     *
     * @return array Decoded JSON response.
     * @throws PorkbunApiException on API status ERROR.
     * @throws \Exception on transport failure or a malformed response.
     */
    public function call($path, array $payload = [], $action = null, $idempotencyKey = null)
    {
        $url = self::ENDPOINT . '/' . ltrim($path, '/');
        $action = $action ?: $path;

        $body = array_merge(
            array(
                'apikey' => $this->apiKey,
                'secretapikey' => $this->secretKey,
            ),
            $payload
        );

        $json = json_encode($body);

        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
        );
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WHMCS-Porkbun-Registrar/1.0',
        ));

        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Redact secrets before anything is logged.
        $loggedRequest = $body;
        $loggedRequest['apikey'] = '[redacted]';
        $loggedRequest['secretapikey'] = '[redacted]';

        if ($raw === false) {
            $this->log($action, $loggedRequest, 'cURL error: ' . $curlErr);
            throw new \Exception('Could not reach Porkbun: ' . $curlErr);
        }

        $decoded = json_decode($raw, true);
        $this->log($action, $loggedRequest, ($decoded !== null ? $decoded : $raw));

        if (!is_array($decoded)) {
            throw new \Exception('Unexpected response from Porkbun (HTTP ' . $httpCode . ').');
        }

        if (isset($decoded['status']) && strtoupper($decoded['status']) === 'ERROR') {
            throw new PorkbunApiException(self::errorMessage($decoded, $httpCode), $decoded);
        }

        return $decoded;
    }

    /**
     * Build the message a WHMCS admin sees. Porkbun's next_action.hint says how
     * to fix the problem ("add funds", "verify the account", ...), which is the
     * most useful half of the response, and the requestId is what Porkbun
     * support asks for.
     *
     * @param array $decoded
     * @param int   $httpCode
     * @return string
     */
    protected static function errorMessage(array $decoded, $httpCode)
    {
        $message = isset($decoded['message']) && $decoded['message'] !== ''
            ? $decoded['message']
            : 'Porkbun returned an error (HTTP ' . $httpCode . ').';

        if (isset($decoded['next_action']['hint']) && $decoded['next_action']['hint'] !== '') {
            $message = rtrim($message, '. ') . '. ' . $decoded['next_action']['hint'];
        }

        $tags = array();
        if (isset($decoded['code']) && $decoded['code'] !== '') {
            $tags[] = (string) $decoded['code'];
        }
        if (isset($decoded['requestId']) && $decoded['requestId'] !== '') {
            $tags[] = 'request ' . $decoded['requestId'];
        }
        if ($tags) {
            $message = rtrim($message, '. ') . '. [' . implode(' · ', $tags) . ']';
        }

        return $message;
    }

    protected function log($action, $request, $response)
    {
        if ($this->logger) {
            call_user_func($this->logger, $action, $request, $response);
        }
    }

    /* --------------------------------------------------------------------
     * Endpoint wrappers
     * ------------------------------------------------------------------ */

    public function ping()
    {
        return $this->call('ping', array(), 'Ping');
    }

    public function checkDomain($domain)
    {
        return $this->call('domain/checkDomain/' . rawurlencode($domain), array(), 'Check Domain');
    }

    /**
     * Availability and pricing for up to 25 domains in one call.
     *
     * This has its own budget - 200 domains per 60 seconds, counted per
     * domain rather than per request - against 10 checks per 10 seconds for
     * the single-domain form, so a lookup across a dozen TLDs belongs here.
     *
     * The response splits three ways and the caller has to read all of them:
     * `domains` answered, `invalid` not checkable, and `unresolved` where the
     * registry did not reply in time. Unresolved is not a "no".
     *
     * @param array $domains
     * @return array
     */
    public function checkDomains(array $domains)
    {
        return $this->call(
            'domain/checkDomain',
            array('domains' => array_values($domains)),
            'Check Domains (bulk)'
        );
    }

    /**
     * The public TLD price list. Needs no credentials, which is why a pricing
     * sync keeps working after a key is rotated.
     *
     * @param array $tlds Optional. Restrict the answer to these extensions.
     * @return array
     */
    public function pricingGet(array $tlds = array())
    {
        $payload = $tlds ? array('tlds' => array_values($tlds)) : array();

        return $this->call('pricing/get', $payload, 'Get Pricing');
    }

    public function createDomain($domain, array $extra, $idempotencyKey = null)
    {
        return $this->call('domain/create/' . rawurlencode($domain), $extra, 'Register Domain', $idempotencyKey);
    }

    public function renewDomain($domain, $costPennies, $idempotencyKey = null)
    {
        return $this->call(
            'domain/renew/' . rawurlencode($domain),
            array('cost' => (int) $costPennies),
            'Renew Domain',
            $idempotencyKey
        );
    }

    public function transferDomain($domain, $authCode, $costPennies, $idempotencyKey = null)
    {
        return $this->call(
            'domain/transfer/' . rawurlencode($domain),
            array('authCode' => (string) $authCode, 'cost' => (int) $costPennies),
            'Transfer Domain',
            $idempotencyKey
        );
    }

    public function getTransfer($domain)
    {
        return $this->call('domain/getTransfer/' . rawurlencode($domain), array(), 'Get Transfer');
    }

    public function getDomain($domain)
    {
        return $this->call('domain/get/' . rawurlencode($domain), array(), 'Get Domain');
    }

    public function updateAutoRenew($domain, $on)
    {
        return $this->call(
            'domain/updateAutoRenew/' . rawurlencode($domain),
            array('status' => $on ? 'on' : 'off'),
            'Update Auto Renew'
        );
    }

    public function getNs($domain)
    {
        return $this->call('domain/getNs/' . rawurlencode($domain), array(), 'Get Nameservers');
    }

    public function updateNs($domain, array $ns)
    {
        return $this->call('domain/updateNs/' . rawurlencode($domain), array('ns' => array_values($ns)), 'Update Nameservers');
    }

    public function dnsRetrieve($domain)
    {
        return $this->call('dns/retrieve/' . rawurlencode($domain), array(), 'Retrieve DNS');
    }

    public function dnsCreate($domain, array $record)
    {
        return $this->call('dns/create/' . rawurlencode($domain), $record, 'Create DNS Record');
    }

    public function dnsEdit($domain, $id, array $record)
    {
        return $this->call('dns/edit/' . rawurlencode($domain) . '/' . rawurlencode($id), $record, 'Edit DNS Record');
    }

    public function dnsDelete($domain, $id)
    {
        return $this->call('dns/delete/' . rawurlencode($domain) . '/' . rawurlencode($id), array(), 'Delete DNS Record');
    }

    /* ---- Webhooks ---- */

    public function webhookList()
    {
        return $this->call('webhook/list', array(), 'List Webhooks');
    }

    public function webhookCreate($url, array $events = array('*'))
    {
        return $this->call(
            'webhook/create',
            array('url' => (string) $url, 'events' => array_values($events)),
            'Create Webhook'
        );
    }

    public function webhookUpdate($id, array $fields)
    {
        return $this->call('webhook/update', array_merge(array('id' => $id), $fields), 'Update Webhook');
    }

    public function webhookDelete($id)
    {
        return $this->call('webhook/delete', array('id' => $id), 'Delete Webhook');
    }

    public function webhookTest($id)
    {
        return $this->call('webhook/test', array('id' => $id), 'Test Webhook');
    }

    public function getContacts($domain)
    {
        return $this->call('domain/getContacts/' . rawurlencode($domain), array(), 'Get Contacts');
    }

    public function updateContacts($domain, array $payload)
    {
        return $this->call('domain/updateContacts/' . rawurlencode($domain), $payload, 'Update Contacts');
    }
}
