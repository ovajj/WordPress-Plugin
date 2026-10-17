<?php
/**
 * Uninstall: removes plugin-owned options. Order meta is intentionally kept so payment history stays intact.
 *
 * @package OvajjPaymentGateway
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ovajj_settings = get_option( 'woocommerce_ovajj_settings', array() );

delete_option( 'ovajj_last_webhook' );
delete_option( 'ovajj_branding' );

// Credentials are only removed when the merchant opted in.
if ( is_array( $ovajj_settings ) && isset( $ovajj_settings['delete_data'] ) && 'yes' === $ovajj_settings['delete_data'] ) {
	delete_option( 'woocommerce_ovajj_settings' );
}
wp_unschedule_hook( 'ovajj_reverify_order' );
