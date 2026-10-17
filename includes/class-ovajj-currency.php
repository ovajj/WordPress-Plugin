<?php
/**
 * Optional currency conversion (site currency -> Ovajj wallet currency).
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exchange-rate handling.
 *
 * Rate convention everywhere in this class: "rate" = how many SITE-currency units equal 1 WALLET-currency unit
 * (for example BDT site, USD wallet: rate 120 means 1 USD = 120 BDT). wallet_amount = site_amount / rate.
 */
final class Ovajj_Currency {

	/** Free, no-key endpoint. Attribution to exchangerate-api.com is required by its terms. */
	const LIVE_URL  = 'https://open.er-api.com/v6/latest/';
	const OPT_CACHE = 'ovajj_rate_cache';

	/**
	 * Whether conversion is switched on.
	 */
	public static function enabled(): bool {
		return 'yes' === Ovajj_Settings::get( 'convert_enabled', 'no' );
	}

	/**
	 * Currency of the Ovajj merchant wallet (what Ovajj is asked to charge).
	 */
	public static function wallet_currency(): string {
		$c = strtoupper( trim( (string) Ovajj_Settings::get( 'wallet_currency', 'USD' ) ) );
		return preg_match( '/^[A-Z]{3}$/', $c ) ? $c : 'USD';
	}

	/**
	 * fixed|live.
	 */
	public static function mode(): string {
		return 'live' === Ovajj_Settings::get( 'rate_mode', 'fixed' ) ? 'live' : 'fixed';
	}

	/**
	 * Safety margin (0-20 %) applied to live rates.
	 */
	public static function buffer_percent(): float {
		return max( 0.0, min( 20.0, (float) Ovajj_Settings::get( 'rate_buffer', '0' ) ) );
	}

	/**
	 * Decimals of the wallet currency.
	 *
	 * @param string $wallet Wallet currency.
	 */
	public static function wallet_decimals( string $wallet ): int {
		return (int) apply_filters( 'ovajj_wallet_decimals', 2, $wallet );
	}

	/**
	 * Whether an order in $site currency must be converted.
	 *
	 * @param string $site Site/order currency.
	 */
	public static function needs_conversion( string $site ): bool {
		return self::enabled() && strtoupper( $site ) !== self::wallet_currency();
	}

