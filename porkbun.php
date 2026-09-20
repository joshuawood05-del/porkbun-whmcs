<?php
/**
 * Porkbun Registrar Module for WHMCS
 * ----------------------------------
 * Registers and manages domains through the Porkbun v3 API (v3.31).
 *
 * Supported: register, renew, transfer-in, nameservers, DNS host records,
 *            domain contacts, expiry/status sync, transfer sync,
 *            live availability lookups, and TLD pricing - imported as cost
 *            for WHMCS' own wizard, or pushed to retail on a schedule with
 *            the module's markup rules applied.
 *
 * Not supported by the Porkbun API (handled gracefully, not silently):
 *   - Retrieving the EPP/auth code (must be pulled from the Porkbun dashboard)
 *   - Registrar lock toggle
 *   - Multi-year terms via API (registrations/renewals are 1 year each)
 *   - .uk inbound transfers via API
 *   - Premium domains: they cannot be registered, renewed or transferred
 *     through the API, so availability marks them unsellable rather than
 *     quoting a price nothing can fulfil
 *
 * IMPORTANT operational notes (see README.md for the full list):
 *   - The Porkbun account must have email + phone verified and account credit.
 *   - The API cannot place your account's *first ever* registration; do one
 *     registration manually in the dashboard before relying on automation.
 *   - Domains must be opted in to "API Access" in Porkbun for renew/manage to
 *     work. Domains registered/transferred via the API are opted in already.
 *
 * @package    WHMCS\Module\Registrar\Porkbun
 * @author     Built for Gander Web LTD
 * @license    MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/PorkbunApi.php';
require_once __DIR__ . '/lib/PorkbunPricing.php';
require_once __DIR__ . '/lib/PorkbunPricingSync.php';

use WHMCS\Module\Registrar\Porkbun\PorkbunApi;
use WHMCS\Module\Registrar\Porkbun\PorkbunApiException;
use WHMCS\Module\Registrar\Porkbun\PorkbunPricing;
use WHMCS\Module\Registrar\Porkbun\PorkbunPricingSync;
use WHMCS\Module\Registrar\Porkbun\PorkbunPricingException;
use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Results\ResultsList;
// WHMCS has two unrelated ResultsList classes; the lookup one is aliased so
// both can be used in the same file.
use WHMCS\Domains\DomainLookup\ResultsList as DomainLookupResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;

/* ======================================================================
 * Module metadata & configuration
 * ==================================================================== */

/**
 * Module metadata shown in the WHMCS admin area.
 *
 * @return array
 */
function porkbun_MetaData()
{
    return array(
        'DisplayName' => 'Porkbun',
        'APIVersion' => '1.1',
    );
}

/**
 * Settings shown under Configuration > System Settings > Domain Registrars.
 *
 * @return array
 */
function porkbun_getConfigArray()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Porkbun Registrar',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Your Porkbun API key (starts with pk1_). Create one at porkbun.com/account/api. Use a pk1_sb_ key to run against the sandbox.',
        ),
        'secretKey' => array(
            'FriendlyName' => 'Secret API Key',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Your Porkbun secret API key (starts with sk1_).',
        ),
        'defaultNs1' => array(
            'FriendlyName' => 'Default Nameserver 1',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'curitiba.ns.porkbun.com',
            'Description' => 'Applied to a newly registered domain when the customer does not supply their own nameservers. Left as Porkbun nameservers, DNS/A records are managed in the WHMCS DNS tab. Replace all four with your own nameservers if you host DNS elsewhere.',
        ),
        'defaultNs2' => array(
            'FriendlyName' => 'Default Nameserver 2',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'fortaleza.ns.porkbun.com',
            'Description' => '',
        ),
        'defaultNs3' => array(
            'FriendlyName' => 'Default Nameserver 3',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'maceio.ns.porkbun.com',
            'Description' => 'Optional.',
        ),
        'defaultNs4' => array(
            'FriendlyName' => 'Default Nameserver 4',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'salvador.ns.porkbun.com',
            'Description' => 'Optional.',
        ),
        'setContacts' => array(
            'FriendlyName' => 'Push customer to registrant',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'After a successful registration, copy the WHMCS customer details into the domain contacts. Turn off to keep your Porkbun account contact as the registrant.',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Webhook Signing Secret',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Optional. Paste the signing secret printed by <em>php modules/registrars/porkbun/webhook.php --register https://your-whmcs/modules/registrars/porkbun/webhook.php</em>. '
                . 'With this set, Porkbun pushes transfer completions and renewals to WHMCS within about a minute instead of waiting for the daily domain sync cron. Leave empty to disable the receiver.',
        ),
        'dnsFullSync' => array(
            'FriendlyName' => 'Full DNS sync (allow deletes)',
            'Type' => 'yesno',
            'Default' => '',
            'Description' => 'When saving DNS from WHMCS, delete Porkbun records that are absent from the WHMCS list. Leave OFF to only add/update records and never delete (safer).',
        ),

        /* ---- Pricing ---------------------------------------------------
         * Porkbun has no reseller tier, so its prices are your cost. These
         * settings turn that cost into what the customer pays.
         */

        'markupPercent' => array(
            'FriendlyName' => 'Markup %',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '25',
            'Description' => 'Added to Porkbun\'s cost price. 25 turns a $11.08 .com into $13.85. Applies to registration, renewal and transfer unless a rule below says otherwise.',
        ),
        'markupFixed' => array(
            'FriendlyName' => 'Fixed uplift',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '0',
            'Description' => 'Optional flat amount added after the percentage, in the pricing currency below.',
        ),
        'markupMinMargin' => array(
            'FriendlyName' => 'Minimum margin',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '0',
            'Description' => 'Optional floor on the margin per year. Worth setting: a percentage of a $2.04 first-year promo leaves pennies, which will not cover the first support ticket. 0 means no floor.',
        ),
        'markupRounding' => array(
            'FriendlyName' => 'Round prices to',
            'Type' => 'dropdown',
            'Options' => array(
                'none' => 'Exact (2 decimal places)',
                'up' => 'Next whole number',
                '.95' => 'Next price ending .95',
                '.99' => 'Next price ending .99',
            ),
            'Default' => 'none',
            'Description' => 'Rounding is always upwards, so it never eats into the margin.',
        ),
        'markupOverrides' => array(
            'FriendlyName' => 'Per-TLD markup rules',
            'Type' => 'textarea',
            'Rows' => '6',
            'Cols' => '60',
            'Default' => '',
            'Description' => 'Optional exceptions, one per line, most specific wins. '
                . '<code>.com 40%</code> &middot; '
                . '<code>.co.uk 60% +0.50</code> &middot; '
                . '<code>.io:renew 20% min 8.00</code> &middot; '
                . '<code>.dev flat 14.99</code> (ignores cost) &middot; '
                . '<code>.xyz:transfer skip</code> (leave that price alone) &middot; '
                . '<code>*:transfer 15%</code> (every TLD, transfers only). '
                . 'Operations are register, renew and transfer. Add <code>round .99</code> to a line to override the rounding. '
                . '# starts a comment. A line that cannot be parsed blocks the save.',
        ),
        'pricingCurrency' => array(
            'FriendlyName' => 'Pricing currency',
            'Type' => 'text',
            'Size' => '8',
            'Default' => 'USD',
            'Description' => 'The WHMCS currency to write prices in. Leave as USD to hand Porkbun\'s own figures to WHMCS and let it convert - that needs USD to exist under Configuration &rarr; System Settings &rarr; Currencies. Set your own currency (e.g. GBP) to have the module convert first.',
        ),
        'pricingFxRate' => array(
            'FriendlyName' => 'USD conversion rate',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '',
            'Description' => 'Only read when the pricing currency is not USD. Blank uses the exchange rates already in WHMCS. Set a number (e.g. 0.79 for USD&rarr;GBP) if USD is not one of your configured currencies.',
        ),
        'pricingScope' => array(
            'FriendlyName' => 'Which TLDs to price',
            'Type' => 'dropdown',
            'Options' => array(
                'existing' => 'Only TLDs already assigned to Porkbun in WHMCS',
                'list' => 'Only the TLDs listed below',
                'all' => 'Every TLD Porkbun sells (about 640)',
            ),
            'Default' => 'existing',
            'Description' => 'A TLD already pointed at a different registrar is never taken over, whichever option is chosen.',
        ),
        'pricingTlds' => array(
            'FriendlyName' => 'TLD list',
            'Type' => 'textarea',
            'Rows' => '4',
            'Cols' => '60',
            'Default' => '',
            'Description' => 'Used when "Only the TLDs listed below" is selected. Separate with spaces, commas or newlines: <code>.com .net .co.uk .dev</code>',
        ),
        'pricingYears' => array(
            'FriendlyName' => 'Registration terms',
            'Type' => 'dropdown',
            'Options' => array(
                '1' => '1 year only (matches what the API can deliver)',
                'keep' => 'Price year 1 and leave other terms as they are',
            ),
            'Default' => '1',
            'Description' => 'The Porkbun API registers and renews the registry minimum - one year - per call, so a customer who buys three years gets one. The first option switches years 2-10 off in WHMCS so that cannot be sold.',
        ),
        'includeHandshake' => array(
            'FriendlyName' => 'Include Handshake TLDs',
            'Type' => 'yesno',
            'Default' => '',
            'Description' => 'Around 270 of Porkbun\'s ~910 extensions are Handshake blockchain names, which resolve only behind a Handshake resolver. They are left out of pricing unless you tick this.',
        ),
        'pricingSyncEnabled' => array(
            'FriendlyName' => 'Sync retail pricing daily',
            'Type' => 'yesno',
            'Default' => '',
            'Description' => 'Apply the markup above to Porkbun\'s current prices and write the result into Domain Pricing on the daily cron. Preview it first with <em>php modules/registrars/porkbun/pricing.php</em>, which changes nothing until you add --apply.',
        ),
    );
}

