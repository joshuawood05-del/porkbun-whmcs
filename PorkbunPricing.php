<?php
/**
 * Porkbun cost pricing and the retail markup rules applied to it.
 *
 * Porkbun has no reseller tier: the prices here are what your own account pays.
 * Everything a customer is charged is derived from them by the markup rules,
 * which is the whole job of this class. It talks to Porkbun and to nothing
 * else - the WHMCS side of the sync lives in PorkbunPricingSync.
 */

namespace WHMCS\Module\Registrar\Porkbun;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * A markup rule that could not be understood, or pricing that could not be
 * fetched. Always carries a message meant for a WHMCS admin to read.
 */
class PorkbunPricingException extends \Exception
{
}

class PorkbunPricing
{
    /** The three prices WHMCS holds per TLD, in WHMCS' own vocabulary. */
    const OPERATIONS = array('register', 'renew', 'transfer');

    /** WHMCS' names for them mapped onto the keys pricing/get returns. */
    protected static $costKeys = array(
        'register' => 'registration',
        'renew' => 'renewal',
        'transfer' => 'transfer',
    );

    /* ==================================================================
     * Cost prices
     * ================================================================ */

    /**
     * Porkbun's own price list, normalised and keyed by extension with a
     * leading dot (".com", ".co.uk") to match how WHMCS stores TLDs.
     *
     * Each entry has register/renew/transfer as floats in USD, or null where
     * Porkbun quotes no price for that operation.
     *
     * @param PorkbunApi $api
     * @param bool       $includeHandshake
     * @return array
     * @throws PorkbunPricingException
     */
    public static function costs(PorkbunApi $api, $includeHandshake = false)
    {
        $data = $api->pricingGet();
        $raw = isset($data['pricing']) && is_array($data['pricing']) ? $data['pricing'] : array();

        if (!$raw) {
            throw new PorkbunPricingException('Porkbun returned an empty price list.');
        }

        $costs = array();

        foreach ($raw as $tld => $prices) {
            if (!is_array($prices)) {
                continue;
            }

            $extension = self::normaliseTld($tld);
            if ($extension === '' || $extension === '.') {
                continue;
            }

            // Roughly a third of the list is Handshake: blockchain names that
            // resolve only behind a Handshake resolver, share no registry with
            // ICANN TLDs, and would otherwise import as ~270 extensions no
            // ordinary customer can use.
            $specialType = isset($prices['specialType']) ? strtolower((string) $prices['specialType']) : '';
            if ($specialType === 'handshake' && !$includeHandshake) {
                continue;
            }

            $entry = array('specialType' => $specialType);
            foreach (self::$costKeys as $operation => $key) {
                $entry[$operation] = self::amount(isset($prices[$key]) ? $prices[$key] : null);
            }

            // No registration price means nothing sellable, whatever else the
            // entry says.
            if ($entry['register'] === null) {
                continue;
            }

            $costs[$extension] = $entry;
        }

        if (!$costs) {
            throw new PorkbunPricingException('Porkbun returned prices, but none of them were usable.');
        }

        ksort($costs);

        return $costs;
    }

    /**
     * ".com", "com" and " .COM " all become ".com".
     *
     * @param string $tld
     * @return string
     */
    public static function normaliseTld($tld)
    {
        $tld = strtolower(trim((string) $tld));
        $tld = trim($tld, ".\t\n\r\0\x0B ");

        return $tld === '' ? '' : '.' . $tld;
    }

    /**
     * Porkbun quotes prices as strings ("9.73"). Anything non-numeric - an
     * empty string, a null, a "call us" - is treated as no price at all
     * rather than as zero, because zero is a price.
     *
     * @param mixed $value
     * @return float|null
     */
    protected static function amount($value)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /* ==================================================================
     * Markup rules
     * ================================================================ */

    /**
     * The markup applied when no per-TLD override matches, built from the
     * registrar module's configuration fields.
     *
     * @param array $config
     * @return array
     * @throws PorkbunPricingException
     */
    public static function baseRule(array $config)
    {
        $percent = self::configNumber($config, 'markupPercent', 'Markup %');
        $fixed = self::configNumber($config, 'markupFixed', 'Fixed uplift');
        $minimum = self::configNumber($config, 'markupMinMargin', 'Minimum margin');

        $rule = array(
            'percent' => $percent,
            'fixed' => $fixed,
            'min' => $minimum,
            'flat' => null,
            'round' => self::roundingMode(isset($config['markupRounding']) ? $config['markupRounding'] : '', 0),
            'skip' => false,
            'source' => 'default',
        );

        if ($rule['percent'] === null && $rule['fixed'] === null && $rule['min'] === null) {
            // Cost price with no uplift at all is almost certainly a mistake,
            // but it is a legible one, so say so rather than guess a number.
            throw new PorkbunPricingException(
                'No markup is configured. Set a Markup %, a Fixed uplift or a Minimum margin before syncing retail prices.'
            );
        }

        return $rule;
    }

