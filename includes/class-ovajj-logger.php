<?php
/**
 * Logger with secret redaction.
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the WooCommerce logger.
 */
final class Ovajj_Logger {

	const SOURCE = 'ovajj-payment-gateway';

	/**
	 * Debug logging switch.
	 *
	 * @return bool
	 */
	public static function debug_enabled(): bool {
		return 'yes' === Ovajj_Settings::get( 'debug', 'no' );
	}

	/**
	 * Log a message. debug/info only when debug logging is enabled; notice and above always.
	 *
	 * @param string $level   PSR level.
	 * @param string $message Message.
	 * @param array  $context Context (redacted before writing).
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		if ( in_array( $level, array( 'debug', 'info' ), true ) && ! self::debug_enabled() ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context = self::redact( $context );
		$suffix  = $context ? ' ' . wp_json_encode( $context ) : '';
		wc_get_logger()->log( $level, $message . $suffix, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Recursively redact sensitive values.
	 *
	 * @param mixed  $data Data.
	 * @param string $key  Current key.
	 * @return mixed
	 */
	public static function redact( $data, string $key = '' ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[ $k ] = self::redact( $v, (string) $k );
			}
			return $out;
		}
		if ( '' !== $key && preg_match( '/(secret|api[_-]?key|merchant[_-]?key|signature|authorization|token|password|email|customer_name|^name$|phone|address)/i', $key ) ) {
			return '[redacted]';
		}
		if ( is_string( $data ) && preg_match( '/(^|_)url$/i', $key ) ) {
			// Hosted checkout URLs carry tokens in the query string.
			$q = strpos( $data, '?' );
			return false === $q ? $data : substr( $data, 0, $q ) . '?[redacted]';
		}
		if ( is_string( $data ) && strlen( $data ) > 500 ) {
			return substr( $data, 0, 500 ) . '...';
		}
		return $data;
	}
}