/**
 * Validates the API credentials when the configuration is saved.
 * Throwing here surfaces the message in the admin UI and blocks the save.
 *
 * @param array $params
 * @throws Exception
 */
function porkbun_config_validate($params)
{
    if (empty($params['apiKey']) || empty($params['secretKey'])) {
        throw new Exception('Please enter both the API Key and Secret API Key.');
    }

    $api = porkbun_api($params);
    $response = $api->ping();

    // ping() answers unauthenticated callers too, so SUCCESS alone proves
    // nothing; credentialsValid is what says the key and secret were accepted.
    if (empty($response['credentialsValid'])) {
        throw new Exception('Porkbun did not accept these API credentials. Double-check the key/secret and that API access is enabled on your account.');
    }

    // A markup rule that cannot be parsed is caught here rather than at 3am
    // by the cron, when the only evidence is a log line.
    try {
        PorkbunPricing::parseRules(isset($params['markupOverrides']) ? $params['markupOverrides'] : '');

        // The markup and the currency only have to make sense once something
        // is going to act on them. An install that never syncs pricing should
        // not be blocked from saving its API key over an empty markup field.
        if (porkbun_bool($params, 'pricingSyncEnabled')) {
            PorkbunPricing::baseRule($params);
            $sync = new PorkbunPricingSync($params, $api);
            $sync->resolveCurrency();
        }
    } catch (PorkbunPricingException $e) {
        throw new Exception($e->getMessage());
    }
}

/* ======================================================================
 * Registration lifecycle
 * ==================================================================== */

/**
 * Register a new domain.
 *
 * @param array $params
 * @return array
 */
