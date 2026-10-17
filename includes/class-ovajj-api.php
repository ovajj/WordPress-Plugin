<?php
/**
 * Ovajj API client (HMAC-SHA256 signed requests).
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to https://ovajj.com/api/v1/*.
 */
final class Ovajj_API {

	const BASE_URL = 'https://ovajj.com';

	/** @var string */
	private $environment;
	/** @var string */
	private $merchant_key;
	/** @var string */
	private $api_key;
	/** @var string */
	private $api_secret;
	/** @var string */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @param string      $environment  sandbox|production.
	 * @param string      $merchant_key Merchant key for this environment.
	 * @param string      $api_key      API key for this environment.
	 * @param string      $api_secret   API secret (Client Secret) for this environment.
	 * @param string|null $base_url     Override base URL (testing only).
	 */
	public function __construct( string $environment, string $merchant_key, string $api_key, string $api_secret, ?string $base_url = null ) {
		$this->environment  = 'production' === $environment ? 'production' : 'sandbox';
		$this->merchant_key = $merchant_key;
		$this->api_key      = $api_key;
		$this->api_secret   = $api_secret;
		$base               = $base_url ?? (string) apply_filters( 'ovajj_api_base_url', self::BASE_URL );
		$this->base_url     = rtrim( $base, '/' );
	}

	/**
	 * Build a client from saved settings for an environment (defaults to the active one).
	 *
	 * @param string|null $environment Environment.
	 * @return self
	 */
	public static function from_settings( ?string $environment = null ): self {
		$env   = $environment ?? Ovajj_Settings::environment();
		$creds = Ovajj_Settings::credentials( $env );
		return new self( $env, $creds['merchant_key'], $creds['api_key'], $creds['api_secret'] );
	}

	/**
	 * Environment of this client.
	 *
	 * @return string
	 */
	public function environment(): string {
		return $this->environment;
	}

	/**
	 * Base URL.
	 *
	 * @return string
	 */
	public function base_url(): string {
		return $this->base_url;
	}

