<?php
/**
 * Plugin Name:       Ovajj Payment Gateway
 * Plugin URI:        https://ovajj.com
 * Description:       Accept payments through Ovajj hosted checkout in WooCommerce (classic checkout and Checkout Blocks, HPOS compatible).
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Ovajj Gateway Integration
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ovajj-payment-gateway
 * Domain Path:       /languages
 * WC requires at least: 6.0
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

define( 'OVAJJ_PG_VERSION', '1.1.0' );
define( 'OVAJJ_PG_FILE', __FILE__ );
define( 'OVAJJ_PG_PATH', plugin_dir_path( __FILE__ ) );
define( 'OVAJJ_PG_URL', plugin_dir_url( __FILE__ ) );

// Declare HPOS and Checkout Blocks compatibility.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OVAJJ_PG_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', OVAJJ_PG_FILE, true );
		}
	}
);

// Register the Checkout Blocks integration once WooCommerce Blocks is ready.
add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once OVAJJ_PG_PATH . 'includes/class-ovajj-settings.php';
		require_once OVAJJ_PG_PATH . 'includes/class-ovajj-utils.php';
		require_once OVAJJ_PG_PATH . 'includes/class-ovajj-logger.php';
		require_once OVAJJ_PG_PATH . 'includes/class-ovajj-currency.php';
		require_once OVAJJ_PG_PATH . 'includes/class-ovajj-api.php';
		require_once OVAJJ_PG_PATH . 'includes/class-ovajj-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
				$registry->register( new Ovajj_Blocks_Support() );
			}
		);
	}
);

add_action( 'plugins_loaded', 'ovajj_pg_bootstrap', 11 );

/**
 * Boot the plugin only when WooCommerce is available.
 */
function ovajj_pg_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'ovajj_pg_missing_wc_notice' );
		return;
	}

	foreach ( array( 'settings', 'logger', 'utils', 'currency', 'api', 'processor', 'webhook', 'gateway', 'plugin' ) as $file ) {
		require_once OVAJJ_PG_PATH . 'includes/class-ovajj-' . $file . '.php';
	}

	Ovajj_Plugin::instance()->init();
}

/**
 * Admin notice shown when WooCommerce is missing or inactive.
 */
function ovajj_pg_missing_wc_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Ovajj Payment Gateway requires WooCommerce to be installed and active.', 'ovajj-payment-gateway' );
	echo '</p></div>';
}
