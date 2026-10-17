<?php
/**
 * Plugin wiring.
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that registers hooks.
 */
final class Ovajj_Plugin {

	/** @var Ovajj_Plugin|null */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		load_plugin_textdomain( 'ovajj-payment-gateway', false, dirname( plugin_basename( OVAJJ_PG_FILE ) ) . '/languages' );

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OVAJJ_PG_FILE ), array( $this, 'action_links' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		( new Ovajj_Webhook() )->init();

		$processor = new Ovajj_Processor();
		add_action( Ovajj_Processor::CRON_HOOK, array( $processor, 'cron_reverify' ), 10, 2 );

		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ) );
		add_action( 'woocommerce_order_action_ovajj_verify_payment', array( $this, 'run_verify_action' ) );
	}

	/**
	 * Register the gateway class.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'Ovajj_Gateway';
		return $gateways;
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ovajj' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'ovajj-payment-gateway' ) . '</a>' );
		return $links;
	}

	/**
	 * Small stylesheet for the checkout.
	 */
	public function enqueue_styles(): void {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			wp_enqueue_style( 'ovajj-checkout', OVAJJ_PG_URL . 'assets/css/ovajj.css', array(), OVAJJ_PG_VERSION );
		}
	}

	/**
	 * Add the manual verification action on Ovajj orders.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function order_actions( $actions ) {
		global $theorder;
		if ( $theorder instanceof WC_Order && 'ovajj' === $theorder->get_payment_method() ) {
			$actions['ovajj_verify_payment'] = __( 'Verify payment with Ovajj', 'ovajj-payment-gateway' );
		}
		return $actions;
	}

	/**
	 * Handle the manual verification action.
	 *
	 * @param WC_Order $order Order.
	 */
	public function run_verify_action( $order ): void {
		if ( $order instanceof WC_Order && current_user_can( 'edit_shop_orders' ) ) {
			( new Ovajj_Processor() )->manual_verify( $order );
		}
	}
}