function porkbun_RegisterDomain($params)
{
    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        // Checked before the price because both read the same cached lookup,
        // and because a premium domain has no price we can act on.
        if (porkbun_isPremium($api, $domain)) {
            return porkbun_premiumRefusal($domain, 'register');
        }

        $price = porkbun_price($api, $domain, 'registration');
        if ($price === null) {
            return array('error' => 'Could not determine the registration price from Porkbun.');
        }

        $payload = array(
            'cost' => porkbun_toPennies($price),
            'agreeToTerms' => 'yes',
        );

        // Map WHMCS ID Protection to Porkbun WHOIS privacy when the option is present.
        if (array_key_exists('idprotection', $params)) {
            $payload['whoisPrivacy'] = (bool) $params['idprotection'];
        }

        // A retry within 24h replays the original response rather than
        // registering (and charging) twice.
        $api->createDomain($domain, $payload, porkbun_idempotencyKey($params, 'register', $domain));

        // Multi-year orders: the API only registers the registry-minimum term (usually 1 year).
        if (!empty($params['regperiod']) && (int) $params['regperiod'] > 1) {
            porkbun_note('Register Term', $domain,
                'WHMCS ordered ' . (int) $params['regperiod'] . ' years but the Porkbun API registers 1 year only. '
                . 'Renew the extra years manually or via the API after 30 days.');
        }

        // Apply custom nameservers if the customer supplied them.
        porkbun_applyNameservers($api, $domain, $params);

        // Optionally push the customer's details onto the domain contacts.
        if (porkbun_bool($params, 'setContacts', true)) {
            porkbun_trySetContacts($api, $domain, porkbun_contactFromParams($params));
        }

        return array('success' => true);
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Initiate an inbound transfer.
 *
 * @param array $params
 * @return array
 */
function porkbun_TransferDomain($params)
{
    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        $authCode = isset($params['eppcode']) ? trim($params['eppcode']) : '';
        if ($authCode === '') {
            return array('error' => 'An EPP / authorization code is required to transfer this domain in.');
        }

        if (porkbun_isPremium($api, $domain)) {
            return porkbun_premiumRefusal($domain, 'transfer');
        }

        $price = porkbun_price($api, $domain, 'transfer');
        if ($price === null) {
            return array('error' => 'Could not determine the transfer price from Porkbun.');
        }

        $api->transferDomain(
            $domain,
            $authCode,
            porkbun_toPennies($price),
            porkbun_idempotencyKey($params, 'transfer', $domain)
        );

        return array('success' => true);
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Renew a domain.
 *
 * @param array $params
 * @return array
 */
function porkbun_RenewDomain($params)
{
    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        $price = porkbun_price($api, $domain, 'renewal');
        if ($price === null) {
            return array('error' => 'Could not determine the renewal price from Porkbun.');
        }

        $idempotencyKey = porkbun_idempotencyKey($params, 'renew', $domain);

        try {
            $api->renewDomain($domain, porkbun_toPennies($price), $idempotencyKey);
        } catch (\Throwable $e) {
            // Renewals run in bulk from cron, so there is no premium check up
            // front the way there is on register and transfer - it would cost
            // a rate-limited lookup per domain to catch a case Porkbun already
            // refuses by name. What this does catch is the other reason a list
            // price can be wrong: the TLD list does not cover premium domains,
            // which carry their own renewal price, and Porkbun rejects a
            // mismatched cost. Spend the per-domain lookup only when we must.
            $exact = porkbun_checkDomainPrice($api, $domain, 'renewal');
            if ($exact === null || porkbun_toPennies($exact) === porkbun_toPennies($price)) {
                throw $e;
            }
            // A different cost is a different body, so the first key cannot
            // be reused (Porkbun answers IDEMPOTENCY_KEY_MISMATCH).
            $api->renewDomain($domain, porkbun_toPennies($exact), $idempotencyKey . '-reprice');
        }

        if (!empty($params['regperiod']) && (int) $params['regperiod'] > 1) {
            porkbun_note('Renew Term', $domain,
                'WHMCS requested ' . (int) $params['regperiod'] . ' years but the Porkbun API renews 1 year per call.');
        }

        return array('success' => true);
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }
}

/* ======================================================================
 * Nameservers
 * ==================================================================== */

/**
 * @param array $params
 * @return array
 */
function porkbun_GetNameservers($params)
{
    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        $response = $api->getNs($domain);
        $servers = isset($response['ns']) && is_array($response['ns']) ? $response['ns'] : array();

        if (empty($servers)) {
            return array('error' => 'Porkbun returned no nameservers for this domain.');
        }

        $result = array();
        $i = 1;
        foreach ($servers as $server) {
            $result['ns' . $i] = $server;
            $i++;
            if ($i > 5) {
                break;
            }
        }

        return $result;
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * @param array $params
 * @return array
 */
function porkbun_SaveNameservers($params)
{
    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        $servers = array();
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($params['ns' . $i])) {
                $servers[] = trim($params['ns' . $i]);
            }
        }

        if (count($servers) < 2) {
            return array('error' => 'Please provide at least two nameservers.');
        }

        $api->updateNs($domain, $servers);

        return array('success' => true);
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }
}

/* ======================================================================
 * DNS host records
 * ==================================================================== */

/**
 * @param array $params
 * @return array
 */
function porkbun_GetDNS($params)
{
    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        $response = $api->dnsRetrieve($domain);
        $records = isset($response['records']) && is_array($response['records']) ? $response['records'] : array();

        $hostRecords = array();
        foreach ($records as $record) {
            $type = isset($record['type']) ? strtoupper($record['type']) : '';
            $name = isset($record['name']) ? $record['name'] : $domain;

            $priority = 'N/A';
            if (isset($record['prio']) && $record['prio'] !== '' && $record['prio'] !== null) {
                $priority = $record['prio'];
            }

            $hostRecords[] = array(
                'hostname' => $name, // Full record name as Porkbun stores it
                'type' => $type,
                'address' => isset($record['content']) ? $record['content'] : '',
                'priority' => $priority,
                'recordid' => isset($record['id']) ? (string) $record['id'] : '',
            );
        }

        return $hostRecords;
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * @param array $params
 * @return array
 */
function porkbun_SaveDNS($params)
{
    $applied = 0;

    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        $incoming = isset($params['dnsrecords']) && is_array($params['dnsrecords']) ? $params['dnsrecords'] : array();

        // Snapshot current records so we can edit-by-id, create, and (optionally) prune.
        $currentResponse = $api->dnsRetrieve($domain);
        $current = isset($currentResponse['records']) && is_array($currentResponse['records']) ? $currentResponse['records'] : array();
        $currentById = array();
        foreach ($current as $record) {
            if (isset($record['id'])) {
                $currentById[(string) $record['id']] = $record;
            }
        }

        $keptIds = array();

        foreach ($incoming as $record) {
            $type = isset($record['type']) ? strtoupper(trim($record['type'])) : '';
            $address = isset($record['address']) ? trim($record['address']) : '';

            // Skip empty rows WHMCS may submit.
            if ($type === '' || $address === '') {
                continue;
            }

            $name = porkbun_relativeName(isset($record['hostname']) ? $record['hostname'] : '', $domain);

            $priority = null;
            if (isset($record['priority']) && $record['priority'] !== '' && strtoupper($record['priority']) !== 'N/A') {
                $priority = (int) $record['priority'];
            }

            $payload = array(
                'name' => $name,
                'type' => $type,
                'content' => $address,
            );

            $recordId = isset($record['recordid']) ? (string) $record['recordid'] : '';
            $existing = ($recordId !== '' && isset($currentById[$recordId])) ? $currentById[$recordId] : null;

            // WHMCS' DNS tab carries neither a TTL nor the priority of the
            // types it shows as N/A, so take both from the record being edited
            // rather than letting Porkbun reset them to its defaults.
            if ($existing !== null) {
                if (isset($existing['ttl']) && $existing['ttl'] !== '') {
                    $payload['ttl'] = (int) $existing['ttl'];
                }
                if ($priority === null && isset($existing['prio']) && $existing['prio'] !== '' && $existing['prio'] !== null) {
                    $priority = (int) $existing['prio'];
                }
            }

            // Send a priority only for the record types that have one.
            if ($priority !== null) {
                $payload['prio'] = $priority;
            }

            if ($existing !== null) {
                $api->dnsEdit($domain, $recordId, $payload);
                $keptIds[$recordId] = true;
            } else {
                $created = $api->dnsCreate($domain, $payload);
                if (isset($created['id'])) {
                    $keptIds[(string) $created['id']] = true;
                }
            }

            $applied++;
        }

        // Optional pruning of records not present in the WHMCS list.
        if (porkbun_bool($params, 'dnsFullSync', false)) {
            foreach ($currentById as $id => $record) {
                if (empty($keptIds[$id])) {
                    $api->dnsDelete($domain, $id);
                }
            }
        }

        return array('success' => true);
    } catch (\Throwable $e) {
        $message = $e->getMessage();
        if ($applied > 0) {
            // Records go one at a time, so say what already landed rather than
            // implying the save changed nothing.
            $message .= ' (' . $applied . ' record(s) had already been saved.)';
        }
        return array('error' => $message);
    }
}

