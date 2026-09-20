<?php
/**
 * Porkbun pricing CLI for the WHMCS registrar module.
 * ---------------------------------------------------
 * Turns Porkbun's cost prices into your retail prices and writes them into
 * WHMCS' Domain Pricing, using the markup rules set on the registrar.
 *
 *   php pricing.php                      preview every change, write nothing
 *   php pricing.php --apply              write them
 *   php pricing.php --tld=.com,.dev      limit the run to these extensions
 *   php pricing.php --all                show unchanged/skipped TLDs too
 *
 * A run with no --apply is read-only: it calls Porkbun and reads WHMCS, and
 * that is all. Read the preview before the first apply, particularly the
 * "terms" line - selling 1-year only is what the API can actually deliver,
 * and switching it on turns years 2-10 off for the TLDs in scope.
 *
 * The same work happens by itself on the daily cron once "Sync retail pricing
 * daily" is ticked; this is for previewing it and for running it now.
 *
 * @package    WHMCS\Module\Registrar\Porkbun
 * @license    MIT
 */

require __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/PorkbunApi.php';
require_once __DIR__ . '/lib/PorkbunPricing.php';
require_once __DIR__ . '/lib/PorkbunPricingSync.php';

use WHMCS\Module\Registrar\Porkbun\PorkbunApi;
use WHMCS\Module\Registrar\Porkbun\PorkbunPricing;
use WHMCS\Module\Registrar\Porkbun\PorkbunPricingSync;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script is CLI only.\n";
    exit;
}

exit(porkbun_pricing_cli(array_slice($argv, 1)));

/**
 * @param array $args
 * @return int exit code
 */
function porkbun_pricing_cli(array $args)
{
    $apply = false;
    $showAll = false;
    $onlyTlds = array();

    foreach ($args as $arg) {
        if ($arg === '--apply') {
            $apply = true;
        } elseif ($arg === '--all') {
            $showAll = true;
        } elseif (strpos($arg, '--tld=') === 0) {
            foreach (preg_split('/[\s,;]+/', substr($arg, 6)) as $tld) {
                $tld = trim($tld);
                if ($tld !== '') {
                    $onlyTlds[] = $tld;
                }
            }
        } elseif ($arg === '--help' || $arg === '-h') {
            porkbun_pricing_usage();
            return 0;
        } else {
            fwrite(STDERR, "Unknown option: " . $arg . "\n\n");
            porkbun_pricing_usage();
            return 1;
        }
    }

    if (!function_exists('getregistrarconfigoptions')) {
        require_once ROOTDIR . '/includes/registrarfunctions.php';
    }

    $config = getregistrarconfigoptions('porkbun');
    if (!is_array($config) || empty($config['apiKey'])) {
        fwrite(STDERR, "Porkbun is not configured. Activate and configure it under Domain Registrars first.\n");
        return 1;
    }

    $redact = array_values(array_filter(array(
        isset($config['apiKey']) ? $config['apiKey'] : '',
        isset($config['secretKey']) ? $config['secretKey'] : '',
    )));

    $api = new PorkbunApi(
        $config['apiKey'],
        isset($config['secretKey']) ? $config['secretKey'] : '',
        function ($action, $request, $response) use ($redact) {
            logModuleCall('porkbun', $action, $request, $response, null, $redact);
        }
    );

    try {
        $run = PorkbunPricingSync::run($config, $api, $apply, $onlyTlds);
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    porkbun_pricing_report($run['plan'], $run['counts'], $config, $showAll);

    if ($run['counts'] !== null && $run['counts']['failed'] > 0) {
        return 1;
    }

    return 0;
}

function porkbun_pricing_usage()
{
    echo "Porkbun pricing sync\n\n";
    echo "  php pricing.php                 preview every change (writes nothing)\n";
    echo "  php pricing.php --apply         write the changes into WHMCS\n";
    echo "  php pricing.php --tld=.com,.io  limit the run to these extensions\n";
    echo "  php pricing.php --all           list unchanged and skipped TLDs too\n";
}

/**
 * @param array      $plan
 * @param array|null $counts
 * @param array      $config
 * @param bool       $showAll
 */
function porkbun_pricing_report(array $plan, $counts, array $config, $showAll)
{
    $currency = $plan['currency'];
    $applied = ($counts !== null);
    $oneYearOnly = (!isset($config['pricingYears']) || $config['pricingYears'] !== 'keep');

    echo ($applied ? "Applying" : "Preview (nothing written)") . " — prices in " . $currency;
    if (abs($plan['rate'] - 1.0) > 0.0000001) {
        echo ", converted from USD at " . rtrim(rtrim(number_format($plan['rate'], 4, '.', ''), '0'), '.');
    }
    echo "\n";
    echo "Terms: " . ($oneYearOnly
        ? "1 year only (years 2-10 switched off)"
        : "year 1 priced, other terms left as they are") . "\n\n";

    printf("%-16s %-10s %-24s %-24s %s\n", 'TLD', 'ACTION', 'COST (reg/renew/xfer)', 'RETAIL (reg/renew/xfer)', 'NOTE');
    echo str_repeat('-', 110) . "\n";

    $shown = 0;

    foreach ($plan['rows'] as $row) {
        if ($row['action'] === 'skip' && !$showAll) {
            continue;
        }
        $shown++;

        $note = $row['error'] !== '' ? 'FAILED: ' . $row['error'] : $row['reason'];

        printf(
            "%-16s %-10s %-24s %-24s %s\n",
            $row['tld'],
            $row['error'] !== '' ? 'failed' : $row['action'],
            porkbun_pricing_triple($row['cost']),
            porkbun_pricing_triple($row['price']),
            $note
        );
    }

    if ($shown === 0) {
        echo "(nothing to change)\n";
    }

    echo "\n" . PorkbunPricingSync::summarise($plan, $counts) . "\n";

    if (!$applied && $shown > 0) {
        echo "Re-run with --apply to write these prices.\n";
    }
}

/**
 * "11.08 / 11.08 / 11.08", with a dash where there is no figure.
 *
 * @param array $prices
 * @return string
 */
function porkbun_pricing_triple(array $prices)
{
    $parts = array();

    foreach (PorkbunPricing::OPERATIONS as $operation) {
        $parts[] = isset($prices[$operation])
            ? number_format((float) $prices[$operation], 2, '.', '')
            : '-';
    }

    return implode(' / ', $parts);
}
