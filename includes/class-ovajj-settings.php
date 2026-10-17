<?php
/**
 * Settings accessor.
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads gateway settings and resolves per-environment credentials.
 */
final class Ovajj_Settings {

	const OPTION = 'woocommerce_ovajj_settings';

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all(): array {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( string $key, $default = '' ) {
		$s = self::all();
		return isset( $s[ $key ] ) && '' !== $s[ $key ] ? $s[ $key ] : $default;
	}

	/**
	 * Active environment: sandbox or production.
	 *
	 * @return string
	 */
	public static function environment(): string {
		return 'production' === self::get( 'environment', 'sandbox' ) ? 'production' : 'sandbox';
	}

	/**
	 * Credential triple for one environment. Never falls back to another environment.
	 *
	 * @param string $env Environment.
	 * @return array{merchant_key:string,api_key:string,api_secret:string}
	 */
	public static function credentials( string $env ): array {
		$env = 'production' === $env ? 'production' : 'sandbox';
		return array(
			'merchant_key' => trim( (string) self::get( $env . '_merchant_key', '' ) ),
			'api_key'      => trim( (string) self::get( $env . '_api_key', '' ) ),
			'api_secret'   => trim( (string) self::get( $env . '_api_secret', '' ) ),
		);
	}

	/**
	 * Whether all three credentials exist for an environment.
	 *
	 * @param string $env Environment.
	 * @return bool
	 */
	public static function is_configured( string $env ): bool {
		$c = self::credentials( $env );
		return '' !== $c['merchant_key'] && '' !== $c['api_key'] && '' !== $c['api_secret'];
	}
}