	/**
	 * Whether credentials are complete.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->merchant_key && '' !== $this->api_key && '' !== $this->api_secret;
	}

	/**
	 * Compute X-Signature: "sha256=" . HMAC-SHA256( timestamp.METHOD.path_with_query.raw_body ).
	 *
	 * @param string $timestamp       Unix timestamp (seconds).
	 * @param string $method          HTTP method.
	 * @param string $path_with_query Path including query string.
	 * @param string $body            Raw body ('' for GET).
	 * @param string $secret          API secret.
	 * @return string
	 */
	public static function sign( string $timestamp, string $method, string $path_with_query, string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . strtoupper( $method ) . '.' . $path_with_query . '.' . $body, $secret );
	}

	/**
	 * POST /api/v1/initiate-payment.
	 *
	 * @param array $payload Payload.
	 * @return array|WP_Error
	 */
	public function initiate_payment( array $payload ) {
		return $this->request( 'POST', '/api/v1/initiate-payment', $payload );
	}

	/**
	 * GET /api/v1/verify-payment/{trxId}.
	 *
	 * @param string $trx_id Ovajj transaction ID.
	 * @return array|WP_Error
	 */
	public function verify_payment( string $trx_id ) {
		return $this->request( 'GET', '/api/v1/verify-payment/' . rawurlencode( $trx_id ) );
	}

	/**
	 * POST /api/v1/refund-payment.
	 *
	 * @param array $payload Payload.
	 * @return array|WP_Error
	 */
	public function refund_payment( array $payload ) {
		return $this->request( 'POST', '/api/v1/refund-payment', $payload );
	}

	/**
	 * GET /api/v1/site-info.
	 *
	 * @return array|WP_Error
	 */
	public function site_info() {
		return $this->request( 'GET', '/api/v1/site-info' );
	}

	/**
	 * Perform a signed request.
	 *
	 * @param string     $method  GET|POST.
	 * @param string     $path    Path with query string.
	 * @param array|null $payload JSON payload for POST.
	 * @return array|WP_Error
	 */
	private function request( string $method, string $path, ?array $payload = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'ovajj_not_configured', 'Ovajj credentials are incomplete for the ' . $this->environment . ' environment.' );
		}

		$body = '';
		if ( null !== $payload ) {
			$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $body ) ) {
				return new WP_Error( 'ovajj_encode_error', 'Could not encode the request body as JSON.' );
			}
		}

		$timestamp = (string) time();
		$headers   = array(
			'Accept'         => 'application/json',
			'X-Environment'  => $this->environment,
			'X-Merchant-Key' => $this->merchant_key,
			'X-API-Key'      => $this->api_key,
			'X-Timestamp'    => $timestamp,
			'X-Signature'    => self::sign( $timestamp, $method, $path, $body, $this->api_secret ),
		);
		$args      = array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $headers,
			'user-agent'  => 'OvajjWooCommerce/' . ( defined( 'OVAJJ_PG_VERSION' ) ? OVAJJ_PG_VERSION : 'dev' ),
		);
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $body; // Exactly the bytes that were signed.
		}

		Ovajj_Logger::log( 'debug', 'API request', array( 'method' => $method, 'path' => preg_replace( '#/verify-payment/.*#', '/verify-payment/{trxId}', $path ), 'environment' => $this->environment ) );

		$response = wp_remote_request( $this->base_url . $path, $args );

		if ( is_wp_error( $response ) ) {
			Ovajj_Logger::log( 'error', 'API transport error', array( 'path' => $path, 'error' => $response->get_error_message() ) );
			return new WP_Error( 'ovajj_http_error', $response->get_error_message(), array( 'transient' => true ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			Ovajj_Logger::log( 'error', 'API returned a malformed (non-JSON) response', array( 'path' => $path, 'http_status' => $status ) );
			return new WP_Error( 'ovajj_malformed_response', 'Malformed response from Ovajj.', array( 'status' => $status, 'transient' => $status >= 500 ) );
		}

		$api_failed = isset( $data['success'] ) && false === $data['success'];
		if ( $status < 200 || $status >= 300 || $api_failed ) {
			$message    = '';
			foreach ( array( 'message', 'error' ) as $k ) {
				if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
					$message = $data[ $k ];
					break;
				}
			}
			$error_code = isset( $data['error_code'] ) && is_string( $data['error_code'] ) ? $data['error_code'] : '';
			Ovajj_Logger::log(
				'error',
				'API error response',
				array(
					'path'        => preg_replace( '#/verify-payment/.*#', '/verify-payment/{trxId}', $path ),
					'http_status' => $status,
					'error_code'  => $error_code,
					'message'     => $message,
					'errors'      => isset( $data['errors'] ) ? $data['errors'] : null,
				)
			);
			return new WP_Error(
				'ovajj_api_error',
				'' !== $message ? $message : 'Ovajj API error (HTTP ' . $status . ').',
				array(
					'status'     => $status,
					'error_code' => $error_code,
					'errors'     => $data['errors'] ?? null,
					'transient'  => ( 429 === $status || $status >= 500 ),
				)
			);
		}

		return $data;
	}

	/**
	 * Convert an API/transport error to text that is safe to show shoppers (no internals).
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public static function customer_message( WP_Error $error ): string {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$msg  = strtolower( $error->get_error_message() );
		$code = strtoupper( (string) ( $data['error_code'] ?? '' ) );

		if ( false !== strpos( $msg, 'currency' ) || false !== strpos( $msg, 'wallet' ) || 'INVALID_CURRENCY' === $code ) {
			return __( 'This payment method is not available for your order currency right now. Please choose another payment method.', 'ovajj-payment-gateway' );
		}
		if ( 'INVALID_AMOUNT' === $code || false !== strpos( $msg, 'payment amount' ) ) {
			return __( 'The order amount is not accepted by this payment method.', 'ovajj-payment-gateway' );
		}
		if ( 429 === ( $data['status'] ?? 0 ) ) {
			return __( 'Too many payment requests. Please wait a moment and try again.', 'ovajj-payment-gateway' );
		}
		return __( 'We could not start your payment with Ovajj. Please try again or choose another payment method.', 'ovajj-payment-gateway' );
	}
}
