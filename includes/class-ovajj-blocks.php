<?php
/**
 * Makes the gateway selectable in the block-based (default) WooCommerce checkout.
 * The script is plain JS (no build step required).
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Ovajj_WC_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'ovajj';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_ovajj_settings', []);
    }

    public function is_active()
    {
        return isset($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'ovajj-wc-blocks',
            OVAJJ_WC_URL . 'assets/js/blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            OVAJJ_WC_VERSION,
            true
        );
        return ['ovajj-wc-blocks'];
    }

    public function get_payment_method_data()
    {
        $description = 'You will be redirected to Ovajj to complete your payment securely.';
        $wallet = Ovajj_WC_API::resolve_processing_currency(is_array($this->settings) ? $this->settings : []);
        if ($wallet !== strtoupper(get_woocommerce_currency())) {
            $description .= ' Your order total will be converted and charged in ' . $wallet . ' at the current exchange rate.';
        }

        return [
            'title'       => 'Ovajj Payment Gateway',
            'description' => $description,
            'supports'    => ['products', 'refunds'],
        ];
    }
}