/* ======================================================================
 * Contacts
 * ==================================================================== */

/**
 * @param array $params
 * @return array
 */
function porkbun_GetContactDetails($params)
{
    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        $response = $api->getContacts($domain);
        $contacts = isset($response['contacts']) && is_array($response['contacts']) ? $response['contacts'] : array();

        $roles = array(
            'registrant' => 'Registrant',
            'admin' => 'Admin',
            'tech' => 'Technical',
            'billing' => 'Billing',
        );

        $result = array();
        foreach ($roles as $key => $label) {
            $contact = isset($contacts[$key]) && is_array($contacts[$key]) ? $contacts[$key] : array();
            $result[$label] = porkbun_contactToWhmcs($contact);
        }

        return $result;
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * @param array $params
 * @return array
 */
function porkbun_SaveContactDetails($params)
{
    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        $details = isset($params['contactdetails']) && is_array($params['contactdetails']) ? $params['contactdetails'] : array();

        $roles = array(
            'Registrant' => 'registrant',
            'Admin' => 'admin',
            'Technical' => 'tech',
            'Billing' => 'billing',
        );

        $contacts = array();
        $missingCode = array();
        foreach ($roles as $label => $key) {
            if (!empty($details[$label]) && is_array($details[$label])) {
                $mapped = porkbun_whmcsToContact($details[$label]);
                if (!empty($mapped['phone']) && empty($mapped['phoneCountryCode'])) {
                    $missingCode[] = $label;
                }
                $contacts[$key] = $mapped;
            }
        }

        if (empty($contacts)) {
            return array('error' => 'No contact details were supplied.');
        }

        // Porkbun rejects the whole update with INVALID_INPUT if any role is
        // missing this, so name the offending contact instead.
        if ($missingCode) {
            return array('error' => 'Porkbun needs the international dialling code in its own field. '
                . 'Fill in Phone Country Code (digits only, e.g. 44 for the UK, 1 for the US) for: '
                . implode(', ', $missingCode) . '.');
        }

        porkbun_updateContactsWithValidation($api, $domain, array('contacts' => $contacts));

        return array('success' => true);
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }
}

/* ======================================================================
 * EPP / auth code (not exposed by the Porkbun API)
 * ==================================================================== */

/**
 * @param array $params
 * @return array
 */
function porkbun_GetEPPCode($params)
{
    return array(
        'error' => 'Porkbun does not expose auth/EPP codes over its API. '
            . 'Retrieve it from the Porkbun dashboard: Domain Management > select the domain > '
            . 'Get Authorization Code (Transfer Out). The code is regenerated each time you view it.',
    );
}

/* ======================================================================
 * Sync (expiry / status) and Transfer sync
 * ==================================================================== */

/**
 * Keep the WHMCS expiry date and status in step with Porkbun.
 *
 * @param array $params
 * @return array
 */
function porkbun_Sync($params)
{
    try {
        $api = porkbun_api($params);
        $domain = porkbun_domain($params);

        $response = $api->getDomain($domain);
        $data = isset($response['domain']) && is_array($response['domain']) ? $response['domain'] : array();

        if (empty($data)) {
            return array('error' => 'Domain not found in the Porkbun account.');
        }

        // Renewals and DNS writes fail on domains that are not opted in to
        // API Access at Porkbun. Say so now rather than at renewal time.
        if (isset($data['apiAccess']) && !$data['apiAccess']) {
            porkbun_note('Sync', $domain,
                'This domain is not opted in to API Access at Porkbun, so renewals and DNS changes will be refused until that is enabled.');
        }

        $status = isset($data['status']) ? strtoupper($data['status']) : '';
        $result = array(
            'active' => ($status === 'ACTIVE'),
            'expired' => ($status === 'EXPIRED'),
        );

        if (!empty($data['expireDate'])) {
            $timestamp = strtotime($data['expireDate']);
            if ($timestamp !== false) {
                $result['expirydate'] = date('Y-m-d', $timestamp);
            }
        }

        return $result;
    } catch (PorkbunApiException $e) {
        // DOMAIN_NOT_FOUND is Porkbun for "not in this account any more".
        // Everything else - credentials, rate limits, INVALID_DOMAIN (which
        // also covers a malformed name) - stays an error, so WHMCS never
        // retires a live domain over a blip.
        if ($e->getPorkbunCode() === 'DOMAIN_NOT_FOUND') {
            return array('transferredAway' => true);
        }
        return array('error' => $e->getMessage());
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }
}

/**
 * Poll the status of an inbound transfer.
 *
 * @param array $params
 * @return array
 */
