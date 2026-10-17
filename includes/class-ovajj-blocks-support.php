<?php
/**
 * WooCommerce Checkout Blocks integration.
 *
 * @package OvajjPaymentGateway
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes only non-secret display data to the block checkout.
 */
final class Ovajj_Blocks_Support extends AbstractPaymentMethodType {

	/** @var string */
	protected $name = 'ovajj';

	/**
	 * Load settings.
	 */
	public function initialize() {
		$this->settings = Ovajj_Settings::all();
	}

	/**
	 * Active only when enabled and the selected environment is fully configured.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === ( $this->settings['enabled'] ?? 'no' ) && Ovajj_Settings::is_configured( Ovajj_Settings::environment() );
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'ovajj-blocks',
			OVAJJ_PG_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			OVAJJ_PG_VERSION,
			true
		);
		wp_set_script_translations( 'ovajj-blocks', 'ovajj-payment-gateway', OVAJJ_PG_PATH . 'languages' );
		wp_register_style( 'ovajj-checkout', OVAJJ_PG_URL . 'assets/css/ovajj.css', array(), OVAJJ_PG_VERSION );
		wp_enqueue_style( 'ovajj-checkout' );
		return array( 'ovajj-blocks' );
	}

	/**
	 * Data passed to the script. Never includes credentials.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$logo = '';
		if ( 'yes' === ( $this->settings['show_logo'] ?? 'yes' ) ) {
			$b    = get_option( 'ovajj_branding' );
			$logo = is_array( $b ) && ! empty( $b['logo'] ) ? esc_url_raw( (string) $b['logo'], array( 'https' ) ) : '';
		}
		$site       = strtoupper( get_woocommerce_currency() );
		$wallet     = Ovajj_Currency::wallet_currency();
		$conversion = array( 'enabled' => false );
		if ( Ovajj_Currency::needs_conversion( $site ) ) {
			$r          = Ovajj_Currency::get_rate( $site, $wallet, false ); // Cached rate only; no remote call while rendering.
			$conversion = array(
				'enabled'  => true,
				'site'     => $site,
				'wallet'   => $wallet,
				'rate'     => is_wp_error( $r ) ? 0 : (float) $r['rate'],
				'decimals' => Ovajj_Currency::wallet_decimals( $wallet ),
			);
		}
		return array(
			'conversion'   => $conversion,
			'title'        => $this->settings['title'] ?? 'Ovajj',
			'description'  => wp_strip_all_tags( (string) ( $this->settings['description'] ?? '' ) ),
			'instructions' => wp_strip_all_tags( (string) ( $this->settings['instructions'] ?? '' ) ),
			'is_sandbox'   => 'sandbox' === Ovajj_Settings::environment(),
			'logo'         => $logo,
			'supports'     => array( 'products' ),
		);
	}
}
