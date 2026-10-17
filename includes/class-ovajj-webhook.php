<?php
/**
 * IPN / webhook endpoint.
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint: /wc-api/ovajj_webhook (as documented by Ovajj for WooCommerce).
 */
class Ovajj_Webhook {

	const MAX_BODY = 262144; // 256 KB.

	/** @var Ovajj_Processor */
	private $processor;

	/**
	 * Constructor.
	 *
	 * @param Ovajj_Processor|null $processor Processor.
	 */
	public function __construct( ?Ovajj_Processor $processor = null ) {
		$this->processor = $processor ?? new Ovajj_Processor();
	}

	/**
	 * Register the WooCommerce API hook.
	 */
	public function init(): void {
		add_action( 'woocommerce_api_ovajj_webhook', array( $this, 'handle' ) );
	}

	/**
	 * Public URL sent to Ovajj as ipn_url.
	 */
	public static function endpoint_url(): string {
		return WC()->api_request_url( 'ovajj_webhook' );
	}

	/**
	 * Verify X-Signature: HMAC-SHA256 of the raw body, hex, optional "sha256=" prefix.
	 *
	 * @param string $raw    Raw request body.
	 * @param string $header Signature header value.
	 * @param string $secret API secret for the environment.
	 * @return bool
	 */
	public static function verify_signature( string $raw, string $header, string $secret ): bool {
		$header = trim( $header );
		if ( '' === $header || '' === $secret ) {
			return false;
		}
		if ( 0 === stripos( $header, 'sha256=' ) ) {
			$header = substr( $header, 7 );
		}
		return hash_equals( hash_hmac( 'sha256', $raw, $secret ), strtolower( $header ) );
	}

	/**
	 * WC API callback.
	 */
	public function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			header( 'Allow: POST' );
			$this->respond( array( 'http' => 405, 'code' => 'method_not_allowed', 'message' => 'POST required.' ) );
			return;
		}
		$raw = (string) file_get_contents( 'php://input' ); // Raw body is required for the signature.
		$sig = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) ) : '';
		$env = isset( $_SERVER['HTTP_X_ENVIRONMENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_ENVIRONMENT'] ) ) ) : '';

		$result = $this->process( $raw, $sig, $env );
		update_option(
			'ovajj_last_webhook',
			array(
				'time' => time(),
				'http' => $result['http'],
				'code' => $result['code'],
			),
			false
		);
		$this->respond( $result );
	}

	/**
	 * Authenticate and dispatch a webhook (separated from I/O for testing).
	 *
	 * @param string $raw Raw body.
	 * @param string $sig X-Signature header.
	 * @param string $env X-Environment header (may be empty).
	 * @return array{http:int,code:string,message:string}
	 */
	public function process( string $raw, string $sig, string $env ): array {
		if ( '' === $raw || strlen( $raw ) > self::MAX_BODY ) {
			return array( 'http' => 400, 'code' => 'bad_body', 'message' => 'Invalid body.' );
		}
		$payload = json_decode( $raw, true );

		// The environment only selects WHICH secret is tried; authenticity is decided by the signature.
		if ( '' === $env && is_array( $payload ) && isset( $payload['data']['environment'] ) && is_string( $payload['data']['environment'] ) ) {
			$env = strtolower( $payload['data']['environment'] );
		}
		if ( '' === $env ) {
			$env = 'production'; // Documented default.
		}
		if ( ! in_array( $env, array( 'sandbox', 'production' ), true ) ) {
			return array( 'http' => 400, 'code' => 'bad_environment', 'message' => 'Unknown environment.' );
		}

		$secret = Ovajj_Settings::credentials( $env )['api_secret'];
		if ( '' === $secret ) {
			Ovajj_Logger::log( 'error', 'Webhook received but no API secret is configured for the environment', array( 'environment' => $env ) );
			return array( 'http' => 503, 'code' => 'not_configured', 'message' => 'Gateway not configured.' );
		}
		if ( ! self::verify_signature( $raw, $sig, $secret ) ) {
			Ovajj_Logger::log( 'warning', 'Webhook signature verification failed', array( 'environment' => $env ) );
			return array( 'http' => 401, 'code' => 'invalid_signature', 'message' => 'Invalid signature.' );
		}
		if ( ! is_array( $payload ) ) {
			return array( 'http' => 400, 'code' => 'malformed', 'message' => 'Malformed JSON.' );
		}
		$body_env = isset( $payload['data']['environment'] ) && is_string( $payload['data']['environment'] ) ? strtolower( $payload['data']['environment'] ) : '';
		if ( '' !== $body_env && $body_env !== $env ) {
			Ovajj_Logger::log( 'warning', 'Webhook header and body environments disagree', array( 'header' => $env, 'body' => $body_env ) );
			return array( 'http' => 400, 'code' => 'environment_mismatch', 'message' => 'Environment mismatch.' );
		}

		Ovajj_Logger::log( 'debug', 'Webhook accepted', array( 'status' => $payload['status'] ?? null, 'environment' => $env ) );
		return $this->processor->handle_ipn( $env, $payload, hash( 'sha256', $raw ) );
	}

	/**
	 * Emit the JSON response.
	 *
	 * @param array $result Result.
	 */
	private function respond( array $result ): void {
		nocache_headers();
		wp_send_json(
			array(
				'status'  => $result['code'],
				'message' => $result['message'] ?? '',
			),
			$result['http']
		);
	}
}