    /**
     * Parse the per-TLD override block. One rule per line:
     *
     *     .com              30% +1.00     # 30% then a pound on top
     *     .co.uk            60%
     *     .io:renew         20% min 8.00  # never less than 8.00 of margin
     *     .dev              flat 14.99    # fixed retail price, cost ignored
     *     .xyz:transfer     skip          # leave this price alone in WHMCS
     *     *:transfer        15%           # every TLD, transfers only
     *
     * @param string $text
     * @return array selector => rule
     * @throws PorkbunPricingException
     */
    public static function parseRules($text)
    {
        $rules = array();
        $lines = preg_split('/\R/', (string) $text);

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            $line = preg_replace('/(^|\s)#.*$/', '', $line);
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', $line);
            $selector = array_shift($tokens);
            $key = self::parseSelector($selector, $number);

            if (isset($rules[$key])) {
                throw new PorkbunPricingException(
                    'Markup rules, line ' . $number . ': "' . $key . '" is already set on an earlier line.'
                );
            }

            $rule = self::parseTokens($tokens, $number);
            $rule['source'] = $key;
            $rules[$key] = $rule;
        }

        return $rules;
    }

    /**
     * ".io:renew" -> ".io:renew", "com" -> ".com", "*" -> "*".
     *
     * @param string $selector
     * @param int    $number
     * @return string
     * @throws PorkbunPricingException
     */
    protected static function parseSelector($selector, $number)
    {
        $parts = explode(':', strtolower(trim($selector)));

        if (count($parts) > 2) {
            throw new PorkbunPricingException(
                'Markup rules, line ' . $number . ': "' . $selector . '" has more than one colon. Use .tld or .tld:operation.'
            );
        }

        $tld = trim($parts[0]);
        $operation = isset($parts[1]) ? trim($parts[1]) : '';

        if ($tld === '') {
            throw new PorkbunPricingException('Markup rules, line ' . $number . ': no TLD before the colon.');
        }
        if ($tld !== '*') {
            $tld = self::normaliseTld($tld);
        }

        if ($operation !== '' && !in_array($operation, self::OPERATIONS, true)) {
            throw new PorkbunPricingException(
                'Markup rules, line ' . $number . ': "' . $operation . '" is not an operation. Use register, renew or transfer.'
            );
        }

        return $operation === '' ? $tld : $tld . ':' . $operation;
    }

    /**
     * @param array $tokens
     * @param int   $number
     * @return array
     * @throws PorkbunPricingException
     */
    protected static function parseTokens(array $tokens, $number)
    {
        $rule = array(
            'percent' => null,
            'fixed' => null,
            'min' => null,
            'flat' => null,
            'round' => null,
            'skip' => false,
        );

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = trim($tokens[$i]);
            if ($token === '') {
                continue;
            }

            $lower = strtolower($token);

            if ($lower === 'skip') {
                $rule['skip'] = true;
                continue;
            }

            // The three keywords that take a value in the next token.
            if ($lower === 'min' || $lower === 'flat' || $lower === 'round') {
                if (!isset($tokens[$i + 1])) {
                    throw new PorkbunPricingException(
                        'Markup rules, line ' . $number . ': "' . $lower . '" needs a value after it.'
                    );
                }
                $value = $tokens[++$i];

                if ($lower === 'round') {
                    $rule['round'] = self::roundingMode($value, $number);
                } else {
                    $rule[$lower] = self::number($value, $lower, $number);
                }
                continue;
            }

            if (substr($lower, -1) === '%') {
                $rule['percent'] = self::number(substr($token, 0, -1), 'percentage', $number);
                continue;
            }

            if ($lower[0] === '+') {
                $rule['fixed'] = self::number(substr($token, 1), 'fixed uplift', $number);
                continue;
            }

            throw new PorkbunPricingException(
                'Markup rules, line ' . $number . ': cannot read "' . $token . '". '
                . 'Expected one of: 30%, +1.00, min 5.00, flat 14.99, round .99, skip.'
            );
        }

        if (!$rule['skip']
            && $rule['percent'] === null
            && $rule['fixed'] === null
            && $rule['min'] === null
            && $rule['flat'] === null
        ) {
            throw new PorkbunPricingException(
                'Markup rules, line ' . $number . ': the TLD is named but no markup is given.'
            );
        }

        return $rule;
    }

    /**
     * The rule that applies to one TLD and operation. The most specific match
     * wins outright rather than merging, so a rule reads as the whole answer
     * for what it names. Rounding is the exception: a rule that says nothing
     * about rounding inherits it, because rounding is a house style rather
     * than part of the margin.
     *
     * @param array  $rules
     * @param string $tld
     * @param string $operation
     * @param array  $base
     * @return array
     */
    public static function ruleFor(array $rules, $tld, $operation, array $base)
    {
        $candidates = array(
            $tld . ':' . $operation,
            $tld,
            '*:' . $operation,
            '*',
        );

        foreach ($candidates as $candidate) {
            if (!isset($rules[$candidate])) {
                continue;
            }
            $rule = $rules[$candidate];
            if ($rule['round'] === null) {
                $rule['round'] = $base['round'];
            }
            return $rule;
        }

        return $base;
    }

    /**
     * Apply a rule to a cost price. Returns null when the rule says to leave
     * the price alone.
     *
     * @param float $cost
     * @param array $rule
     * @return float|null
     */
    public static function retail($cost, array $rule)
    {
        if (!empty($rule['skip'])) {
            return null;
        }

        if ($rule['flat'] !== null) {
            return self::roundTo($rule['flat'], $rule['round']);
        }

        $cost = (float) $cost;
        $price = $cost;

        if ($rule['percent'] !== null) {
            $price = $cost * (1 + ($rule['percent'] / 100));
        }
        if ($rule['fixed'] !== null) {
            $price += $rule['fixed'];
        }

        // A percentage of a 2.04 first-year price is pennies of margin, which
        // does not pay for the support ticket that follows it.
        if ($rule['min'] !== null && $rule['min'] > 0 && $price < $cost + $rule['min']) {
            $price = $cost + $rule['min'];
        }

        if ($price < 0) {
            $price = 0.0;
        }

        return self::roundTo($price, $rule['round']);
    }

    /**
     * @param float  $value
     * @param string $mode none|up|.95|.99
     * @return float
     */
    public static function roundTo($value, $mode)
    {
        $value = (float) $value;

        switch ($mode) {
            case 'up':
                return (float) ceil($value - 0.0000001);
            case '.95':
                return self::endingIn($value, 0.95);
            case '.99':
                return self::endingIn($value, 0.99);
            default:
                return round($value, 2);
        }
    }

    /**
     * Smallest price ending in $ending that is not less than $value.
     *
     * @param float $value
     * @param float $ending
     * @return float
     */
    protected static function endingIn($value, $ending)
    {
        $whole = (int) ceil($value - $ending - 0.0000001);
        if ($whole < 0) {
            $whole = 0;
        }

        return round($whole + $ending, 2);
    }

    /**
     * @param string     $value
     * @param int|string $number line number, or 0 for a configuration field
     * @return string none|up|.95|.99
     * @throws PorkbunPricingException
     */
    public static function roundingMode($value, $number)
    {
        $value = strtolower(trim((string) $value));

        if ($value === '' || $value === 'none' || $value === 'exact') {
            return 'none';
        }
        if ($value === 'up' || $value === 'whole' || $value === '.00' || $value === '0.00') {
            return 'up';
        }
        if ($value === '.95' || $value === '0.95' || $value === '95') {
            return '.95';
        }
        if ($value === '.99' || $value === '0.99' || $value === '99') {
            return '.99';
        }

        $where = $number ? 'Markup rules, line ' . $number . ': ' : 'Rounding: ';
        throw new PorkbunPricingException(
            $where . '"' . $value . '" is not a rounding mode. Use none, up, .95 or .99.'
        );
    }

    /**
     * @param string $value
     * @param string $label
     * @param int    $number
     * @return float
     * @throws PorkbunPricingException
     */
    protected static function number($value, $label, $number)
    {
        $value = trim((string) $value);

        if ($value === '' || !is_numeric($value)) {
            throw new PorkbunPricingException(
                'Markup rules, line ' . $number . ': "' . $value . '" is not a number for the ' . $label . '.'
            );
        }

        return (float) $value;
    }

    /**
     * Read one of the numeric configuration fields. Blank means "not set",
     * which is different from zero.
     *
     * @param array  $config
     * @param string $field
     * @param string $label
     * @return float|null
     * @throws PorkbunPricingException
     */
    protected static function configNumber(array $config, $field, $label)
    {
        $value = isset($config[$field]) ? trim((string) $config[$field]) : '';

        if ($value === '') {
            return null;
        }

        $value = str_replace('%', '', $value);

        if (!is_numeric($value)) {
            throw new PorkbunPricingException('"' . $label . '" must be a number, not "' . $value . '".');
        }

        return (float) $value;
    }
}