function porkbun_TransferSync($params)
{
    $domain = porkbun_domain($params);

    try {
        $api = porkbun_api($params);

        $response = $api->getTransfer($domain);
        $transfer = isset($response['transfer']) && is_array($response['transfer']) ? $response['transfer'] : array();
        $status = isset($transfer['status']) ? strtolower((string) $transfer['status']) : '';

        // Porkbun's transfer states are NEW, INIT, PENDINGAUTH, PENDINGSUBMIT,
        // PENDINGTRANSFER, PENDINGDNS, DONE and CANCELED. Everything that is
        // not terminal leaves the order pending.
        $completedStates = array('done');
        $failedStates = array('canceled', 'cancelled');

        if (in_array($status, $completedStates, true)) {
            $result = array('completed' => true);
            try {
                $domainData = $api->getDomain($domain);
                if (!empty($domainData['domain']['expireDate'])) {
                    $timestamp = strtotime($domainData['domain']['expireDate']);
                    if ($timestamp !== false) {
                        $result['expirydate'] = date('Y-m-d', $timestamp);
                    }
                }
            } catch (\Throwable $inner) {
                // Non-fatal: report completion without an expiry date.
            }
            return $result;
        }

        if (in_array($status, $failedStates, true)) {
            $reason = isset($transfer['statusDescription']) && $transfer['statusDescription'] !== ''
                ? $transfer['statusDescription']
                : ('Transfer ' . strtoupper($status));
            return array('failed' => true, 'reason' => $reason);
        }

        // Still in progress: return nothing so WHMCS leaves the order pending.
        return array();
    } catch (\Throwable $e) {
        // "No transfer record yet" is normal early on, so stay pending rather
        // than failing the order - but leave a trail, because a revoked key
        // looks exactly the same from here.
        porkbun_note('Transfer Sync', $domain, 'Could not read transfer status: ' . $e->getMessage());
        return array();
    }
}

/* ======================================================================
 * Availability lookups
 * ==================================================================== */

/**
 * Live availability, straight from the registries Porkbun talks to.
 *
 * Select Porkbun under Configuration > System Settings > Domain Pricing >
 * Lookup Provider to have the domain search use this instead of WHMCS' WHOIS
 * lookups. Prices the customer sees still come from your WHMCS TLD pricing;
 * what this adds is a registry-accurate answer about what is actually free.
 *
 * @param array $params
 * @return DomainLookupResultsList|array
 */
function porkbun_CheckAvailability($params)
{
    $searchTerm = !empty($params['punyCodeSearchTerm'])
        ? $params['punyCodeSearchTerm']
        : (isset($params['searchTerm']) ? $params['searchTerm'] : '');
    $sld = strtolower(trim((string) $searchTerm, ". \t\n\r\0\x0B"));

    if ($sld === '') {
        return new DomainLookupResultsList();
    }

    $requested = isset($params['tldsToInclude']) ? (array) $params['tldsToInclude'] : array();

    $tlds = array();
    foreach ($requested as $tld) {
        $tld = PorkbunPricing::normaliseTld($tld);
        if ($tld !== '' && $tld !== '.') {
            $tlds[$sld . $tld] = $tld;
        }
    }

    if (!$tlds) {
        return new DomainLookupResultsList();
    }

    try {
        $api = porkbun_api($params);
        $answers = porkbun_checkMany($api, array_keys($tlds));
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }

    $results = new DomainLookupResultsList();

    foreach ($tlds as $domain => $tld) {
        // A domain the registry never answered for is neither free nor taken.
        // Leaving it out of the results is the only honest option; saying
        // "registered" would lose a sale and "available" would lose an order.
        if (!isset($answers[$domain])) {
            continue;
        }

        $answer = $answers[$domain];
        $result = new SearchResult($sld, ltrim($tld, '.'));

        if (isset($answer['unsupported'])) {
            $result->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
            $results->append($result);
            continue;
        }

        $available = isset($answer['avail']) && strtolower((string) $answer['avail']) === 'yes';
        $premium = isset($answer['premium']) && strtolower((string) $answer['premium']) === 'yes';

        if ($available && $premium) {
            // Porkbun's API refuses to register, renew or transfer premium
            // names. Offering one at a price would sell an order that cannot
            // be fulfilled, so it is shown as unavailable instead.
            $result->setStatus(SearchResult::STATUS_RESERVED);
        } elseif ($available) {
            $result->setStatus(SearchResult::STATUS_NOT_REGISTERED);
        } else {
            $result->setStatus(SearchResult::STATUS_REGISTERED);
        }

        $results->append($result);
    }

    return $results;
}

/* ======================================================================
 * Pricing
 * ==================================================================== */

/**
 * Feeds Porkbun's cost prices to WHMCS' "Import Pricing" wizard, which is
 * where you set the margin on the way in.
 *
 * These are cost prices by design - the wizard applies its own markup, and
 * returning retail here would mark it up twice. For retail prices written on
 * a schedule from this module's own markup rules instead, see pricing.php.
 *
 * @param array $params
 * @return ResultsList|array
 */
function porkbun_GetTldPricing($params)
{
    try {
        $api = porkbun_api($params);
        $costs = PorkbunPricing::costs($api, porkbun_bool($params, 'includeHandshake'));

        $sync = new PorkbunPricingSync($params, $api);
        list($currency, $rate) = $sync->resolveCurrency();
    } catch (\Throwable $e) {
        return array('error' => $e->getMessage());
    }

    $oneYearOnly = (!isset($params['pricingYears']) || $params['pricingYears'] !== 'keep');

    $results = new ResultsList();

    foreach ($costs as $extension => $prices) {
        $item = new ImportItem();
        $item->setExtension($extension)
            ->setCurrency($currency)
            ->setEppRequired(true) // porkbun_TransferDomain refuses without one
            ->setMinYears(1)
            // The API registers the registry minimum per call, so anything
            // beyond a year is a term WHMCS could sell and the module could
            // not deliver.
            ->setMaxYears($oneYearOnly ? 1 : 10)
            ->setRegisterPrice(round($prices['register'] * $rate, 2));

        if ($prices['renew'] !== null) {
            $item->setRenewPrice(round($prices['renew'] * $rate, 2));
        }
        // Porkbun quotes 0.00 for transfers it does not price, .uk among them,
        // which the API cannot transfer at all. Pass null rather than "free".
        if ($prices['transfer'] !== null && $prices['transfer'] > 0) {
            $item->setTransferPrice(round($prices['transfer'] * $rate, 2));
        }

        $results[] = $item;
    }

    return $results;
}

/* ======================================================================
 * Internal helpers
 * ==================================================================== */

/**
 * @param array $params
 * @return PorkbunApi
 */
function porkbun_api($params)
{
    $key = isset($params['apiKey']) ? $params['apiKey'] : '';
    $secret = isset($params['secretKey']) ? $params['secretKey'] : '';

    $redact = array();
    if ($key !== '') {
        $redact[] = $key;
    }
    if ($secret !== '') {
        $redact[] = $secret;
    }

    $logger = function ($action, $request, $response) use ($redact) {
        if (function_exists('logModuleCall')) {
            logModuleCall('porkbun', $action, $request, $response, null, $redact);
        }
    };

    return new PorkbunApi($key, $secret, $logger);
}

/**
 * Full domain name from WHMCS params.
 *
 * @param array $params
 * @return string
 */
