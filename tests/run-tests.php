<?php
/**
 * Dependency-free test runner (no WordPress, no PHPUnit, no network).
 * Usage:  php tests/run-tests.php
 *
 * It stubs the few WordPress/WooCommerce functions the plugin core uses and mocks every HTTP call.
 * No real payments are ever made.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'OVAJJ_PG_VERSION', 'test' );
define( 'HOUR_IN_SECONDS', 3600 );

/* ---------- WordPress / WooCommerce stubs ---------- */
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s ) { return $s; }
function apply_filters( $tag, $v ) { return $v; }
function get_option( $k, $d = false ) { global $OPTS; return $OPTS[ $k ] ?? $d; }
function update_option( $k, $v ) { global $OPTS; $OPTS[ $k ] = $v; return true; }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function wp_parse_url( $u ) { return parse_url( $u ); }
function wp_remote_request( $url, $args ) { global $HTTP; $HTTP['calls'][] = array( $url, $args ); return array_shift( $HTTP['queue'] ); }
function wp_remote_get( $url, $args = array() ) { return wp_remote_request( $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_schedule_single_event() { global $SCHEDULED; $SCHEDULED[] = func_get_args(); return true; }
function wc_get_price_decimals() { return 2; }
function wc_get_logger() { return new class { public function log() {} }; }
function wc_get_order( $id ) { global $ORDERS; return $ORDERS[ $id ] ?? false; }
function wc_create_refund( $a ) { global $ORDERS; $ORDERS[ $a['order_id'] ]->refunded += $a['amount']; return new stdClass(); }

class WC_Order {
	public $id; public $status = 'pending'; public $meta = array(); public $notes = array(); public $total = '250.00'; public $currency = 'USD';
	public $completed = 0; public $refunded = 0;
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() {}
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
	public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
	public function update_status( $s, $note = '' ) { $this->status = $s; $this->notes[] = $note; }
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function payment_complete( $t = '' ) { $this->status = 'processing'; $this->completed++; }
	public function get_remaining_refund_amount() { return (float) $this->total - $this->refunded; }
	public function get_total_refunded() { return $this->refunded; }
}

require __DIR__ . '/../includes/class-ovajj-settings.php';
require __DIR__ . '/../includes/class-ovajj-logger.php';
require __DIR__ . '/../includes/class-ovajj-utils.php';
require __DIR__ . '/../includes/class-ovajj-currency.php';
require __DIR__ . '/../includes/class-ovajj-api.php';
require __DIR__ . '/../includes/class-ovajj-processor.php';
require __DIR__ . '/../includes/class-ovajj-webhook.php';

/** Processor without the database lock. */
class Test_Processor extends Ovajj_Processor {
	public function acquire_lock( int $order_id ): bool { return true; }
	public function release_lock( int $order_id ): void {}
}

/* ---------- tiny assertion helpers ---------- */
$pass = 0; $fail = 0;
function t( $name, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name\n"; } }
function json_resp( $code, $arr ) { return array( 'code' => $code, 'body' => json_encode( $arr ) ); }

$SECRET = 'sandbox-secret-xyz';
function reset_state() {
	global $OPTS, $HTTP, $ORDERS, $SCHEDULED, $SECRET;
	$OPTS = array( 'woocommerce_ovajj_settings' => array(
		'environment' => 'sandbox',
		'sandbox_merchant_key' => 'test_mk', 'sandbox_api_key' => 'test_ak', 'sandbox_api_secret' => $SECRET,
		'production_merchant_key' => 'mk', 'production_api_key' => 'ak', 'production_api_secret' => 'prod-secret',
	) );
	$HTTP = array( 'calls' => array(), 'queue' => array() );
	$SCHEDULED = array();
	$o = new WC_Order( 77 );
	$o->meta['_ovajj_attempts'] = array( 'WC-77-ABCDEF1234' => array( 'environment' => 'sandbox', 'amount' => 250.0, 'currency' => 'USD' ) );
	$o->meta['_ovajj_ref_trx'] = 'WC-77-ABCDEF1234';
	$o->meta['_ovajj_environment'] = 'sandbox';
	$ORDERS = array( 77 => $o );
	return $o;
}
function ipn( $over = array(), $dataOver = array() ) {
	$data = array_merge( array( 'ref_trx' => 'WC-77-ABCDEF1234', 'amount' => 250, 'currency_code' => 'USD', 'environment' => 'sandbox', 'is_sandbox' => true ), $dataOver );
	return json_encode( array_merge( array( 'data' => $data, 'message' => 'Payment Completed', 'status' => 'completed', 'timestamp' => 1 ), $over ) );
}
function send( $raw, $secret = null, $env = 'sandbox', $prefix = '' ) {
	global $SECRET;
	$secret = $secret ?? $SECRET;
	$sig    = $prefix . hash_hmac( 'sha256', $raw, $secret );
	$wh     = new Ovajj_Webhook( new Test_Processor() );
	return $wh->process( $raw, $sig, $env );
}

/* ---------- 1. Signing ---------- */
echo "Signing / API client\n";
$expected = 'sha256=' . hash_hmac( 'sha256', '1778650000.POST./api/v1/initiate-payment.{"a":1}', 'k' );
t( 'signature format timestamp.METHOD.path.body with sha256= prefix', Ovajj_API::sign( '1778650000', 'post', '/api/v1/initiate-payment', '{"a":1}', 'k' ) === $expected );
t( 'GET signature uses empty body', Ovajj_API::sign( '1', 'GET', '/api/v1/verify-payment/T1', '', 'k' ) === 'sha256=' . hash_hmac( 'sha256', '1.GET./api/v1/verify-payment/T1.', 'k' ) );

reset_state();
$api = Ovajj_API::from_settings( 'sandbox' );
$HTTP['queue'][] = json_resp( 200, array( 'payment_url' => 'https://ovajj.com/payment/checkout?token=a', 'info' => array() ) );
$r = $api->initiate_payment( array( 'payment_amount' => 250.0, 'currency_code' => 'USD', 'ref_trx' => 'X', 'success_redirect' => 'https://s.test/a/b' ) );
list( $url, $args ) = $HTTP['calls'][0];
t( 'initiate hits /api/v1/initiate-payment', $url === 'https://ovajj.com/api/v1/initiate-payment' );
t( 'sandbox headers present', $args['headers']['X-Environment'] === 'sandbox' && $args['headers']['X-Merchant-Key'] === 'test_mk' && $args['headers']['X-API-Key'] === 'test_ak' );
t( 'signature matches the exact body that was sent', $args['headers']['X-Signature'] === Ovajj_API::sign( $args['headers']['X-Timestamp'], 'POST', '/api/v1/initiate-payment', $args['body'], $SECRET ) );
t( 'body keeps slashes unescaped (signed bytes == sent bytes)', strpos( $args['body'], 'https://s.test/a/b' ) !== false );
t( 'secret is never sent as a header', ! in_array( $SECRET, $args['headers'], true ) );

$HTTP['queue'][] = json_resp( 200, array( 'status' => 'success', 'trx_id' => 'T1' ) );
$api->verify_payment( 'T1' );
list( , $args ) = $HTTP['calls'][1];
t( 'verify GET signed with empty body and has no body', $args['headers']['X-Signature'] === Ovajj_API::sign( $args['headers']['X-Timestamp'], 'GET', '/api/v1/verify-payment/T1', '', $SECRET ) && ! isset( $args['body'] ) );

$HTTP['queue'][] = json_resp( 422, array( 'success' => false, 'message' => 'Currency is not enabled for this merchant.', 'error_code' => 'INVALID_CURRENCY' ) );
$e = $api->initiate_payment( array( 'x' => 1 ) );
t( '422 becomes WP_Error with friendly customer message', is_wp_error( $e ) && stripos( Ovajj_API::customer_message( $e ), 'currency' ) !== false && stripos( Ovajj_API::customer_message( $e ), 'Enabled for this merchant' ) === false );
$HTTP['queue'][] = array( 'code' => 200, 'body' => '<html>nope</html>' );
t( 'malformed JSON handled', is_wp_error( $api->site_info() ) );
$HTTP['queue'][] = new WP_Error( 'http_request_failed', 'timeout' );
$e = $api->site_info();
t( 'transport timeout flagged transient', is_wp_error( $e ) && ! empty( $e->get_error_data()['transient'] ) );
$HTTP['queue'][] = json_resp( 429, array( 'message' => 'slow down' ) );
$e = $api->site_info();
t( '429 flagged transient', ! empty( $e->get_error_data()['transient'] ) );
$bad = new Ovajj_API( 'sandbox', '', '', '' );
t( 'missing credentials refused before any request', is_wp_error( $bad->site_info() ) );

/* ---------- 2. Utils & logger ---------- */
echo "Utils / logger\n";
$ref = Ovajj_Utils::generate_ref( 123, 'WC' );
t( 'ref length <= 60 and parseable', strlen( $ref ) <= 60 && Ovajj_Utils::order_id_from_ref( $ref ) === 123 );
t( 'refs are unique', Ovajj_Utils::generate_ref( 1 ) !== Ovajj_Utils::generate_ref( 1 ) );
t( 'amount compare in minor units', Ovajj_Utils::amounts_match( 250, '250.00' ) && ! Ovajj_Utils::amounts_match( 250, 249.99 ) && ! Ovajj_Utils::amounts_match( null, 1 ) );
t( 'checkout URL: https on api host ok', Ovajj_Utils::is_valid_checkout_url( 'https://ovajj.com/payment/checkout?t=1', 'https://ovajj.com' ) );
t( 'checkout URL: http rejected', ! Ovajj_Utils::is_valid_checkout_url( 'http://ovajj.com/x', 'https://ovajj.com' ) );
t( 'checkout URL: foreign host rejected', ! Ovajj_Utils::is_valid_checkout_url( 'https://evil.example/x', 'https://ovajj.com' ) );
t( 'checkout URL: lookalike host rejected', ! Ovajj_Utils::is_valid_checkout_url( 'https://notovajj.com/x', 'https://ovajj.com' ) );
t( 'checkout URL: userinfo trick rejected', ! Ovajj_Utils::is_valid_checkout_url( 'https://ovajj.com@evil.example/x', 'https://ovajj.com' ) );
$red = Ovajj_Logger::redact( array( 'api_secret' => 'S', 'X-Signature' => 'sha256=abc', 'customer_email' => 'a@b.c', 'payment_url' => 'https://ovajj.com/p?token=SECRET', 'order_id' => 5 ) );
t( 'logger redacts secrets, signatures, emails, URL tokens', $red['api_secret'] === '[redacted]' && $red['X-Signature'] === '[redacted]' && $red['customer_email'] === '[redacted]' && strpos( $red['payment_url'], 'SECRET' ) === false && $red['order_id'] === 5 );

/* ---------- 3. Webhook authentication ---------- */
echo "Webhook authentication\n";
reset_state();
$raw = ipn();
t( 'verify_signature accepts raw hex', Ovajj_Webhook::verify_signature( $raw, hash_hmac( 'sha256', $raw, 's' ), 's' ) );
t( 'verify_signature accepts sha256= prefix', Ovajj_Webhook::verify_signature( $raw, 'sha256=' . hash_hmac( 'sha256', $raw, 's' ), 's' ) );
t( 'verify_signature rejects wrong secret / empty', ! Ovajj_Webhook::verify_signature( $raw, hash_hmac( 'sha256', $raw, 'x' ), 's' ) && ! Ovajj_Webhook::verify_signature( $raw, '', 's' ) );
$r = send( $raw, 'wrong-secret' );
t( 'invalid signature -> 401 and order untouched', $r['http'] === 401 && $ORDERS[77]->status === 'pending' );
$r = send( $raw . ' ' );
t( 'tampered body -> 401', ( function () use ( $raw ) { $wh = new Ovajj_Webhook( new Test_Processor() ); global $SECRET; return $wh->process( $raw . ' ', hash_hmac( 'sha256', $raw, $SECRET ), 'sandbox' )['http'] === 401; } )() );
$r = send( ipn( array(), array( 'environment' => 'production' ) ) );
t( 'header/body environment disagreement rejected', $r['http'] === 400 );
$r = send( ipn(), 'prod-secret', 'production' );
t( 'valid production signature on a sandbox attempt -> environment mismatch', $r['http'] === 400 && $r['code'] === 'environment_mismatch' && $ORDERS[77]->status === 'pending' );

/* ---------- 4. Payment lifecycle ---------- */
echo "Payment lifecycle\n";
reset_state();
$r = send( ipn() );
t( 'signed completed IPN completes the order once', $r['http'] === 200 && $ORDERS[77]->completed === 1 && $ORDERS[77]->status === 'processing' );
$r = send( ipn() );
t( 'duplicate IPN is acknowledged and not re-processed', $r['http'] === 200 && $r['code'] === 'duplicate' && $ORDERS[77]->completed === 1 );
$r = send( ipn( array( 'status' => 'failed' ) ) );
t( 'failed IPN cannot overwrite a paid order', $r['http'] === 200 && $ORDERS[77]->status === 'processing' );
$r = send( ipn( array(), array( 'amount' => 250.5 ) ) );
t( 'second different success notice on paid order only adds a review note', $ORDERS[77]->completed === 1 );

reset_state();
$r = send( ipn( array(), array( 'amount' => 100 ) ) );
t( 'amount mismatch -> 422, on-hold, not paid', $r['http'] === 422 && $ORDERS[77]->status === 'on-hold' && $ORDERS[77]->completed === 0 );
reset_state();
$r = send( ipn( array(), array( 'currency_code' => 'EUR' ) ) );
t( 'currency mismatch -> 422, not paid', $r['http'] === 422 && $ORDERS[77]->completed === 0 );
reset_state();
$r = send( ipn( array(), array( 'ref_trx' => 'WC-77-ZZZZZZZZZZ' ) ) );
t( 'unknown reference -> 404', $r['http'] === 404 && $ORDERS[77]->completed === 0 );
reset_state();
$r = send( ipn( array( 'status' => 'failed' ) ) );
t( 'failed IPN for latest attempt -> order failed (retry possible)', $r['http'] === 200 && $ORDERS[77]->status === 'failed' );
reset_state();
$r = send( ipn( array( 'status' => 'pending' ) ) );
t( 'pending IPN records a note, order untouched', $r['http'] === 200 && $ORDERS[77]->status === 'pending' && $ORDERS[77]->completed === 0 );

echo "Verification via API (when a trx_id is provided)\n";
reset_state();
$HTTP['queue'][] = json_resp( 200, array( 'status' => 'success', 'trx_id' => 'TRX1', 'amount' => 250, 'fee' => 0, 'currency' => 'USD' ) );
$r = send( ipn( array(), array( 'trx_id' => 'TRX1' ) ) );
t( 'completes after verify success and stores trx id', $r['http'] === 200 && $ORDERS[77]->completed === 1 && $ORDERS[77]->meta['_ovajj_trx_id'] === 'TRX1' );
t( 'verify request was signed GET to the trx path', substr( $HTTP['calls'][0][0], -33 ) === '/api/v1/verify-payment/TRX1' || strpos( $HTTP['calls'][0][0], '/verify-payment/TRX1' ) !== false );

reset_state();
$HTTP['queue'][] = json_resp( 200, array( 'status' => 'success', 'trx_id' => 'TRX2', 'amount' => 237.5, 'fee' => 12.5, 'currency' => 'USD' ) );
$r = send( ipn( array(), array( 'trx_id' => 'TRX2' ) ) );
t( 'net-of-fee verify amount (237.5 + 12.5 fee = 250) accepted', $r['http'] === 200 && $ORDERS[77]->completed === 1 );

reset_state();
$HTTP['queue'][] = json_resp( 200, array( 'status' => 'pending', 'trx_id' => 'TRX3', 'amount' => 250, 'currency' => 'USD' ) );
$r = send( ipn( array(), array( 'trx_id' => 'TRX3' ) ) );
t( 'verify pending -> 202, not paid, re-check scheduled', $r['http'] === 202 && $ORDERS[77]->completed === 0 && count( $SCHEDULED ) === 1 );

reset_state();
$HTTP['queue'][] = json_resp( 200, array( 'status' => 'failed', 'trx_id' => 'TRX4', 'amount' => 250, 'currency' => 'USD' ) );
$r = send( ipn( array(), array( 'trx_id' => 'TRX4' ) ) );
t( 'IPN says completed but verify says failed -> on-hold, not paid', $r['http'] === 409 && $ORDERS[77]->status === 'on-hold' && $ORDERS[77]->completed === 0 );

reset_state();
$HTTP['queue'][] = json_resp( 200, array( 'status' => 'success', 'trx_id' => 'TRX5', 'amount' => 10, 'fee' => 0, 'currency' => 'USD' ) );
$r = send( ipn( array(), array( 'trx_id' => 'TRX5' ) ) );
t( 'verify amount mismatch -> on-hold, not paid', $r['http'] === 422 && $ORDERS[77]->completed === 0 );

reset_state();
$HTTP['queue'][] = json_resp( 503, array( 'message' => 'down' ) );
$r = send( ipn( array(), array( 'trx_id' => 'TRX6' ) ) );
t( 'verify API outage -> 503, not paid, retry scheduled', $r['http'] === 503 && $ORDERS[77]->completed === 0 && count( $SCHEDULED ) === 1 );
$HTTP['queue'][] = json_resp( 200, array( 'status' => 'success', 'trx_id' => 'TRX6', 'amount' => 250, 'fee' => 0, 'currency' => 'USD' ) );
( new Test_Processor() )->cron_reverify( 77, 'WC-77-ABCDEF1234' );
t( 'cron re-verification completes the order after recovery', $ORDERS[77]->completed === 1 );

/* ---------- 5. Refund notifications ---------- */
echo "Refund notifications\n";
reset_state();
$ORDERS[77]->status = 'processing';
$ORDERS[77]->meta['_ovajj_trx_id'] = 'TRXA';
$refund = array( 'event' => 'payment.refunded', 'status' => 'refunded', 'data' => array( 'original_trx_id' => 'TRXA', 'refund_trx_id' => 'RFD1', 'ref_trx' => 'WC-77-ABCDEF1234', 'ref_refund' => 'DASH-1', 'refunded_amount' => 10.0, 'total_refunded' => 10.0, 'currency' => 'USD', 'environment' => 'sandbox' ), 'timestamp' => 2 );
$r = send( json_encode( $refund ) );
t( 'external (dashboard) refund mirrored once in WooCommerce', $r['http'] === 200 && $ORDERS[77]->refunded == 10.0 );
$r = send( json_encode( $refund ) );
t( 'duplicate refund IPN not applied twice', $ORDERS[77]->refunded == 10.0 );
$refund['data']['refund_trx_id'] = 'RFD2';
$r = send( json_encode( $refund ) );
t( 'same ref_refund under a new refund trx id is treated as duplicate', $ORDERS[77]->refunded == 10.0 );
$ORDERS[77]->meta['_ovajj_refunds']['RFDWC'] = array( 'ref_refund' => 'WCR-77-1-1000', 'amount' => 10.0, 'source' => 'wc' );
$refund['data'] = array_merge( $refund['data'], array( 'refund_trx_id' => 'RFDWC', 'ref_refund' => 'WCR-77-1-1000' ) );
$r = send( json_encode( $refund ) );
t( 'IPN for a refund issued from WooCommerce is not mirrored again', $ORDERS[77]->refunded == 10.0 );
$refund['data'] = array_merge( $refund['data'], array( 'refund_trx_id' => 'RFD9', 'ref_refund' => 'BIG', 'refunded_amount' => 9999 ) );
$r = send( json_encode( $refund ) );
t( 'refund larger than remaining amount rejected', $r['http'] === 422 && $ORDERS[77]->refunded == 10.0 );

/* ---------- 6. Currency conversion (v1.1) ---------- */
echo "Currency conversion\n";
function conv_settings( $over = array() ) {
	global $OPTS;
	$OPTS['woocommerce_ovajj_settings'] = array_merge( $OPTS['woocommerce_ovajj_settings'], array( 'convert_enabled' => 'yes', 'wallet_currency' => 'USD', 'rate_mode' => 'fixed', 'fixed_rate' => '120' ), $over );
}
reset_state();
t( 'conversion off by default: nothing to convert', ! Ovajj_Currency::needs_conversion( 'BDT' ) );
conv_settings();
t( 'BDT site + USD wallet needs conversion; USD order does not', Ovajj_Currency::needs_conversion( 'BDT' ) && ! Ovajj_Currency::needs_conversion( 'USD' ) );
$c = Ovajj_Currency::convert( 12000.0, 'BDT' );
t( 'fixed rate 120: 12000 BDT -> 100.00 USD', ! is_wp_error( $c ) && $c['amount'] == 100.0 && $c['wallet_currency'] === 'USD' && $c['rate'] == 120.0 && $c['rate_source'] === 'fixed' );
$c = Ovajj_Currency::convert( 12345.67, 'BDT' );
t( 'amount rounded to wallet decimals (102.88)', $c['amount'] == 102.88 );
conv_settings( array( 'fixed_rate' => '' ) );
t( 'fixed mode without a rate fails (never guesses)', is_wp_error( Ovajj_Currency::convert( 100.0, 'BDT' ) ) );

echo "Live rate\n";
reset_state(); conv_settings( array( 'rate_mode' => 'live' ) );
$HTTP['queue'][] = json_resp( 200, array( 'result' => 'success', 'base_code' => 'USD', 'rates' => array( 'USD' => 1, 'BDT' => 121.5 ) ) );
$c = Ovajj_Currency::convert( 12150.0, 'BDT' );
t( 'live rate fetched from open.er-api.com and applied', ! is_wp_error( $c ) && $c['amount'] == 100.0 && strpos( $HTTP['calls'][0][0], 'https://open.er-api.com/v6/latest/USD' ) === 0 );
$n = count( $HTTP['calls'] );
Ovajj_Currency::convert( 100.0, 'BDT' );
t( 'second conversion uses the cache (no extra HTTP call)', count( $HTTP['calls'] ) === $n );
conv_settings( array( 'rate_mode' => 'live', 'rate_buffer' => '2' ) );
$c = Ovajj_Currency::convert( 12150.0, 'BDT' );
t( '2% safety margin lowers the rate so more wallet currency is charged', abs( $c['rate'] - 121.5 * 0.98 ) < 1e-9 && $c['amount'] > 100.0 );

reset_state(); conv_settings( array( 'rate_mode' => 'live' ) );
$HTTP['queue'][] = new WP_Error( 'http_request_failed', 'timeout' );
t( 'live fetch failure with no cache -> error, payment is not attempted blind', is_wp_error( Ovajj_Currency::convert( 100.0, 'BDT' ) ) );

reset_state(); conv_settings( array( 'rate_mode' => 'live' ) );
$OPTS['ovajj_rate_cache'] = array( 'USD>BDT' => array( 'rate' => 120.0, 'time' => time() - 10 * 3600 ) );
$HTTP['queue'][] = new WP_Error( 'http_request_failed', 'timeout' );
$c = Ovajj_Currency::convert( 12000.0, 'BDT' );
t( 'fetch fails but a <72h-old cached rate is still used', ! is_wp_error( $c ) && $c['amount'] == 100.0 );
$OPTS['ovajj_rate_cache']['USD>BDT']['time'] = time() - 100 * 3600;
$HTTP['queue'][] = new WP_Error( 'http_request_failed', 'timeout' );
t( 'cache older than 72h is not used', is_wp_error( Ovajj_Currency::convert( 12000.0, 'BDT' ) ) );

reset_state(); conv_settings( array( 'rate_mode' => 'live' ) );
$OPTS['ovajj_rate_cache'] = array( 'USD>BDT' => array( 'rate' => 120.0, 'time' => time() - 10 * 3600 ) );
$HTTP['queue'][] = json_resp( 200, array( 'result' => 'success', 'base_code' => 'USD', 'rates' => array( 'BDT' => 900 ) ) );
$c = Ovajj_Currency::convert( 12000.0, 'BDT' );
t( 'implausible jump (120 -> 900) rejected, previous rate kept', $c['amount'] == 100.0 );
reset_state(); conv_settings( array( 'rate_mode' => 'live' ) );
$HTTP['queue'][] = json_resp( 200, array( 'result' => 'success', 'base_code' => 'USD', 'rates' => array( 'EUR' => 0.9 ) ) );
t( 'missing pair in API response -> error', is_wp_error( Ovajj_Currency::convert( 100.0, 'BDT' ) ) );

echo "Converted payments: reconciliation\n";
function reset_converted() {
	$o = reset_state();
	conv_settings();
	$o->currency = 'BDT'; $o->total = '12000.00';
	$o->meta['_ovajj_attempts'] = array( 'WC-77-ABCDEF1234' => array(
		'environment' => 'sandbox', 'amount' => 100.0, 'currency' => 'USD', 'site_amount' => 12000.0, 'site_currency' => 'BDT',
		'rate' => 120.0, 'rate_source' => 'fixed', 'converted' => true, 'decimals' => 2,
	) );
	return $o;
}
reset_converted();
$r = send( ipn( array(), array( 'amount' => 100, 'currency_code' => 'USD' ) ) );
t( 'IPN of 100 USD completes a 12000 BDT order', $r['http'] === 200 && $ORDERS[77]->completed === 1 );
reset_converted();
$r = send( ipn( array(), array( 'amount' => 12000, 'currency_code' => 'BDT' ) ) );
t( 'IPN in BDT (not what we charged) is rejected', $r['http'] === 422 && $ORDERS[77]->completed === 0 );
reset_converted();
$r = send( ipn( array(), array( 'amount' => 12000, 'currency_code' => 'USD' ) ) );
t( 'IPN of 12000 USD (wrong amount) is rejected', $r['http'] === 422 && $ORDERS[77]->completed === 0 );
reset_converted();
$ORDERS[77]->total = '13000.00';
$r = send( ipn( array(), array( 'amount' => 100, 'currency_code' => 'USD' ) ) );
t( 'order total edited after the session was created -> on hold', $r['http'] === 422 && $ORDERS[77]->status === 'on-hold' );
reset_converted();
$HTTP['queue'][] = json_resp( 200, array( 'status' => 'success', 'trx_id' => 'TRXC', 'amount' => 100, 'fee' => 0, 'currency' => 'USD' ) );
$r = send( ipn( array(), array( 'amount' => 100, 'currency_code' => 'USD', 'trx_id' => 'TRXC' ) ) );
t( 'verify-payment is checked against the charged USD amount', $r['http'] === 200 && $ORDERS[77]->completed === 1 );

echo "Converted payments: refunds\n";
reset_converted();
$o = $ORDERS[77]; $o->status = 'processing'; $o->meta['_ovajj_trx_id'] = 'TRXC';
$att = $o->meta['_ovajj_attempts']['WC-77-ABCDEF1234'];
t( 'site_to_wallet: 3000 BDT -> 25.00 USD', Ovajj_Processor::site_to_wallet_refund( $o, $att, 3000.0 ) == 25.0 );
$refund = array( 'event' => 'payment.refunded', 'status' => 'refunded', 'data' => array( 'original_trx_id' => 'TRXC', 'refund_trx_id' => 'RC1', 'ref_trx' => 'WC-77-ABCDEF1234', 'ref_refund' => 'D1', 'refunded_amount' => 25.0, 'currency' => 'USD', 'environment' => 'sandbox' ), 'timestamp' => 3 );
$r = send( json_encode( $refund ) );
t( 'refund IPN of 25 USD mirrored as 3000 BDT', $r['http'] === 200 && abs( $o->refunded - 3000.0 ) < 0.001 );
t( 'recorded refund amount is stored in the charged (USD) currency', abs( Ovajj_Processor::refunded_wallet_total( $o ) - 25.0 ) < 0.001 );
t( 'refunding the whole remainder returns exactly the remaining USD (75.00)', Ovajj_Processor::site_to_wallet_refund( $o, $att, 9000.0 ) == 75.0 );
$refund['data'] = array_merge( $refund['data'], array( 'refund_trx_id' => 'RC2', 'ref_refund' => 'D2', 'refunded_amount' => 75.0 ) );
$r = send( json_encode( $refund ) );
t( 'final 75 USD refund closes the order balance exactly (12000 BDT total)', $r['http'] === 200 && abs( $o->refunded - 12000.0 ) < 0.001 );
$refund['data'] = array_merge( $refund['data'], array( 'refund_trx_id' => 'RC3', 'ref_refund' => 'D3', 'refunded_amount' => 1.0 ) );
$r = send( json_encode( $refund ) );
t( 'refund beyond the charged amount is rejected', $r['http'] === 422 && abs( $o->refunded - 12000.0 ) < 0.001 );
$refund['data'] = array_merge( $refund['data'], array( 'refund_trx_id' => 'RC4', 'ref_refund' => 'D4', 'refunded_amount' => 5.0, 'currency' => 'BDT' ) );
$r = send( json_encode( $refund ) );
t( 'refund IPN in the wrong currency is rejected', $r['http'] === 422 );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
