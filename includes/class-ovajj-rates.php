<?php
/**
 * Exchange rates for the currency conversion (order currency -> Processing Currency).
 *
 * Two free public sources, no API keys, both linked on the settings page:
 *   - Fiat (every country's currency): open.er-api.com. Attribution ("Rates By Exchange Rate API") is
 *     required, caching is allowed, abusive clients are blocked for 20 minutes (HTTP 429).
 *   - Crypto (BTC, ETH, USDT): Coinbase public spot price, https://api.coinbase.com/v2/prices/{SYM}-USD/spot
 *
 * Rate meaning used everywhere in this plugin:  1 {processing currency} = {rate} {order currency}.
 * Anything involving a crypto currency is resolved through USD.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ovajj_WC_Rates
{
    const FIAT_API   = 'https://open.er-api.com/v6/latest/';
    const CRYPTO_API = 'https://api.coinbase.com/v2/prices/';
    const CRYPTO     = ['BTC', 'ETH', 'USDT'];
    const TTL        = 3600;    // fresh cache: 1 hour
    const STALE_MAX  = 172800;  // last known rate may be used for up to 48 h if a provider is unreachable

    private static function key($base, $quote)
    {
        return 'ovajj_fx_' . strtolower($base . '_' . $quote);
    }

    /** Last known rate (fresh or stale) WITHOUT any network call - safe for the admin settings page. */
    public static function cached($base, $quote)
    {
        $key = self::key(strtoupper($base), strtoupper($quote));
        $fresh = get_transient($key);
        if (is_numeric($fresh) && (float) $fresh > 0) {
            return (float) $fresh;
        }
        $last = get_option($key . '_last');
        if (is_array($last) && !empty($last['rate']) && (float) $last['rate'] > 0) {
            return (float) $last['rate'];
        }
        return null;
    }

    /**
     * Rate to use for a payment. Returns 0.0 when no usable rate exists - the caller must then refuse
     * the payment instead of guessing.
     */
    public static function live($base, $quote)
    {
        $base  = strtoupper($base);
        $quote = strtoupper($quote);
        $key   = self::key($base, $quote);

        $fresh = get_transient($key);
        if (is_numeric($fresh) && (float) $fresh > 0) {
            return (float) $fresh;
        }

        $rate = self::fetch_rate($base, $quote);
        if ($rate > 0) {
            set_transient($key, $rate, self::TTL);
            update_option($key . '_last', ['rate' => $rate, 'time' => time()], false);
            return $rate;
        }

        // Providers unavailable or backing off: last known rate, but not an old one.
        $last = get_option($key . '_last');
        if (is_array($last) && !empty($last['rate']) && (time() - (int) ($last['time'] ?? 0)) <= self::STALE_MAX) {
            return (float) $last['rate'];
        }
        return 0.0;
    }

    /** 1 {base} = X {quote} for any combination of fiat currencies and BTC / ETH / USDT. */
    private static function fetch_rate($base, $quote)
    {
        if (!in_array($base, self::CRYPTO, true) && !in_array($quote, self::CRYPTO, true)) {
            return self::fetch_fiat($base, $quote);
        }
        $u_base = self::usd_per_unit($base);
        if ($u_base <= 0) {
            return 0.0;
        }
        $u_quote = self::usd_per_unit($quote);
        return $u_quote > 0 ? $u_base / $u_quote : 0.0;
    }

    /** Value of one unit of a currency in USD (0.0 when unknown). */
    private static function usd_per_unit($currency)
    {
        if ($currency === 'USD') {
            return 1.0;
        }
        if (in_array($currency, self::CRYPTO, true)) {
            return self::fetch_crypto_usd($currency);
        }
        $fx = self::fetch_fiat('USD', $currency); // 1 USD = $fx {currency}
        return $fx > 0 ? 1.0 / $fx : 0.0;
    }

    private static function fetch_fiat($base, $quote)
    {
        if (get_transient('ovajj_fx_backoff_fx')) {
            return 0.0;
        }
        $response = wp_remote_get(self::FIAT_API . rawurlencode($base), [
            'timeout' => 10, 'redirection' => 0, 'sslverify' => true,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            set_transient('ovajj_fx_backoff_fx', 1, 300);
            return 0.0;
        }
        $http = (int) wp_remote_retrieve_response_code($response);
        if ($http === 429) {
            set_transient('ovajj_fx_backoff_fx', 1, 1260); // provider blocks for 20 minutes
            return 0.0;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($http !== 200 || !is_array($data)
            || ($data['result'] ?? '') !== 'success'
            || strtoupper((string) ($data['base_code'] ?? '')) !== $base) {
            set_transient('ovajj_fx_backoff_fx', 1, 300);
            return 0.0;
        }
        $rate = $data['rates'][$quote] ?? null;
        if (!is_numeric($rate) || (float) $rate <= 0 || (float) $rate > 1.0E+15) {
            return 0.0; // currency not listed by the provider: no rate, no payment (never guess)
        }
        return (float) $rate;
    }

    private static function fetch_crypto_usd($symbol)
    {
        if (get_transient('ovajj_fx_backoff_cb')) {
            return 0.0;
        }
        $response = wp_remote_get(self::CRYPTO_API . rawurlencode($symbol) . '-USD/spot', [
            'timeout' => 10, 'redirection' => 0, 'sslverify' => true,
            'headers' => ['Accept' => 'application/json'],
        ]);
        $amount = null;
        $http = 0;
        if (!is_wp_error($response)) {
            $http = (int) wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $amount = (is_array($data) && isset($data['data']['amount'])) ? $data['data']['amount'] : null;
        }
        if ($http !== 200 || !is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 1.0E+12) {
            set_transient('ovajj_fx_backoff_cb', 1, 300);
            return 0.0;
        }
        return (float) $amount;
    }
}
