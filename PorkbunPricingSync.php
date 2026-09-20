<?php
/**
 * Writes Porkbun's prices, plus your markup, into WHMCS' domain pricing.
 *
 * WHMCS' own Import Pricing tool does a one-off import of *cost* prices and
 * applies a margin in the wizard. This does the same job on a schedule and
 * from the module's own markup rules, so a Porkbun price change reaches the
 * customer-facing price list without anybody opening the admin area.
 *
 * Nothing here writes to WHMCS tables directly: every change goes through the
 * CreateOrUpdateTLD API, which is what recalculates the other currencies.
 */

namespace WHMCS\Module\Registrar\Porkbun;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use Illuminate\Database\Capsule\Manager as Capsule;

class PorkbunPricingSync
{
    /** WHMCS caps registration at 10 years and renewal at 9. */
    const MAX_REGISTER_YEARS = 10;
    const MAX_RENEW_YEARS = 9;

    /** @var array registrar module configuration */
    protected $config;

    /** @var PorkbunApi */
    protected $api;

    /** @var string currency code prices are written in */
    protected $currency = 'USD';

    /** @var float multiplier from Porkbun's USD onto $currency */
    protected $rate = 1.0;

    public function __construct(array $config, PorkbunApi $api)
    {
        $this->config = $config;
        $this->api = $api;
    }

    /* ==================================================================
     * Planning
     * ================================================================ */

    /**
     * Work out what every in-scope TLD should cost, without changing
     * anything. The result is the same whether it is about to be applied or
     * just printed, which is what makes --dry-run worth trusting.
     *
     * @param array $onlyTlds Optional. Restrict the run to these extensions.
     * @return array {currency: string, rate: float, rows: array}
     * @throws PorkbunPricingException
     */
    public function plan(array $onlyTlds = array())
    {
        list($this->currency, $this->rate) = $this->resolveCurrency();

        $base = PorkbunPricing::baseRule($this->config);
        $rules = PorkbunPricing::parseRules(
            isset($this->config['markupOverrides']) ? $this->config['markupOverrides'] : ''
        );

        $costs = PorkbunPricing::costs($this->api, $this->boolean('includeHandshake'));
        $existing = $this->whmcsTlds();

        $targets = $this->targets($costs, $existing);

        if ($onlyTlds) {
            $filter = array();
            foreach ($onlyTlds as $tld) {
                $filter[PorkbunPricing::normaliseTld($tld)] = true;
            }
            $targets = array_values(array_filter($targets, function ($tld) use ($filter) {
                return isset($filter[$tld]);
            }));
        }

        $rows = array();
        foreach ($targets as $tld) {
            $rows[] = $this->row($tld, $costs, $existing, $rules, $base);
        }

        return array(
            'currency' => $this->currency,
            'rate' => $this->rate,
            'rows' => $rows,
        );
    }

    /**
     * Build the plan for a single extension.
     *
     * @param string $tld
     * @param array  $costs
     * @param array  $existing
     * @param array  $rules
     * @param array  $base
     * @return array
     */
    protected function row($tld, array $costs, array $existing, array $rules, array $base)
    {
        $row = array(
            'tld' => $tld,
            'action' => 'skip',
            'reason' => '',
            'cost' => array(),
            'price' => array(),
            'error' => '',
        );

        if (!isset($costs[$tld])) {
            $row['reason'] = 'Porkbun does not list this TLD';
            return $row;
        }

        $registrar = isset($existing[$tld]) ? $existing[$tld]['autoreg'] : null;

        // Never take a TLD off another registrar. Somebody chose that, and a
        // nightly cron is the wrong place to change their mind.
        if ($registrar !== null && $registrar !== '' && $registrar !== 'porkbun') {
            $row['reason'] = 'assigned to the ' . $registrar . ' registrar in WHMCS';
            return $row;
        }

        foreach (PorkbunPricing::OPERATIONS as $operation) {
            $cost = $costs[$tld][$operation];

            if ($cost === null) {
                continue;
            }

            // Porkbun quotes 0.00 for transfers it does not price - .uk among
            // them, which cannot be transferred through the API at all. That
            // is not the same as a free transfer, so leave whatever WHMCS
            // already has rather than publish a zero.
            if ($operation === 'transfer' && $cost <= 0) {
                $row['cost'][$operation] = 0.0;
                continue;
            }

            $converted = round($cost * $this->rate, 4);
            $rule = PorkbunPricing::ruleFor($rules, $tld, $operation, $base);
            $price = PorkbunPricing::retail($converted, $rule);

            $row['cost'][$operation] = round($converted, 2);
            if ($price !== null) {
                $row['price'][$operation] = $price;
            }
        }

        if (!$row['price']) {
            $row['reason'] = 'no price to write (every operation skipped or unpriced)';
            return $row;
        }

        $row['action'] = isset($existing[$tld]) ? 'update' : 'create';

        return $row;
    }