function porkbun_domain($params)
{
    if (!empty($params['domainname'])) {
        return $params['domainname'];
    }
    return $params['sld'] . '.' . $params['tld'];
}

/**
 * Availability and pricing for a list of domains, keyed by domain name.
 *
 * Batched 25 at a time because the bulk endpoint has its own, much larger
 * budget - 200 domains a minute against 10 single checks per 10 seconds - and
 * because the registries themselves are queried in fewer commands that way.
 *
 * Domains the registry did not answer for are retried once and then left out
 * of the result entirely, since "no answer" is not "taken".
 *
 * @param PorkbunApi $api
 * @param array      $domains
 * @return array domain => Porkbun check response (or array('unsupported' => true))
 */
function porkbun_checkMany(PorkbunApi $api, array $domains)
{
    $answers = array();
    $wanted = array_values(array_unique(array_map('strtolower', $domains)));

    foreach (array_chunk($wanted, PorkbunApi::CHECK_BATCH_SIZE) as $batch) {
        porkbun_checkBatch($api, $batch, $answers);

        $pending = array_values(array_diff($batch, array_keys($answers)));
        if ($pending) {
            porkbun_checkBatch($api, $pending, $answers);
        }
    }

    $missing = array_values(array_diff($wanted, array_keys($answers)));
    if ($missing) {
        porkbun_note('Check Availability', implode(', ', $missing),
            'The registry did not answer for these within the retry, so they were left out of the results rather than shown as taken.');
    }

    return $answers;
}

/**
 * One bulk call, merged into $answers.
 *
 * @param PorkbunApi $api
 * @param array      $batch
 * @param array      $answers
 */
function porkbun_checkBatch(PorkbunApi $api, array $batch, array &$answers)
{
    try {
        $response = $api->checkDomains($batch);
    } catch (PorkbunApiException $e) {
        // A batch heavy in registries that accept one name per command (.de
        // among them) is refused rather than left to time out. Halving it is
        // the documented way through.
        if ($e->getPorkbunCode() === 'BULK_CHECK_TOO_SLOW' && count($batch) > 1) {
            $half = (int) ceil(count($batch) / 2);
            porkbun_checkBatch($api, array_slice($batch, 0, $half), $answers);
            porkbun_checkBatch($api, array_slice($batch, $half), $answers);
            return;
        }
        throw $e;
    }

    $domains = isset($response['domains']) && is_array($response['domains']) ? $response['domains'] : array();
    foreach ($domains as $domain => $answer) {
        if (is_array($answer) && $answer) {
            $answers[strtolower((string) $domain)] = $answer;
        }
    }

    $invalid = isset($response['invalid']) && is_array($response['invalid']) ? $response['invalid'] : array();
    foreach ($invalid as $entry) {
        $name = '';
        if (is_string($entry)) {
            $name = $entry;
        } elseif (is_array($entry)) {
            foreach (array('domain', 'name', 'value') as $key) {
                if (!empty($entry[$key]) && is_string($entry[$key])) {
                    $name = $entry[$key];
                    break;
                }
            }
        }
        if ($name !== '') {
            $answers[strtolower($name)] = array('unsupported' => true);
        }
    }
}

/**
 * One domain's check response, cached for the life of the request so that
 * asking for the price and asking whether it is premium costs a single call
 * against a rate limit of ten every ten seconds.
 *
 * @param PorkbunApi $api
 * @param string     $domain
 * @return array
 */
function porkbun_domainCheck(PorkbunApi $api, $domain)
{
    static $cache = array();

    $key = strtolower($domain);

    if (!array_key_exists($key, $cache)) {
        $check = $api->checkDomain($domain);
        $cache[$key] = isset($check['response']) && is_array($check['response']) ? $check['response'] : array();
    }

    return $cache[$key];
}

/**
 * Whether Porkbun considers this a premium name.
 *
 * It matters because the API will not register, renew or transfer one, so an
 * order for a premium domain fails at the registrar with the customer already
 * invoiced. Refusing early keeps that from happening.
 *
 * @param PorkbunApi $api
 * @param string     $domain
 * @return bool
 */
function porkbun_isPremium(PorkbunApi $api, $domain)
{
    $check = porkbun_domainCheck($api, $domain);

    return isset($check['premium']) && strtolower((string) $check['premium']) === 'yes';
}

/**
 * The error a customer-facing premium order should fail with.
 *
 * @param string $domain
 * @param string $operation register|renew|transfer, as it reads in a sentence
 * @return array
 */
function porkbun_premiumRefusal($domain, $operation)
{
    return array(
        'error' => $domain . ' is a premium domain, and the Porkbun API cannot ' . $operation
            . ' premium names. Handle it in the Porkbun dashboard, or cancel the order.',
    );
}

/**
 * Look up the current price (USD, as a string/number) for an operation.
 *
 * @param PorkbunApi $api
 * @param string     $domain
 * @param string     $type   registration|renewal|transfer
 * @return string|float|null
 */
function porkbun_price(PorkbunApi $api, $domain, $type)
{
    // Renewals run in bulk from cron and domain/checkDomain is the endpoint
    // Porkbun throttles hardest, so take renewal prices from the TLD price
    // list, which is one cached call for any number of domains.
    if ($type === 'renewal') {
        $listed = porkbun_tldPrice($api, $domain, 'renewal');
        if ($listed !== null) {
            return $listed;
        }
    }

    return porkbun_checkDomainPrice($api, $domain, $type);
}

/**
 * Per-domain price from domain/checkDomain. Authoritative, premium domains
 * included, but rate limited.
 *
 * @param PorkbunApi $api
 * @param string     $domain
 * @param string     $type   registration|renewal|transfer
 * @return string|float|null
 */
function porkbun_checkDomainPrice(PorkbunApi $api, $domain, $type)
{
    $response = porkbun_domainCheck($api, $domain);

    if ($type === 'registration') {
        return isset($response['price']) ? $response['price'] : null;
    }

    $additional = isset($response['additional']) && is_array($response['additional']) ? $response['additional'] : array();

    if ($type === 'renewal' && isset($additional['renewal']['price'])) {
        return $additional['renewal']['price'];
    }
    if ($type === 'transfer' && isset($additional['transfer']['price'])) {
        return $additional['transfer']['price'];
    }

    // Fall back to the primary price if the breakdown is missing.
    return isset($response['price']) ? $response['price'] : null;
}

/**
 * Price for a domain's TLD from pricing/get, fetched once per request.
 * Standard registry pricing only - premium domains are not in this list.
 *
 * @param PorkbunApi $api
 * @param string     $domain
 * @param string     $type   registration|renewal|transfer
 * @return string|float|null
 */
