<?php
/**
 * Plugin Name: Ovajj Payment Gateway
 * Description: Accept payments through Ovajj hosted checkout in WooCommerce (classic and Blocks checkout), with signed API requests, server-side payment verification and refunds.
 * Version: 2.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: ovajj
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 *
 * Based on the official Ovajj API documentation: https://ovajj.com/api-docs
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OVAJJ_WC_VERSION', '2.2.0');
define('OVAJJ_WC_FILE', __FILE__);
define('OVAJJ_WC_PATH', plugin_dir_path(__FILE__));
define('OVAJJ_WC_URL', plugin_dir_url(__FILE__));

// Declare compatibility with HPOS (custom order tables) and the Cart/Checkout Blocks.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', OVAJJ_WC_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', OVAJJ_WC_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Ovajj Payment Gateway</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    require_once OVAJJ_WC_PATH . 'includes/class-ovajj-api.php';
    require_once OVAJJ_WC_PATH . 'includes/class-ovajj-rates.php';
    require_once OVAJJ_WC_PATH . 'includes/class-ovajj-gateway.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'Ovajj_WC_Gateway';
        return $gateways;
    });

    // Block-based checkout support.
    add_action('woocommerce_blocks_loaded', function () {
        if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }
        require_once OVAJJ_WC_PATH . 'includes/class-ovajj-blocks.php';
        add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
            $registry->register(new Ovajj_WC_Blocks());
        });
    });
});

// "Settings" link on the Plugins screen.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=ovajj');
    array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
    return $links;
});
