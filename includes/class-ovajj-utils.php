<?php
/**
 * Small helpers.
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Utility functions (mostly pure, easy to unit test).
 */
final class Ovajj_Utils {

	/**
	 * Build a unique merchant reference (max 60 chars per the API).
	 *
	 * @param int    $order_id Order ID.
	 * @param string $prefix   Prefix.
	 * @return string
	 */
	public static function generate_ref( int $order_id, string $prefix = 'WC' ): string {
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $prefix );
		$prefix = '' === $prefix ? 'WC' : substr( $prefix, 0, 12 );
		$rand   = strtoupper( substr( bin2hex( random_bytes( 6 ) ), 0, 10 ) );
		return $prefix . '-' . $order_id . '-' . $rand;
	}

	/**
	 * Extract the order ID from a reference created by generate_ref().
	 *
	 * @param string $ref Reference.
	 * @return int 0 when not parseable.
	 */
	public static function order_id_from_ref( string $ref ): int {
		return preg_match( '/^[A-Za-z0-9]{1,12}-(\d+)-[A-Z0-9]{10}$/', $ref, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Compare two money amounts at a given number of decimals using minor units.
	 *
	 * @param mixed $a        Amount A.
	 * @param mixed $b        Amount B.
	 * @param int   $decimals Decimals.
	 * @return bool
	 */
	public static function amounts_match( $a, $b, int $decimals = 2 ): bool {
		if ( ! is_numeric( $a ) || ! is_numeric( $b ) ) {
			return false;
		}
		$f = 10 ** max( 0, $decimals );
		return (int) round( (float) $a * $f ) === (int) round( (float) $b * $f );
	}

	/**
	 * Validate a hosted checkout URL: https only, and host must be the API host (or a subdomain).
	 *
	 * @param mixed  $url      URL.
	 * @param string $api_base API base URL.
	 * @return bool
	 */
	public static function is_valid_checkout_url( $url, string $api_base ): bool {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 4096 ) {
			return false;
		}
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || empty( $p['scheme'] ) || 'https' !== strtolower( $p['scheme'] ) ) {
			return false;
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return false;
		}
		$host    = strtolower( $p['host'] );
		$base    = wp_parse_url( $api_base );
		$allowed = array( strtolower( $base['host'] ?? '' ) );
		$allowed = (array) apply_filters( 'ovajj_allowed_checkout_hosts', $allowed );
		foreach ( $allowed as $h ) {
			$h = strtolower( (string) $h );
			if ( '' !== $h && ( $host === $h || substr( $host, -strlen( $h ) - 1 ) === '.' . $h ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a gateway status string means "paid". The IPN uses "completed", verify-payment uses "success".
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_success_status( string $status ): bool {
		return in_array( strtolower( $status ), array( 'completed', 'success' ), true );
	}

	/**
	 * Whether a status string means a terminal failure.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_failure_status( string $status ): bool {
		return in_array( strtolower( $status ), array( 'failed', 'cancelled', 'canceled', 'expired', 'timeout', 'timed_out' ), true );
	}
}
