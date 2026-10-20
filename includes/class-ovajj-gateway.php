<?php
/**
 * Ovajj WooCommerce gateway.
 *
 * An order is completed ONLY when: the webhook signature is valid, the per-order token on the
 * ipn_url is valid, the environment matches, the webhook says "completed", an independent
 * GET /verify-payment says "success", and currency + amount match the WooCommerce order.
 * Reaching the thank-you page never completes an order.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ovajj_WC_Gateway extends WC_Payment_Gateway
{
    /** @var Ovajj_WC_API */
    private $api;

    /** @var WC_Logger|null */
    private static $logger = null;

    public function __construct()
    {
        $this->id                 = 'ovajj';
        $this->method_title       = 'Ovajj Payment Gateway';
        $this->method_description = 'Accept payments through Ovajj hosted checkout. Version ' . OVAJJ_WC_VERSION . '.';
        $this->has_fields         = false;
        $this->supports           = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        // Fixed customer-facing text (no optional fields to maintain).
        $this->title       = 'Ovajj Payment Gateway';
        $this->description = 'You will be redirected to Ovajj to complete your payment securely.';
        $processing = Ovajj_WC_API::resolve_processing_currency($this->settings);
        if ($processing !== strtoupper(get_woocommerce_currency())) {
            $this->description .= ' Your order total will be converted and charged in ' . $processing . ' at the current exchange rate.';
        }
        $this->enabled     = $this->get_option('enabled');

        $this->api = new Ovajj_WC_API($this->settings);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_' . $this->id, [$this, 'handle_webhook']); // /wc-api/ovajj/
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Ovajj Payment Gateway',
                'default' => 'no',
            ],
            'environment' => [
                'title'       => 'Environment',
                'type'        => 'select',
                'default'     => 'sandbox',
                'options'     => [
                    'sandbox'    => 'Sandbox (test - no real money)',
                    'production' => 'Live (production)',
                ],
                'description' => 'The credentials below must belong to the selected environment. When you switch, replace all three together.',
                'desc_tip'    => false,
            ],
            'merchant_key' => [
                'title'       => 'Merchant Key',
                'type'        => 'text',
                'description' => 'Ovajj Dashboard → Merchant → Config. Sandbox keys start with test_.',
            ],
            'api_key' => [
                'title' => 'API Key',
                'type'  => 'password',
            ],
            'api_secret' => [
                'title'       => 'API Secret (Client Secret)',
                'type'        => 'password',
                'description' => 'Signs API requests and verifies webhooks. Never exposed to the browser.',
            ],
            'processing_currency' => [
                'title'       => 'Processing Currency',
                'type'        => 'select',
                'default'     => 'USD',
                'options'     => [
                    'USD'  => 'USD',
                    'EUR'  => 'EUR',
                    'GBP'  => 'GBP',
                    'USDT' => 'USDT',
                    'BTC'  => 'BTC',
                    'ETH'  => 'ETH',
                ],
                'description' => 'Currency Ovajj is charged in. Orders in any other currency (any country\'s currency, or BTC, ETH, USDT) are converted to it automatically; the order itself stays in the store currency. The currency must be enabled for your Ovajj merchant with an active wallet and gateway (USD is the safe choice; EUR and GBP are not guaranteed; USDT has 4 letters; BTC/ETH have a 1.00 minimum).',
            ],
            'rate_mode' => [
                'title'       => 'Exchange Rate',
                'type'        => 'select',
                'default'     => 'live',
                'options'     => [
                    'live'  => 'Live rate (free, automatic)',
                    'fixed' => 'Fixed rate',
                ],
                'description' => $this->rate_hint(),
            ],
            'fixed_rate' => [
                'title'       => 'Fixed Rate',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Used only when "Fixed rate" is selected, for orders in the store (base) currency: how many <strong>store-currency</strong> units equal <strong>1 Processing-Currency</strong> unit. Example: processing USD, store BDT, rate 122.50 means 1 USD = 122.50 BDT. Orders in other currencies always use the live rate.',
            ],
            'debug' => [
                'title'       => 'Debug Logging',
                'type'        => 'checkbox',
                'label'       => 'Write detailed diagnostics to WooCommerce → Status → Logs (source: ovajj). Credentials are never logged.',
                'default'     => 'no',
            ],
        ];
    }

    /** Settings-page hint: both rate sources with their live feeds. Never makes a network call. */
    private function rate_hint()
    {
        $hint = 'Live rates are cached for 1 hour.<br>'
            . '<strong>USD, EUR, GBP and all other fiat currencies:</strong> '
            . '<a href="https://www.exchangerate-api.com" target="_blank" rel="noopener">Rates By Exchange Rate API</a> '
            . '(live feed: <a href="https://open.er-api.com/v6/latest/USD" target="_blank" rel="noopener">open.er-api.com/v6/latest/USD</a>)<br>'
            . '<strong>BTC, ETH and USDT:</strong> '
            . '<a href="https://www.coinbase.com/price" target="_blank" rel="noopener">Coinbase</a> '
            . '(live feed: <a href="https://api.coinbase.com/v2/prices/BTC-USD/spot" target="_blank" rel="noopener">api.coinbase.com/v2/prices/BTC-USD/spot</a>)';

        // Read the saved option directly: this runs while the form fields are still being built.
        $saved      = get_option('woocommerce_' . $this->id . '_settings', []);
        $processing = Ovajj_WC_API::resolve_processing_currency(is_array($saved) ? $saved : []);
        $store      = strtoupper(get_woocommerce_currency());
        if ($processing !== $store) {
            $known = Ovajj_WC_Rates::cached($processing, $store);
            $hint .= '<br>' . ($known
                ? 'Last known rate: 1 ' . $processing . ' = ' . Ovajj_WC_API::fmt_rate($known) . ' ' . $store . '.'
                : 'No rate has been fetched yet; it is fetched at the first checkout.');
        }
        return $hint;
    }

    /** Only whole positive numbers/decimals are accepted as a fixed rate. */
    public function validate_fixed_rate_field($key, $value)
    {
        $v = (float) str_replace(',', '.', wc_clean((string) $value));
        return $v > 0 ? (string) $v : '';
    }

    /**
     * Amount and currency actually sent to Ovajj.
     * @return array{amount:float,currency:string,rate:float,mode:string}|WP_Error
     */
    private function get_payment_amount($order)
    {
        $store = strtoupper($order->get_currency());
        $total = (float) $order->get_total();
        $wallet = Ovajj_WC_API::resolve_processing_currency($this->settings);

        if ($wallet === $store) {
            return ['amount' => $total, 'currency' => $store, 'rate' => 1.0, 'mode' => 'none'];
        }

        // Rate meaning: 1 Processing Currency = $rate order currency. A fixed rate only fits the store's
        // base currency; orders in any other currency (multi-currency plugins) use the live rate.
        $use_fixed = ($this->get_option('rate_mode', 'live') === 'fixed') && $store === strtoupper(get_woocommerce_currency());
        if ($use_fixed) {
            $mode = 'fixed';
            $rate = (float) $this->get_option('fixed_rate');
        } else {
            $mode = 'live';
            $rate = Ovajj_WC_Rates::live($wallet, $store);
        }
        if ($rate <= 0) {
            return new WP_Error('ovajj_rate', 'no usable exchange rate for ' . $wallet . '/' . $store . ' (' . $mode . ' mode)');
        }

        return [
            'amount'   => (float) number_format($total / $rate, Ovajj_WC_API::decimals($wallet), '.', ''),
            'currency' => $wallet,
            'rate'     => $rate,
            'mode'     => $mode,
        ];
    }

    public function is_available()
    {
        return parent::is_available() && $this->api->is_configured();
    }

    public function admin_notices()
    {
        if ($this->get_option('enabled') !== 'yes' || !current_user_can('manage_woocommerce')) {
            return;
        }
        if (!$this->api->is_configured()) {
            echo '<div class="notice notice-warning"><p><strong>Ovajj:</strong> Merchant Key, API Key and API Secret are required for the selected environment.</p></div>';
        }
        $w = Ovajj_WC_API::resolve_processing_currency($this->settings);
        if ($w !== strtoupper(get_woocommerce_currency())
            && $this->get_option('rate_mode', 'live') === 'fixed' && (float) $this->get_option('fixed_rate') <= 0) {
            echo '<div class="notice notice-warning"><p><strong>Ovajj:</strong> "Fixed rate" is selected but no Fixed Rate is set. Payments will fail until you enter one.</p></div>';
        }
        if ($this->api->environment() === 'production' && strpos(home_url(), 'https://') !== 0) {
            echo '<div class="notice notice-error"><p><strong>Ovajj:</strong> Live mode requires your site to use HTTPS.</p></div>';
        }
    }

    /* ------------------------------------------------------------------ *
     * Logging
     * ------------------------------------------------------------------ */

    private function log($message, $level = 'info', array $context = [])
    {
        if ($level === 'debug' && $this->get_option('debug') !== 'yes') {
            return;
        }
        if (self::$logger === null) {
            self::$logger = wc_get_logger();
        }
        if ($context) {
            $message .= ' ' . wp_json_encode($context);
        }
        self::$logger->log($level === 'debug' ? 'info' : $level, $message, ['source' => 'ovajj']);
    }

    /* ------------------------------------------------------------------ *
     * Checkout: create the Ovajj session and redirect
     * ------------------------------------------------------------------ */

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'failure'];
        }

        $fail = function ($log_message, array $ctx = []) use ($order) {
            $this->log($log_message, 'error', ['order' => $order->get_id()] + $ctx);
            $order->add_order_note('Ovajj: could not start payment - ' . $log_message);
            $notice = 'The payment could not be started. Please try again or choose another payment method.';
            // Shop managers (only) also see the real reason, to make setup problems easy to diagnose.
            if (current_user_can('manage_woocommerce')) {
                $notice .= ' [Admin only] ' . esc_html($log_message);
            }
            wc_add_notice($notice, 'error');
            return ['result' => 'failure'];
        };

        if (!$this->api->is_configured()) {
            return $fail('gateway credentials are not configured');
        }
        if ($this->api->environment() === 'production' && strpos(home_url(), 'https://') !== 0) {
            return $fail('site must use HTTPS in live mode');
        }

        // Amount/currency sent to Ovajj (converted to the wallet currency when configured).
        $pay = $this->get_payment_amount($order);
        if (is_wp_error($pay)) {
            return $fail($pay->get_error_message());
        }
        $total = $pay['amount'];
        if ($total < Ovajj_WC_API::MIN_AMOUNT) {
            return $fail('payment amount is below the Ovajj minimum of 1.00 ' . $pay['currency']);
        }

        $ref = $this->api->make_ref($order->get_id());
        $payload = [
            'payment_amount'   => (float) Ovajj_WC_API::fmt($total, $pay['currency']),
            'currency_code'    => $pay['currency'],
            'ref_trx'          => $ref,
            'description'      => 'Order #' . $order->get_order_number(),
            'success_redirect' => $this->get_return_url($order),
            'cancel_redirect'  => $order->get_checkout_payment_url(), // lets the customer retry
            'ipn_url'          => add_query_arg(
                ['order' => $order->get_id(), 't' => $this->api->ipn_token($order->get_id())],
                WC()->api_request_url($this->id)
            ),
        ];

        $name = Ovajj_WC_API::clean(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()), 100);
        if ($name !== '') {
            $payload['customer_name'] = $name;
        }
        $email = $order->get_billing_email();
        if ($email && is_email($email) && strlen($email) <= 100) {
            $payload['customer_email'] = $email;
        }

        $res = $this->api->request('POST', '/api/v1/initiate-payment', $payload);
        $this->log('initiate-payment', 'debug', ['order' => $order->get_id(), 'http' => $res['http'], 'error' => $res['error']]);

        if (!$res['ok']) {
            return $fail('Ovajj rejected the request: ' . $res['error']);
        }

        $data = $res['data'];
        $info = (isset($data['info']) && is_array($data['info'])) ? $data['info'] : [];
        $url = (isset($data['payment_url']) && is_string($data['payment_url'])) ? trim($data['payment_url']) : '';
        $parts = $url !== '' ? wp_parse_url($url) : false;

        // The API echoes the request in "info"; refuse to redirect if it does not match what we asked for.
        $echo_ok = (!isset($info['ref_trx']) || $info['ref_trx'] === $ref)
            && (!isset($info['amount']) || Ovajj_WC_API::units($info['amount'], $pay['currency']) === Ovajj_WC_API::units($total, $pay['currency']))
            && (!isset($info['currency_code']) || strtoupper((string) $info['currency_code']) === $payload['currency_code'])
            && (!isset($info['environment']) || $info['environment'] === $this->api->environment());

        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || !$echo_ok) {
            return $fail('unexpected initiate-payment response');
        }

        // Remember exactly what this session asked for, so the webhook can be checked against it.
        $sessions = $order->get_meta('_ovajj_sessions');
        $sessions = is_array($sessions) ? $sessions : [];
        $sessions[$ref] = [
            'amount'   => Ovajj_WC_API::fmt($total, $pay['currency']),
            'currency' => $pay['currency'],
            'rate'     => $pay['rate'],
            'time'     => time(),
        ];
        $order->update_meta_data('_ovajj_sessions', array_slice($sessions, -10, null, true));
        $order->update_meta_data('_ovajj_ref', $ref);

        $session_note = 'Ovajj checkout session created (' . $this->api->environment() . '): '
            . Ovajj_WC_API::fmt($total, $pay['currency']) . ' ' . $pay['currency'];
        if ($pay['mode'] !== 'none') {
            $session_note .= ' [converted from ' . Ovajj_WC_API::money($order->get_total()) . ' ' . strtoupper($order->get_currency())
                . ' at 1 ' . $pay['currency'] . ' = ' . Ovajj_WC_API::fmt_rate($pay['rate']) . ' ' . strtoupper($order->get_currency())
                . ', ' . $pay['mode'] . ' rate]';
        }
        $order->add_order_note($session_note . '. Awaiting verified payment notification.');
        $order->save();

        return ['result' => 'success', 'redirect' => $url];
    }

    /* ------------------------------------------------------------------ *
     * Webhook (IPN)  -  https://yoursite/wc-api/ovajj/?order=ID&t=TOKEN
     * ------------------------------------------------------------------ */

    private function respond($code, $status)
    {
        wp_send_json(['status' => $status], $code); // sends JSON and exits
    }

    public function handle_webhook()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->respond(405, 'method_not_allowed');
        }
        if (!$this->api->is_configured()) {
            $this->log('webhook received but credentials are not configured', 'error');
            $this->respond(503, 'not_configured');
        }

        // 1. Raw body, byte-exact, size-capped.
        $raw = (string) file_get_contents('php://input', false, null, 0, 65537);
        if (strlen($raw) > 65536) {
            $this->respond(413, 'payload_too_large');
        }

        // 2. Signature first. Nothing from an unauthenticated request is logged except the source IP.
        if (!$this->api->verify_webhook_signature($raw, $_SERVER['HTTP_X_SIGNATURE'] ?? '')) {
            $this->log('webhook signature verification failed', 'warning', [
                'ip' => Ovajj_WC_API::clean($_SERVER['REMOTE_ADDR'] ?? '', 45),
                'env' => $this->api->environment(),
            ]);
            $this->respond(401, 'invalid_signature');
        }

        // 3. Parse.
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            $this->log('malformed webhook JSON (signature valid)', 'error');
            $this->respond(400, 'malformed_payload');
        }
        $data   = $payload['data'];
        $event  = Ovajj_WC_API::clean($payload['event'] ?? '', 50);
        $status = strtolower(Ovajj_WC_API::clean($payload['status'] ?? '', 30));

        // 4. Order binding through the token we put on our own ipn_url.
        $order_id = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $token    = isset($_GET['t']) ? sanitize_text_field(wp_unslash($_GET['t'])) : '';
        if ($order_id <= 0 || $token === '' || !hash_equals($this->api->ipn_token($order_id), $token)) {
            $this->log('invalid or missing order token on IPN URL', 'warning', ['order' => $order_id]);
            $this->respond(403, 'invalid_token');
        }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            $this->log('IPN for unknown order or order not paid via Ovajj', 'warning', ['order' => $order_id]);
            $this->respond(404, 'order_not_found');
        }

        $trx_id  = $data['trx_id'] ?? ($payload['trx_id'] ?? '');
        $ref_trx = isset($data['ref_trx']) ? (string) $data['ref_trx'] : '';
        $ctx = [
            'order'   => $order_id,
            'event'   => $event,
            'status'  => $status,
            'ref_trx' => Ovajj_WC_API::clean($ref_trx, 80),
            'trx_id'  => is_string($trx_id) ? Ovajj_WC_API::clean($trx_id, 100) : '',
            'amount'  => isset($data['amount']) ? Ovajj_WC_API::clean($data['amount'], 30) : '',
            'currency' => Ovajj_WC_API::clean($data['currency_code'] ?? '', 10),
        ];

        // 5. Environment isolation: sandbox must never settle a live order (and vice-versa).
        $hdr_env  = strtolower(trim((string) ($_SERVER['HTTP_X_ENVIRONMENT'] ?? '')));
        $body_env = strtolower((string) ($data['environment'] ?? ''));
        $mine     = $this->api->environment();
        if (($hdr_env !== '' && $hdr_env !== $mine)
            || ($body_env !== '' && $body_env !== $mine)
            || (isset($data['is_sandbox']) && ((bool) $data['is_sandbox']) !== ($mine === 'sandbox'))) {
            $this->log('environment mismatch - webhook rejected', 'warning', $ctx);
            $this->respond(400, 'environment_mismatch');
        }

        // 6. Replay window; idempotency below covers replays inside it.
        if (isset($payload['timestamp']) && is_numeric($payload['timestamp']) && abs(time() - (int) $payload['timestamp']) > 3600) {
            $this->log('stale webhook timestamp - rejected', 'warning', $ctx);
            $this->respond(400, 'stale_timestamp');
        }

        // 7. A ref_trx that is ours must belong to this same order.
        $ref_order = $this->api->parse_ref($ref_trx);
        if ($ref_order !== null && $ref_order !== $order_id) {
            $this->log('ref_trx belongs to a different order - rejected', 'warning', $ctx);
            $this->respond(400, 'order_mismatch');
        }

        // 8. Non-payment events.
        if ($event === 'payment.refunded' || $status === 'refunded') {
            $order->add_order_note(sprintf(
                'Ovajj refund notice: refund %s, amount %s (informational only).',
                Ovajj_WC_API::clean($data['refund_trx_id'] ?? '-', 100),
                Ovajj_WC_API::clean($data['refunded_amount'] ?? '-', 30)
            ));
            $this->log('refund notice', 'info', $ctx);
            $this->respond(200, 'refund_noted');
        }

        if ($status !== 'completed') {
            if (in_array($status, ['failed', 'cancelled', 'canceled', 'expired'], true)) {
                if ($order->has_status('pending')) {
                    $order->update_status('failed', 'Ovajj reported the payment as ' . $status . '.');
                } else {
                    $order->add_order_note('Ovajj reported a ' . $status . ' payment attempt; order status unchanged.');
                }
            }
            $this->log('non-completed notification - order not paid', 'info', $ctx);
            $this->respond(200, 'noted_' . ($status ?: 'unknown'));
        }

        // 9. status=completed: never trust the webhook alone - verify with Ovajj.
        if (!Ovajj_WC_API::is_valid_trx_id($trx_id)) {
            $this->log('completed webhook has no usable data.trx_id - cannot verify, order NOT paid', 'error', $ctx);
            $order->add_order_note('Ovajj sent a completed notification without a transaction ID. Payment could not be verified; order NOT marked paid.');
            $this->respond(422, 'missing_trx_id');
        }
        if (!is_numeric($data['amount'] ?? null) || empty($data['currency_code'])) {
            $this->log('webhook missing amount/currency', 'error', $ctx);
            $this->respond(400, 'malformed_payload');
        }
        $hook_currency = strtoupper((string) $data['currency_code']);
        $hook_minor    = Ovajj_WC_API::units($data['amount'], $hook_currency); // smallest units (cents, satoshi ...)

        // Cheap duplicate check before spending an API call.
        if ($this->already_recorded($order, $trx_id)) {
            $this->log('duplicate delivery ignored', 'info', $ctx);
            $this->respond(200, 'already_processed');
        }

        $verify = $this->api->request('GET', '/api/v1/verify-payment/' . rawurlencode($trx_id));
        $this->log('verify-payment', 'debug', ['trx' => $ctx['trx_id'], 'http' => $verify['http'], 'error' => $verify['error']]);

        if (!$verify['ok']) {
            $this->log('verification request failed: ' . $verify['error'], 'error', $ctx);
            $this->respond($verify['transient'] ? 503 : 502, 'verification_failed'); // 503 => please retry
        }
        $v = $verify['data'];
        $v_status = strtolower((string) ($v['status'] ?? ''));

        if ($v_status === 'pending') {
            $this->log('verify says still pending', 'info', $ctx);
            $this->respond(503, 'still_pending');
        }
        if ($v_status !== 'success' || !isset($v['trx_id']) || $v['trx_id'] !== $trx_id) {
            $this->log('verification mismatch (status "' . Ovajj_WC_API::clean($v_status, 30) . '") - NOT recording payment', 'error', $ctx);
            $order->add_order_note('Ovajj said completed but verification did not confirm it. Order NOT marked paid.');
            $this->respond(409, 'verification_mismatch');
        }
        if (!empty($v['fully_refunded']) || (isset($v['refunded_amount']) && (float) $v['refunded_amount'] > 0)) {
            $this->log('transaction already refunded at Ovajj - NOT recording payment', 'error', $ctx);
            $this->respond(409, 'already_refunded');
        }

        // 10. Serialise concurrent deliveries for the same order.
        global $wpdb;
        $lock = 'ovajj_order_' . $order_id;
        $got = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 15));
        if ($got !== 1) {
            $this->log('could not obtain order lock', 'error', $ctx);
            $this->respond(503, 'busy');
        }
        $finish = function ($code, $status_text) use ($wpdb, $lock) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            $this->respond($code, $status_text);
        };

        // Reload the order inside the lock so we see the winner of any race.
        clean_post_cache($order_id);
        $order = wc_get_order($order_id);

        if ($this->already_recorded($order, $trx_id)) {
            $this->log('duplicate delivery (concurrent) ignored', 'info', $ctx);
            $finish(200, 'already_processed');
        }

        if (!$order->has_status(['pending', 'failed', 'on-hold'])) {
            $order->add_order_note(sprintf(
                'Ovajj payment %s was received but the order status is "%s". Not applied - review and refund manually if needed.',
                $trx_id,
                $order->get_status()
            ));
            $this->log('order not payable - payment not applied', 'warning', $ctx + ['order_status' => $order->get_status()]);
            $finish(200, 'order_not_payable');
        }

        // Expected payment = the Ovajj session this payment belongs to. Amount and currency are whatever we
        // asked Ovajj for (possibly converted to the wallet currency) and were recorded at session creation.
        $order_currency = strtoupper($order->get_currency());
        $sessions = $order->get_meta('_ovajj_sessions');
        $expected = null;
        if (is_array($sessions) && $sessions) {
            if (isset($sessions[$ref_trx]) && is_array($sessions[$ref_trx])) {
                $expected = $sessions[$ref_trx];
            } else {
                foreach ($sessions as $sess) {
                    if (is_array($sess)
                        && strtoupper((string) ($sess['currency'] ?? '')) === $hook_currency
                        && Ovajj_WC_API::units($sess['amount'] ?? 0, $sess['currency']) === $hook_minor) {
                        $expected = $sess;
                        break;
                    }
                }
            }
        } else {
            // Orders created before v1.1 have no session record: the order itself is the expectation.
            $expected = ['amount' => $order->get_total(), 'currency' => $order_currency, 'rate' => 1.0];
        }
        if (!$expected) {
            $order->add_order_note('Ovajj payment rejected: it does not match any payment session created for this order.');
            $this->log('no matching payment session', 'error', $ctx);
            $finish(409, 'session_mismatch');
        }
        $exp_currency = strtoupper((string) $expected['currency']);
        $due_minor    = Ovajj_WC_API::units($expected['amount'], $exp_currency);

        // Currency: webhook and verify must both equal the expected session currency.
        $v_currency = strtoupper((string) ($v['currency'] ?? ''));
        if ($hook_currency !== $exp_currency || $v_currency !== $exp_currency) {
            $order->add_order_note('Ovajj payment rejected: currency mismatch (expected ' . $exp_currency . ', Ovajj ' . $hook_currency . '/' . $v_currency . ').');
            $this->log('currency mismatch', 'error', $ctx);
            $finish(409, 'currency_mismatch');
        }

        // Webhook "amount" is the gross charge. verify "amount" may be reported net of the fee
        // (docs example: amount 237.5 + fee 12.5 for a 250 payment), so accept amount or amount+fee,
        // with a 1-cent tolerance because Ovajj rounds the fee and net amount separately.
        $v_amount = (isset($v['amount']) && is_numeric($v['amount'])) ? (float) $v['amount'] : null;
        $v_fee    = (isset($v['fee']) && is_numeric($v['fee']) && (float) $v['fee'] >= 0) ? (float) $v['fee'] : 0.0;

        $hook_ok   = ($hook_minor === $due_minor);
        $verify_ok = $v_amount !== null
            && (abs(Ovajj_WC_API::units($v_amount, $exp_currency) - $due_minor) <= 1
                || abs(Ovajj_WC_API::units($v_amount + $v_fee, $exp_currency) - $due_minor) <= 1);

        if (!$hook_ok || !$verify_ok) {
            $order->add_order_note('Ovajj payment rejected: amount mismatch (expected ' . Ovajj_WC_API::fmt($expected['amount'], $exp_currency) . ' ' . $exp_currency . ').');
            $this->log('amount mismatch', 'error', $ctx + ['expected' => Ovajj_WC_API::fmt($expected['amount'], $exp_currency), 'verify_amount' => $v_amount, 'verify_fee' => $v_fee]);
            $finish(409, 'amount_mismatch');
        }

        // 11. Verified: complete the order.
        $order->update_meta_data('_ovajj_trx_id', $trx_id);
        $order->update_meta_data('_ovajj_pay_amount', Ovajj_WC_API::fmt($expected['amount'], $exp_currency));
        $order->update_meta_data('_ovajj_pay_currency', $exp_currency);
        $order->update_meta_data('_ovajj_rate', (string) (float) ($expected['rate'] ?? 1));
        if ($v_fee > 0) {
            $order->update_meta_data('_ovajj_fee', Ovajj_WC_API::fmt($v_fee, $exp_currency));
        }
        $order->payment_complete($trx_id);

        $conv_note = '';
        if ($exp_currency !== $order_currency) {
            $conv_note = sprintf(
                ' Charged %s %s at 1 %s = %s %s.',
                Ovajj_WC_API::fmt($expected['amount'], $exp_currency),
                $exp_currency,
                $exp_currency,
                Ovajj_WC_API::fmt_rate((float) ($expected['rate'] ?? 0)),
                $order_currency
            );
        }
        $order->add_order_note(sprintf(
            'Ovajj payment verified and recorded. Transaction %s (%s).%s',
            $trx_id,
            $mine,
            $conv_note
        ));
        $this->log('payment verified and recorded', 'info', $ctx);

        $finish(200, 'processed');
    }

    /** True if this order is already paid with this transaction, or the transaction ID belongs to any order. */
    private function already_recorded($order, $trx_id)
    {
        if ($order->get_meta('_ovajj_trx_id') === $trx_id) {
            return true;
        }
        $found = wc_get_orders([
            'transaction_id' => $trx_id,
            'limit'          => 1,
            'return'         => 'ids',
        ]);
        return !empty($found);
    }

    /* ------------------------------------------------------------------ *
     * Refunds (WooCommerce → Orders → Refund)
     * ------------------------------------------------------------------ */

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('ovajj_refund', 'Order not found.');
        }
        if (!$this->api->is_configured()) {
            return new WP_Error('ovajj_refund', 'Ovajj credentials are not configured.');
        }

        $trx_id = $order->get_transaction_id();
        if (!Ovajj_WC_API::is_valid_trx_id($trx_id)) {
            return new WP_Error('ovajj_refund', 'This order has no valid Ovajj transaction ID.');
        }

        $amount = (float) $amount;
        if ($amount <= 0) {
            return new WP_Error('ovajj_refund', 'Invalid refund amount.');
        }

        // If the order was charged in a different (wallet) currency, refund in that currency at the
        // rate the customer actually paid. A full refund returns exactly what was charged.
        $refund_amount = $amount;
        $refund_cur = strtoupper($order->get_currency());
        $paid_cur  = strtoupper((string) $order->get_meta('_ovajj_pay_currency'));
        $paid_amt  = (float) $order->get_meta('_ovajj_pay_amount');
        $paid_rate = (float) $order->get_meta('_ovajj_rate');
        if ($paid_cur !== '' && $paid_cur !== $refund_cur) {
            if ($paid_rate <= 0 || $paid_amt <= 0) {
                return new WP_Error('ovajj_refund', 'Cannot convert the refund: the original conversion rate is missing.');
            }
            $refund_amount = (Ovajj_WC_API::minor($amount) === Ovajj_WC_API::minor($order->get_total()))
                ? $paid_amt
                : min($paid_amt, (float) number_format($amount / $paid_rate, Ovajj_WC_API::decimals($paid_cur), '.', ''));
            $refund_cur = $paid_cur;
        }

        // Idempotent: same transaction + amount within the same clock hour can never refund twice.
        $payload = [
            'trx_id'     => $trx_id,
            'amount'     => (float) Ovajj_WC_API::fmt($refund_amount, $refund_cur),
            'reason'     => Ovajj_WC_API::clean($reason !== '' ? $reason : 'Refund for order #' . $order->get_order_number(), 200),
            'ref_refund' => 'WC-' . substr(hash('sha256', $trx_id . '|' . number_format($refund_amount, 8, '.', '') . '|' . gmdate('YmdH')), 0, 32),
        ];

        $res = $this->api->request('POST', '/api/v1/refund-payment', $payload);
        $this->log('refund-payment', 'debug', ['order' => $order_id, 'http' => $res['http'], 'error' => $res['error']]);

        $data = $res['data'];
        if ($res['ok'] && is_array($data) && ($data['status'] ?? '') === 'success') {
            $order->add_order_note(sprintf(
                'Ovajj refund of %s recorded (refund transaction %s). Ovajj returns the money to the buyer; the original processing fee is not refunded.',
                Ovajj_WC_API::fmt($refund_amount, $refund_cur) . ' ' . $refund_cur,
                Ovajj_WC_API::clean($data['refund_trx_id'] ?? '-', 100)
            ));
            $this->log('refund succeeded', 'info', ['order' => $order_id]);
            return true;
        }

        $this->log('refund failed: ' . $res['error'], 'error', ['order' => $order_id]);
        return new WP_Error('ovajj_refund', 'Ovajj refund failed: ' . ($res['error'] ?: 'unknown error'));
    }
}