	/**
	 * Convert a site-currency amount to the wallet currency.
	 *
	 * @param float  $site_amount Amount in site currency.
	 * @param string $site        Site currency.
	 * @return array|WP_Error {amount, wallet_currency, site_currency, rate, rate_source, decimals}
	 */
	public static function convert( float $site_amount, string $site ) {
		$wallet = self::wallet_currency();
		$site   = strtoupper( $site );
		$r      = self::get_rate( $site, $wallet, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$dec = self::wallet_decimals( $wallet );
		return array(
			'amount'          => round( $site_amount / $r['rate'], $dec ),
			'wallet_currency' => $wallet,
			'site_currency'   => $site,
			'rate'            => $r['rate'],
			'rate_source'     => $r['source'],
			'decimals'        => $dec,
		);
	}

	/**
	 * Current rate (site units per 1 wallet unit).
	 *
	 * @param string $site        Site currency.
	 * @param string $wallet      Wallet currency.
	 * @param bool   $allow_fetch Whether a remote request is allowed (false for page rendering).
	 * @return array|WP_Error {rate, source, time, base, stale}
	 */
	public static function get_rate( string $site, string $wallet, bool $allow_fetch = true ) {
		if ( 'fixed' === self::mode() ) {
			$r = (float) Ovajj_Settings::get( 'fixed_rate', '0' );
			if ( $r <= 0 ) {
				return new WP_Error( 'ovajj_rate', 'The fixed exchange rate is not configured.' );
			}
			return array( 'rate' => $r, 'source' => 'fixed', 'time' => 0, 'base' => $r, 'stale' => false );
		}

		$live = self::live_rate( $site, $wallet, $allow_fetch );
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		$eff = $live['rate'] * ( 1 - self::buffer_percent() / 100 );
		return array( 'rate' => $eff, 'source' => 'live', 'time' => $live['time'], 'base' => $live['rate'], 'stale' => $live['stale'] );
	}

	/**
	 * Cached live rate with stale fallback (never invents a rate).
	 *
	 * @param string $site        Site currency.
	 * @param string $wallet      Wallet currency.
	 * @param bool   $allow_fetch Allow remote request.
	 * @return array|WP_Error
	 */
	private static function live_rate( string $site, string $wallet, bool $allow_fetch ) {
		$cache = get_option( self::OPT_CACHE, array() );
		$cache = is_array( $cache ) ? $cache : array();
		$key   = $wallet . '>' . $site;
		$entry = isset( $cache[ $key ] ) && is_array( $cache[ $key ] ) ? $cache[ $key ] : null;

		$ttl       = (int) apply_filters( 'ovajj_live_rate_ttl', 6 * HOUR_IN_SECONDS );
		$max_stale = (int) apply_filters( 'ovajj_live_rate_max_age', 72 * HOUR_IN_SECONDS );
		$age       = $entry ? time() - (int) $entry['time'] : PHP_INT_MAX;

		if ( $entry && $age <= $ttl ) {
			return array( 'rate' => (float) $entry['rate'], 'time' => (int) $entry['time'], 'stale' => false );
		}

		$err = null;
		if ( $allow_fetch ) {
			$f = self::refresh_live( $site, $wallet );
			if ( ! is_wp_error( $f ) ) {
				return array( 'rate' => $f['rate'], 'time' => $f['time'], 'stale' => false );
			}
			$err = $f;
		}
		if ( $entry && $age <= $max_stale ) {
			return array( 'rate' => (float) $entry['rate'], 'time' => (int) $entry['time'], 'stale' => true );
		}
		return $err ?? new WP_Error( 'ovajj_rate', 'No recent live exchange rate is available.' );
	}

	/**
	 * Fetch a fresh live rate and store it.
	 *
	 * @param string $site   Site currency.
	 * @param string $wallet Wallet currency.
	 * @return array|WP_Error {rate, time}
	 */
	public static function refresh_live( string $site, string $wallet ) {
		$response = wp_remote_get(
			self::LIVE_URL . rawurlencode( $wallet ),
			array( 'timeout' => 10, 'redirection' => 2, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( is_wp_error( $response ) ) {
			Ovajj_Logger::log( 'error', 'Live rate request failed', array( 'error' => $response->get_error_message() ) );
			return new WP_Error( 'ovajj_rate', 'Live rate request failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			Ovajj_Logger::log( 'error', 'Live rate API returned an unusable response', array( 'http_status' => $code ) );
			return new WP_Error( 'ovajj_rate', 'Live rate API returned an unusable response (HTTP ' . $code . ').' );
		}
		if ( ( isset( $data['result'] ) && 'success' !== $data['result'] ) || ( isset( $data['base_code'] ) && strtoupper( (string) $data['base_code'] ) !== $wallet ) ) {
			return new WP_Error( 'ovajj_rate', 'Live rate API reported an error.' );
		}
		$rate = $data['rates'][ $site ] ?? null;
		if ( ! is_numeric( $rate ) || (float) $rate <= 0 ) {
			return new WP_Error( 'ovajj_rate', 'The live rate API has no rate for ' . $wallet . ' to ' . $site . '.' );
		}
		$rate = (float) $rate;

		$cache = get_option( self::OPT_CACHE, array() );
		$cache = is_array( $cache ) ? $cache : array();
		$key   = $wallet . '>' . $site;
		$prev  = isset( $cache[ $key ]['rate'] ) ? (float) $cache[ $key ]['rate'] : 0.0;

		// Guard against a corrupted upstream value: refuse a huge jump versus the last good rate.
		$max_jump = (float) apply_filters( 'ovajj_max_rate_jump', 0.5 );
		if ( $prev > 0 && abs( $rate / $prev - 1 ) > $max_jump ) {
			Ovajj_Logger::log( 'error', 'Live rate rejected: implausible jump', array( 'previous' => $prev, 'received' => $rate ) );
			return new WP_Error( 'ovajj_rate', 'The new live rate differs too much from the previous one and was rejected.' );
		}

		$cache[ $key ] = array( 'rate' => $rate, 'time' => time() );
		update_option( self::OPT_CACHE, $cache, false );
		Ovajj_Logger::log( 'notice', 'Live exchange rate refreshed', array( 'pair' => $key, 'rate' => $rate ) );
		return array( 'rate' => $rate, 'time' => $cache[ $key ]['time'] );
	}

	/**
	 * Human label such as "1 USD = 120.0000 BDT".
	 *
	 * @param float  $rate   Rate.
	 * @param string $wallet Wallet currency.
	 * @param string $site   Site currency.
	 */
	public static function rate_label( float $rate, string $wallet, string $site ): string {
		return sprintf( '1 %1$s = %2$s %3$s', $wallet, rtrim( rtrim( number_format( $rate, 6, '.', '' ), '0' ), '.' ), $site );
	}
}
