<?php
/**
 * Hooks for the Porkbun registrar module.
 *
 * WHMCS picks this file up automatically, but only re-scans for it when the
 * registrar is activated or saved: after adding or changing it, open
 * Configuration > System Settings > Domain Registrars and click Save Changes
 * on Porkbun.
 *
 * @package    WHMCS\Module\Registrar\Porkbun
 * @license    MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/PorkbunApi.php';
require_once __DIR__ . '/lib/PorkbunPricing.php';
require_once __DIR__ . '/lib/PorkbunPricingSync.php';

use WHMCS\Module\Registrar\Porkbun\PorkbunApi;
use WHMCS\Module\Registrar\Porkbun\PorkbunPricingSync;

/**
 * Re-price the TLDs you sell from Porkbun's current cost, once a day.
 *
 * Porkbun moves prices when a registry does, which for the cheaper TLDs can
 * be several times a year. Without this the WHMCS price list is only as
 * current as the last time somebody ran the import wizard, and the first sign
 * that a renewal now costs more than you charge for it is a negative margin.
 *
 * Off unless "Sync retail pricing daily" is ticked on the registrar.
 */
add_hook('DailyCronJob', 1, function ($vars) {
    if (!function_exists('getregistrarconfigoptions')) {
        require_once ROOTDIR . '/includes/registrarfunctions.php';
    }

    $config = getregistrarconfigoptions('porkbun');
    if (!is_array($config) || empty($config['pricingSyncEnabled'])) {
        return;
    }

    $redact = array_values(array_filter(array(
        isset($config['apiKey']) ? $config['apiKey'] : '',
        isset($config['secretKey']) ? $config['secretKey'] : '',
    )));

    $api = new PorkbunApi(
        isset($config['apiKey']) ? $config['apiKey'] : '',
        isset($config['secretKey']) ? $config['secretKey'] : '',
        function ($action, $request, $response) use ($redact) {
            logModuleCall('porkbun', $action, $request, $response, null, $redact);
        }
    );

    try {
        $run = PorkbunPricingSync::run($config, $api, true);
        $summary = PorkbunPricingSync::summarise($run['plan'], $run['counts']);

        // Every row, so the log answers "why did .io change on Tuesday".
        logModuleCall('porkbun', 'Pricing Sync', array(
            'scope' => isset($config['pricingScope']) ? $config['pricingScope'] : 'existing',
        ), array(
            'summary' => $summary,
            'rows' => $run['plan']['rows'],
        ));

        // Failures are the part somebody needs to see without going looking.
        if (!empty($run['counts']['failed'])) {
            logActivity('Porkbun pricing sync: ' . $summary . ' See the module log for the failures.');
        } elseif ($run['counts']['created'] || $run['counts']['updated']) {
            logActivity('Porkbun pricing sync: ' . $summary);
        }
    } catch (\Throwable $e) {
        logModuleCall('porkbun', 'Pricing Sync', array(), 'Failed: ' . $e->getMessage());
        logActivity('Porkbun pricing sync failed: ' . $e->getMessage());
    }
});