function porkbun_tldPrice(PorkbunApi $api, $domain, $type)
{
    static $pricing = null;

    if ($pricing === null) {
        try {
            $data = $api->pricingGet();
            $pricing = isset($data['pricing']) && is_array($data['pricing']) ? $data['pricing'] : array();
        } catch (\Throwable $e) {
            $pricing = array();
        }
    }

    // Longest matching suffix wins, so example.co.uk prices as co.uk, not uk.
    $labels = explode('.', strtolower(rtrim(trim($domain), '.')));
    for ($i = 1; $i < count($labels); $i++) {
        $suffix = implode('.', array_slice($labels, $i));
        if (isset($pricing[$suffix][$type]) && $pricing[$suffix][$type] !== '') {
            return $pricing[$suffix][$type];
        }
    }

    return null;
}

/**
 * Convert a USD price string/number to integer pennies (cents).
 *
 * @param string|float $dollars
 * @return int
 */
function porkbun_toPennies($dollars)
{
    return (int) round(((float) $dollars) * 100);
}

/**
 * Read a yes/no module setting as a boolean.
 *
 * @param array  $params
 * @param string $field
 * @param bool   $default
 * @return bool
 */
function porkbun_bool($params, $field, $default = false)
{
    if (!array_key_exists($field, $params)) {
        return $default;
    }
    $value = $params[$field];
    return ($value === 'on' || $value === '1' || $value === 1 || $value === true || $value === 'yes');
}

/**
 * Write an informational note to the module log without failing the operation.
 *
 * @param string $action
 * @param string $domain
 * @param string $message
 */
function porkbun_note($action, $domain, $message)
{
    if (function_exists('logModuleCall')) {
        logModuleCall('porkbun', $action, array('domain' => $domain), $message);
    }
}

/**
 * Build the Idempotency-Key for a billable write.
 *
 * Porkbun stores the response for 24 hours and replays it for an identical
 * body, so a WHMCS retry after a timeout cannot charge the account twice. The
 * date keeps next year's renewal of the same domain from replaying this one;
 * the flip side is that two deliberate renewals of one domain on one day
 * collapse into a single charge, which is the safer way round to be wrong.
 *
 * @param array  $params
 * @param string $operation register|renew|transfer
 * @param string $domain
 * @return string
 */
function porkbun_idempotencyKey($params, $operation, $domain)
{
    $id = !empty($params['domainid']) ? $params['domainid'] : $domain;
    $seed = implode('|', array($operation, $domain, $id, gmdate('Y-m-d')));

    return 'whmcs-' . $operation . '-' . substr(sha1($seed), 0, 32);
}

/**
 * Apply nameservers after registration: the customer's own if they supplied
 * them with the order, otherwise the module's configured default nameservers.
 *
 * @param PorkbunApi $api
 * @param string     $domain
 * @param array      $params
 */
function porkbun_applyNameservers(PorkbunApi $api, $domain, $params)
{
    $servers = porkbun_resolveNameservers($params);

    if (count($servers) >= 2) {
        try {
            $api->updateNs($domain, $servers);
        } catch (\Throwable $e) {
            porkbun_note('Set Nameservers', $domain, 'Could not set nameservers after registration: ' . $e->getMessage());
        }
    }
}

/**
 * Decide which nameservers a new registration should use.
 *   1) Nameservers submitted with the order (customer choice, or WHMCS'
 *      system default nameservers if configured) always win.
 *   2) Otherwise the module's configured default nameservers are used.
 *   3) If neither is available, an empty list is returned and the domain keeps
 *      whatever Porkbun assigns (its own nameservers, i.e. Porkbun-hosted DNS).
 *
 * @param array $params
 * @return array
 */
function porkbun_resolveNameservers($params)
{
    $ordered = array();
    for ($i = 1; $i <= 5; $i++) {
        if (!empty($params['ns' . $i])) {
            $ordered[] = trim($params['ns' . $i]);
        }
    }
    if (count($ordered) >= 2) {
        return $ordered;
    }

    $defaults = array();
    for ($i = 1; $i <= 4; $i++) {
        if (!empty($params['defaultNs' . $i])) {
            $defaults[] = trim($params['defaultNs' . $i]);
        }
    }
    return $defaults;
}

/**
 * Build a Porkbun contact array from WHMCS registration params.
 *
 * @param array $params
 * @return array
 */
function porkbun_contactFromParams($params)
{
    $phoneCc = '';
    if (isset($params['phonecc'])) {
        $phoneCc = preg_replace('/\D/', '', (string) $params['phonecc']);
    }

    // Porkbun wants the national number as digits only, with the calling code
    // in its own field.
    $phone = '';
    if (!empty($params['phonenumber'])) {
        $phone = preg_replace('/\D/', '', (string) $params['phonenumber']);
    } elseif (!empty($params['fullphonenumber'])) {
        list($fullCc, $phone) = porkbun_splitPhone($params['fullphonenumber']);
        if ($phoneCc === '' && $fullCc !== '') {
            $phoneCc = $fullCc;
        }
    }

    $contact = array(
        'firstName' => isset($params['firstname']) ? $params['firstname'] : '',
        'lastName' => isset($params['lastname']) ? $params['lastname'] : '',
        'organization' => isset($params['companyname']) ? $params['companyname'] : '',
        'email' => isset($params['email']) ? $params['email'] : '',
        'address1' => isset($params['address1']) ? $params['address1'] : '',
        'address2' => isset($params['address2']) ? $params['address2'] : '',
        'city' => isset($params['city']) ? $params['city'] : '',
        'state' => isset($params['state']) ? $params['state'] : '',
        'postalCode' => isset($params['postcode']) ? $params['postcode'] : '',
        'country' => isset($params['country']) ? $params['country'] : '',
        'phone' => $phone,
    );

    if ($phoneCc !== '') {
        $contact['phoneCountryCode'] = $phoneCc;
    }

    // Drop empty values so we don't overwrite good data with blanks.
    return array_filter($contact, function ($value) {
        return $value !== '' && $value !== null;
    });
}

/**
 * Push contacts to Porkbun after registration, best-effort (never fatal).
 *
 * @param PorkbunApi $api
 * @param string     $domain
 * @param array      $contact  A single Porkbun contact applied to all roles.
 */
