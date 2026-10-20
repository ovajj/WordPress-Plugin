<?php
/**
 * Ovajj API client + shared helpers.
 *
 * Only documented behaviour is used:
 *   - Headers: X-Environment, X-Merchant-Key, X-API-Key, X-Timestamp, X-Signature
 *   - Signature: sha256= + HMAC-SHA256("timestamp.METHOD.path_with_query.raw_body", API secret)
 *   - POST /api/v1/initiate-payment, GET /api/v1/verify-payment/{trxId}, POST /api/v1/refund-payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ovajj_WC_API
{
    const BASE_URL = 'https://ovajj.com';
    const MIN_AMOUNT = 1.00;

    /** Dropdown values for the Processing Currency setting (in display order). */
    const PROCESSING_CURRENCIES = ['USD', 'EUR', 'GBP', 'USDT', 'BTC', 'ETH'];
    const DEFAULT_PROCESSING    = 'USD';

    private $environment;
    private $merchant_key;
    private $api_key;
    private $api_secret;

    public function __construct(array $settings)
    {
        $this->environment  = (isset($settings['environment']) && $settings['environment'] === 'production') ? 'production' : 'sandbox';
        $this->merchant_key = trim((string) ($settings['merchant_key'] ?? ''));
        $this->api_key      = trim((string) ($settings['api_key'] ?? ''));
        $this->api_secret   = trim((string) ($settings['api_secret'] ?? ''));
    }

    public function environment()
    {
        return $this->environment;
    }

    public function secret()
    {
        return $this->api_secret;
    }

    public function is_configured()
    {
        return $this->merchant_key !== '' && $this->api_key !== '' && $this->api_secret !== '';
    }

    /* ---------------- helpers ---------------- */

    public static function money($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** Integer minor units for exact comparison. */
    public static function minor($amount)
    {
        return (int) round(((float) $amount) * 100);
    }

    /** Decimal places used for amounts in a currency (BTC and ETH need 8, everything else 2). */
    public static function decimals($currency)
    {
        return in_array(strtoupper((string) $currency), ['BTC', 'ETH'], true) ? 8 : 2;
    }

    /** Amount formatted with the currency's precision. */
    public static function fmt($amount, $currency)
    {
        return number_format((float) $amount, self::decimals($currency), '.', '');
    }

    /** Integer smallest units (cents, satoshi ...) for exact, currency-aware comparison. */
    public static function units($amount, $currency)
    {
        return (int) round(((float) $amount) * pow(10, self::decimals($currency)));
    }

    /** Human-readable exchange rate (tiny crypto rates need more digits). */
    public static function fmt_rate($rate)
    {
        $rate = (float) $rate;
        return rtrim(rtrim(number_format($rate, $rate < 1 ? 12 : 6, '.', ''), '0'), '.');
    }

    /**
     * The Processing Currency from saved settings. A value saved by plugin 1.1 ("wallet_currency")
     * is kept if it is one of the dropdown currencies; otherwise USD.
     */
    public static function resolve_processing_currency(array $settings)
    {
        $v = strtoupper((string) ($settings['processing_currency'] ?? ''));
        if (!in_array($v, self::PROCESSING_CURRENCIES, true)) {
            $old = strtoupper((string) ($settings['wallet_currency'] ?? ''));
            $v = in_array($old, self::PROCESSING_CURRENCIES, true) ? $old : self::DEFAULT_PROCESSING;
        }
        return $v;
    }

    public static function clean($value, $max = 200)
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value);
        return mb_substr((string) $value, 0, $max);
    }

    public static function is_valid_trx_id($id)
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_\-]{4,100}$/', $id) === 1;
    }

    /** Unique, unforgeable ref_trx (max 60 chars): WC{orderId}-{nonce}-{hmac12}. */
    public function make_ref($order_id)
    {
        $nonce = dechex(time()) . bin2hex(random_bytes(3));
        $sig = substr(hash_hmac('sha256', $order_id . '|' . $nonce, $this->api_secret), 0, 12);
        return 'WC' . (int) $order_id . '-' . $nonce . '-' . $sig;
    }

    /** Order ID encoded in one of our refs, or null when the ref is not (validly) ours. */
    public function parse_ref($ref)
    {
        if (!is_string($ref) || !preg_match('/^WC(\d+)-([a-f0-9]+)-([a-f0-9]{12})$/', $ref, $m)) {
            return null;
        }
        $expected = substr(hash_hmac('sha256', $m[1] . '|' . $m[2], $this->api_secret), 0, 12);
        return hash_equals($expected, $m[3]) ? (int) $m[1] : null;
    }

    /** Token on our ipn_url: a callback can only ever act on the order it was created for. */
    public function ipn_token($order_id)
    {
        return substr(hash_hmac('sha256', 'ovajj-ipn|' . (int) $order_id, $this->api_secret), 0, 32);
    }

    /** Verifies a webhook X-Signature (hex HMAC-SHA256 of the raw body, optional "sha256=" prefix). */
    public function verify_webhook_signature($raw_body, $header)
    {
        $provided = strtolower(trim((string) $header));
        if (strpos($provided, 'sha256=') === 0) {
            $provided = substr($provided, 7);
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $provided) || $this->api_secret === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $raw_body, $this->api_secret), $provided);
    }

    /* ---------------- HTTP ---------------- */

    /**
     * @return array{ok:bool,http:int,data:?array,error:?string,transient:bool}
     */
    public function request($method, $path, $payload = null)
    {
        $result = ['ok' => false, 'http' => 0, 'data' => null, 'error' => null, 'transient' => false];

        $method = strtoupper($method);
        $body = '';
        if ($payload !== null) {
            $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                $result['error'] = 'Could not encode request body.';
                return $result;
            }
        }

        // Exactly the string that is signed is the string that is sent.
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $method . '.' . $path . '.' . $body, $this->api_secret);

        $headers = [
            'Accept'         => 'application/json',
            'X-Environment'  => $this->environment,
            'X-Merchant-Key' => $this->merchant_key,
            'X-API-Key'      => $this->api_key,
            'X-Timestamp'    => $timestamp,
            'X-Signature'    => 'sha256=' . $signature,
        ];
        $args = [
            'method'      => $method,
            'timeout'     => 30,
            'redirection' => 0,
            'sslverify'   => true,
            'headers'     => $headers,
        ];
        if ($method === 'POST') {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $body;
        }

        $response = wp_remote_request(self::BASE_URL . $path, $args);

        if (is_wp_error($response)) {
            $result['error'] = 'Network error: ' . self::clean($response->get_error_message());
            $result['transient'] = true;
            return $result;
        }

        $http = (int) wp_remote_retrieve_response_code($response);
        $result['http'] = $http;
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($data)) {
            $result['error'] = 'Invalid (non-JSON) response from Ovajj, HTTP ' . $http;
            $result['transient'] = ($http >= 500 || $http === 429);
            return $result;
        }
        $result['data'] = $data;

        if ($http >= 200 && $http < 300 && !(array_key_exists('success', $data) && $data['success'] === false)) {
            $result['ok'] = true;
            return $result;
        }

        $msg = $data['message'] ?? ($data['error'] ?? 'Request failed');
        if (is_array($msg)) {
            $msg = wp_json_encode($msg);
        }
        $code = isset($data['error_code']) ? ' [' . self::clean($data['error_code'], 50) . ']' : '';
        $result['error'] = 'HTTP ' . $http . $code . ': ' . self::clean($msg);
        $result['transient'] = ($http === 429 || $http >= 500);

        return $result;
    }
}