    /**
     * Which extensions this run covers.
     *
     * @param array $costs
     * @param array $existing
     * @return array
     */
    protected function targets(array $costs, array $existing)
    {
        $scope = isset($this->config['pricingScope']) ? trim((string) $this->config['pricingScope']) : '';

        if ($scope === 'all') {
            return array_keys($costs);
        }

        if ($scope === 'list') {
            $listed = preg_split('/[\s,;]+/', isset($this->config['pricingTlds']) ? $this->config['pricingTlds'] : '');
            $targets = array();
            foreach ($listed as $tld) {
                $tld = PorkbunPricing::normaliseTld($tld);
                if ($tld !== '' && $tld !== '.') {
                    $targets[$tld] = true;
                }
            }
            return array_keys($targets);
        }

        // Default: refresh what you already sell through Porkbun and add
        // nothing. A new TLD in the customer-facing list stays a decision.
        $targets = array();
        foreach ($existing as $tld => $info) {
            if ($info['autoreg'] === 'porkbun') {
                $targets[] = $tld;
            }
        }

        return $targets;
    }

    /* ==================================================================
     * Applying
     * ================================================================ */

    /**
     * Write a plan's rows into WHMCS. Rows are mutated in place with the
     * outcome so the caller can report on exactly what it planned.
     *
     * @param array $plan as returned by plan()
     * @return array {created: int, updated: int, skipped: int, failed: int}
     */
    public function apply(array &$plan)
    {
        $counts = array('created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0);

        foreach ($plan['rows'] as &$row) {
            if ($row['action'] === 'skip') {
                $counts['skipped']++;
                continue;
            }

            try {
                $this->write($row, $plan['currency']);
                $counts[$row['action'] === 'create' ? 'created' : 'updated']++;
            } catch (\Throwable $e) {
                $row['error'] = $e->getMessage();
                $counts['failed']++;
            }
        }
        unset($row);

        return $counts;
    }

    /**
     * One CreateOrUpdateTLD call. Only pricing is sent: ID protection, DNS
     * management and the rest are admin decisions that a price sync has no
     * business overwriting.
     *
     * @param array  $row
     * @param string $currency
     * @throws PorkbunPricingException
     */
    protected function write(array $row, $currency)
    {
        $oneYearOnly = $this->oneYearOnly();

        $post = array(
            'extension' => $row['tld'],
            'currency_code' => $currency,
        );

        if (isset($row['price']['register'])) {
            $post['register'] = $this->years($row['price']['register'], $oneYearOnly ? self::MAX_REGISTER_YEARS : 1);
        }
        if (isset($row['price']['renew'])) {
            $post['renew'] = $this->years($row['price']['renew'], $oneYearOnly ? self::MAX_RENEW_YEARS : 1);
        }
        if (isset($row['price']['transfer'])) {
            // WHMCS only holds a transfer price for the minimum period.
            $post['transfer'] = array(1 => $this->money($row['price']['transfer']));
        }

        // Set the registrar and the EPP requirement when introducing a TLD.
        // On one we already own, both are the admin's to change.
        if ($row['action'] === 'create') {
            $post['auto_registrar'] = 'porkbun';
            $post['epp_required'] = true;
        }

        if (!function_exists('localAPI')) {
            throw new PorkbunPricingException('WHMCS is not loaded, so pricing cannot be written.');
        }

        $result = \localAPI('CreateOrUpdateTLD', $post);

        if (!is_array($result) || !isset($result['result']) || $result['result'] !== 'success') {
            $message = is_array($result) && isset($result['message']) ? $result['message'] : 'unknown error';
            throw new PorkbunPricingException('CreateOrUpdateTLD rejected ' . $row['tld'] . ': ' . $message);
        }
    }

    /**
     * Year 1 carries the price. Years 2 and up are switched off (-1) when the
     * module is set to sell single years only, which is all the Porkbun API
     * can actually deliver - it registers and renews the registry minimum per
     * call, so a customer who buys three years gets one.
     *
     * @param float $price
     * @param int   $upTo
     * @return array
     */
    protected function years($price, $upTo)
    {
        $years = array(1 => $this->money($price));

        for ($year = 2; $year <= $upTo; $year++) {
            $years[$year] = '-1';
        }

        return $years;
    }

    /**
     * @param float $value
     * @return string
     */
    protected function money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return bool
     */
    protected function oneYearOnly()
    {
        $value = isset($this->config['pricingYears']) ? trim((string) $this->config['pricingYears']) : '';

        return $value !== 'keep';
    }

    /* ==================================================================
     * WHMCS state
     * ================================================================ */

    /**
     * Extensions WHMCS already knows about, and which registrar each is
     * pointed at.
     *
     * @return array
     * @throws PorkbunPricingException
     */
    protected function whmcsTlds()
    {
        try {
            $rows = Capsule::table('tbldomainpricing')->get(array('id', 'extension', 'autoreg'));
        } catch (\Throwable $e) {
            throw new PorkbunPricingException('Could not read the WHMCS domain pricing table: ' . $e->getMessage());
        }

        $map = array();
        foreach ($rows as $row) {
            $row = (array) $row;
            $extension = PorkbunPricing::normaliseTld(isset($row['extension']) ? $row['extension'] : '');
            if ($extension === '' || $extension === '.') {
                continue;
            }
            $map[$extension] = array(
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'autoreg' => strtolower(trim((string) (isset($row['autoreg']) ? $row['autoreg'] : ''))),
            );
        }

        return $map;
    }

    /**
     * Which currency to write prices in, and what to multiply Porkbun's USD
     * by to get there.
     *
     * Passing USD straight through is the simple path and lets WHMCS convert
     * into every other active currency itself - but that needs USD to be one
     * of the configured currencies, which a UK-only install will not have.
     * The conversion here is the way out of that.
     *
     * @return array [string $currency, float $rate]
     * @throws PorkbunPricingException
     */
    public function resolveCurrency()
    {
        $currency = strtoupper(trim((string) (isset($this->config['pricingCurrency']) ? $this->config['pricingCurrency'] : '')));
        if ($currency === '') {
            $currency = 'USD';
        }

        if ($currency === 'USD') {
            return array('USD', 1.0);
        }

        $override = trim((string) (isset($this->config['pricingFxRate']) ? $this->config['pricingFxRate'] : ''));
        if ($override !== '') {
            if (!is_numeric($override) || (float) $override <= 0) {
                throw new PorkbunPricingException('"USD conversion rate" must be a positive number, not "' . $override . '".');
            }
            return array($currency, (float) $override);
        }

        $rates = $this->whmcsRates();

        if (!isset($rates['USD'])) {
            throw new PorkbunPricingException(
                'Porkbun prices in USD, but USD is not a configured WHMCS currency. Either add it under '
                . 'Configuration > System Settings > Currencies, or fill in "USD conversion rate" on the registrar.'
            );
        }
        if (!isset($rates[$currency])) {
            throw new PorkbunPricingException('"' . $currency . '" is not a configured WHMCS currency.');
        }
        if ($rates['USD'] <= 0) {
            throw new PorkbunPricingException('The WHMCS exchange rate for USD is zero, so prices cannot be converted.');
        }

        // WHMCS rates are all relative to the default currency, so going from
        // one to another is a ratio rather than a single rate.
        return array($currency, $rates[$currency] / $rates['USD']);
    }

    /**
     * @return array code => rate
     * @throws PorkbunPricingException
     */
    protected function whmcsRates()
    {
        try {
            $rows = Capsule::table('tblcurrencies')->get(array('code', 'rate'));
        } catch (\Throwable $e) {
            throw new PorkbunPricingException('Could not read the WHMCS currencies table: ' . $e->getMessage());
        }

        $rates = array();
        foreach ($rows as $row) {
            $row = (array) $row;
            $code = strtoupper(trim((string) (isset($row['code']) ? $row['code'] : '')));
            if ($code !== '') {
                $rates[$code] = (float) (isset($row['rate']) ? $row['rate'] : 0);
            }
        }

        return $rates;
    }

    /**
     * @param string $field
     * @return bool
     */
    protected function boolean($field)
    {
        if (!array_key_exists($field, $this->config)) {
            return false;
        }
        $value = $this->config[$field];

        return ($value === 'on' || $value === '1' || $value === 1 || $value === true || $value === 'yes');
    }

    /* ==================================================================
     * Entry point shared by the cron hook and the CLI
     * ================================================================ */

    /**
     * Plan, optionally apply, and hand back both so the caller can report.
     *
     * @param array      $config
     * @param PorkbunApi $api
     * @param bool       $apply
     * @param array      $onlyTlds
     * @return array {plan: array, counts: array|null}
     * @throws PorkbunPricingException
     */
    public static function run(array $config, PorkbunApi $api, $apply, array $onlyTlds = array())
    {
        $sync = new self($config, $api);
        $plan = $sync->plan($onlyTlds);

        $counts = null;
        if ($apply) {
            $counts = $sync->apply($plan);
        }

        return array('plan' => $plan, 'counts' => $counts);
    }

    /**
     * One-line summary of a run, for the module log and the activity log.
     *
     * @param array      $plan
     * @param array|null $counts
     * @return string
     */
    public static function summarise(array $plan, $counts = null)
    {
        $rows = $plan['rows'];
        $planned = 0;
        foreach ($rows as $row) {
            if ($row['action'] !== 'skip') {
                $planned++;
            }
        }

        if ($counts === null) {
            return count($rows) . ' TLDs examined, ' . $planned . ' would change (' . $plan['currency'] . ', dry run).';
        }

        return count($rows) . ' TLDs examined in ' . $plan['currency'] . ': '
            . $counts['created'] . ' created, '
            . $counts['updated'] . ' updated, '
            . $counts['skipped'] . ' skipped, '
            . $counts['failed'] . ' failed.';
    }
}