function porkbun_trySetContacts(PorkbunApi $api, $domain, $contact)
{
    if (empty($contact)) {
        return;
    }
    try {
        porkbun_updateContactsWithValidation($api, $domain, array('contact' => $contact));
    } catch (\Throwable $e) {
        porkbun_note('Set Contacts', $domain,
            'Registration succeeded but pushing customer contacts failed (the domain keeps your account contact): ' . $e->getMessage());
    }
}

/**
 * Call updateContacts, retrying once past an address-validation prompt.
 *
 * @param PorkbunApi $api
 * @param string     $domain
 * @param array      $payload  contacts=>[...] or contact=>[...]
 * @throws \Throwable
 */
function porkbun_updateContactsWithValidation(PorkbunApi $api, $domain, $payload)
{
    try {
        $api->updateContacts($domain, $payload);
    } catch (PorkbunApiException $e) {
        if ($e->getPorkbunCode() !== 'ADDRESS_VALIDATION_REQUIRED') {
            throw $e;
        }

        // Address-validated TLDs (.de/.uk/.us/.ca/.eu/.au families) run the
        // registrant address through Google Address Validation. Porkbun
        // rejects use_as_entered when it actually has a correction to offer,
        // so take the suggestion whenever one came back.
        $response = $e->getResponse();
        $suggested = !empty($response['suggestedAddress']);

        $payload['addressValidationChoice'] = $suggested ? 'accept_suggestion' : 'use_as_entered';
        $api->updateContacts($domain, $payload);

        if ($suggested) {
            porkbun_note('Set Contacts', $domain,
                'The registry required a validated address, so Porkbun\'s standardised version of the address was saved instead of the one entered in WHMCS.');
        }
    }
}

/**
 * Map a Porkbun contact to the WHMCS field labels used by this module.
 *
 * @param array $contact
 * @return array
 */
function porkbun_contactToWhmcs($contact)
{
    $cc = isset($contact['phoneCountryCode']) ? ltrim(trim((string) $contact['phoneCountryCode']), '+') : '';
    $phone = isset($contact['phone']) ? trim((string) $contact['phone']) : '';

    // Porkbun stores and validates the calling code as its own field, so give
    // it its own box here too. Folding it into the number means guessing it
    // back out on save, and a bare national number has nothing to guess from.
    if ($cc === '') {
        list($cc, $phone) = porkbun_splitPhone($phone);
    }

    return array(
        'First Name' => isset($contact['firstName']) ? $contact['firstName'] : '',
        'Last Name' => isset($contact['lastName']) ? $contact['lastName'] : '',
        'Company Name' => isset($contact['organization']) ? $contact['organization'] : '',
        'Email' => isset($contact['email']) ? $contact['email'] : '',
        'Address 1' => isset($contact['address1']) ? $contact['address1'] : '',
        'Address 2' => isset($contact['address2']) ? $contact['address2'] : '',
        'City' => isset($contact['city']) ? $contact['city'] : '',
        'State' => isset($contact['state']) ? $contact['state'] : '',
        'Postcode' => isset($contact['postalCode']) ? $contact['postalCode'] : '',
        'Country' => isset($contact['country']) ? $contact['country'] : '',
        'Phone Country Code' => $cc,
        'Phone' => $phone,
    );
}

/**
 * Map WHMCS contact fields (as labelled by this module) back to Porkbun.
 *
 * @param array $contact
 * @return array
 */
function porkbun_whmcsToContact($contact)
{
    $phoneCc = isset($contact['Phone Country Code'])
        ? preg_replace('/\D/', '', (string) $contact['Phone Country Code'])
        : '';
    $phone = isset($contact['Phone']) ? (string) $contact['Phone'] : '';

    // A number pasted in as +44.20… still has to work, so fall back to
    // splitting it when the dedicated field is empty.
    if ($phoneCc === '') {
        list($phoneCc, $phone) = porkbun_splitPhone($phone);
    } else {
        $phone = preg_replace('/\D/', '', $phone);
    }

    $mapped = array(
        'firstName' => isset($contact['First Name']) ? $contact['First Name'] : '',
        'lastName' => isset($contact['Last Name']) ? $contact['Last Name'] : '',
        'organization' => isset($contact['Company Name']) ? $contact['Company Name'] : '',
        'email' => isset($contact['Email']) ? $contact['Email'] : '',
        'address1' => isset($contact['Address 1']) ? $contact['Address 1'] : '',
        'address2' => isset($contact['Address 2']) ? $contact['Address 2'] : '',
        'city' => isset($contact['City']) ? $contact['City'] : '',
        'state' => isset($contact['State']) ? $contact['State'] : '',
        'postalCode' => isset($contact['Postcode']) ? $contact['Postcode'] : '',
        'country' => isset($contact['Country']) ? $contact['Country'] : '',
        'phone' => $phone,
    );

    if ($phoneCc !== '') {
        $mapped['phoneCountryCode'] = $phoneCc;
    }

    return array_filter($mapped, function ($value) {
        return $value !== '' && $value !== null;
    });
}

/**
 * Split a +CC.number phone string into Porkbun's two fields, for values that
 * arrive with the calling code folded into the number.
 *
 * @param string $phone
 * @return array [country code, number]
 */
function porkbun_splitPhone($phone)
{
    $phone = trim((string) $phone);

    // Only treat a leading group as a calling code when the string says so:
    // a + prefix, or the dot WHMCS uses. Guessing from a space would read the
    // area code of a national number like "0123 4567890" as a country code.
    if (preg_match('/^\+\s*(\d{1,4})[.\-\s]+(.+)$/', $phone, $matches)
        || preg_match('/^(\d{1,4})\.(.+)$/', $phone, $matches)) {
        return array($matches[1], preg_replace('/\D/', '', $matches[2]));
    }

    return array('', preg_replace('/\D/', '', $phone));
}

/**
 * Strip the domain suffix to get the subdomain Porkbun expects in the "name" field.
 * Root record -> "" ; "www.example.com" -> "www" ; "www" -> "www".
 *
 * @param string $hostname
 * @param string $domain
 * @return string
 */
function porkbun_relativeName($hostname, $domain)
{
    $hostname = rtrim(trim((string) $hostname), '.');
    $domain = rtrim(trim((string) $domain), '.');

    if ($hostname === '' || strcasecmp($hostname, $domain) === 0 || $hostname === '@') {
        return '';
    }

    $suffix = '.' . $domain;
    $suffixLength = strlen($suffix);

    if (strlen($hostname) > $suffixLength
        && strcasecmp(substr($hostname, -$suffixLength), $suffix) === 0) {
        return substr($hostname, 0, -$suffixLength);
    }

    // Already a bare subdomain label.
    return $hostname;
}
