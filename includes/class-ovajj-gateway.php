<?php
/**
 * WooCommerce gateway.
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ovajj hosted-checkout gateway.
 */
class Ovajj_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'ovajj';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'Ovajj', 'ovajj-payment-gateway' );
		$this->method_description = __( 'Redirect customers to the secure Ovajj hosted checkout.', 'ovajj-payment-gateway' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title', 'Ovajj' );
		$this->description  = $this->get_option( 'description' );
		$this->instructions = $this->get_option( 'instructions' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_api_ovajj_cancel', array( $this, 'handle_cancel' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'               => array(
				'title'   => __( 'Enable/Disable', 'ovajj-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Ovajj', 'ovajj-payment-gateway' ),
				'default' => 'no',
			),
			'title'                 => array(
				'title'       => __( 'Title', 'ovajj-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Shown to customers at checkout.', 'ovajj-payment-gateway' ),
				'default'     => 'Ovajj',
				'desc_tip'    => true,
			),
			'description'           => array(
				'title'   => __( 'Description', 'ovajj-payment-gateway' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with Ovajj. You will be redirected to Ovajj to complete your payment.', 'ovajj-payment-gateway' ),
			),
			'instructions'          => array(
				'title'       => __( 'Instructions', 'ovajj-payment-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Optional text shown under the description at checkout and on the thank-you page.', 'ovajj-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'environment'           => array(
				'title'       => __( 'Environment', 'ovajj-payment-gateway' ),
				'type'        => 'select',
				'options'     => array(
					'sandbox'    => __( 'Sandbox (test)', 'ovajj-payment-gateway' ),
					'production' => __( 'Production (live)', 'ovajj-payment-gateway' ),
				),
				'default'     => 'sandbox',
				'description' => __( 'Only the credentials for the selected environment are used. Credentials are never shared between environments.', 'ovajj-payment-gateway' ),
				'desc_tip'    => true,
			),
			'sandbox_title'         => array(
				'title'       => __( 'Sandbox credentials', 'ovajj-payment-gateway' ),
				'type'        => 'title',
				'description' => __( 'From Ovajj Dashboard → Merchant → Config (sandbox mode). Sandbox Merchant Key and API Key start with "test_".', 'ovajj-payment-gateway' ),
			),
			'sandbox_merchant_key'  => array(
				'title' => __( 'Sandbox Merchant Key', 'ovajj-payment-gateway' ),
				'type'  => 'text',
			),
			'sandbox_api_key'       => array(
				'title' => __( 'Sandbox API Key', 'ovajj-payment-gateway' ),
				'type'  => 'ovajj_secret',
			),
			'sandbox_api_secret'    => array(
				'title' => __( 'Sandbox API Secret (Client Secret)', 'ovajj-payment-gateway' ),
				'type'  => 'ovajj_secret',
			),
			'production_title'      => array(
				'title'       => __( 'Production credentials', 'ovajj-payment-gateway' ),
				'type'        => 'title',
				'description' => __( 'From Ovajj Dashboard → Merchant → Config (production mode). Requires an approved merchant account. Production keys have no "test_" prefix.', 'ovajj-payment-gateway' ),
			),
			'production_merchant_key' => array(
				'title' => __( 'Production Merchant Key', 'ovajj-payment-gateway' ),
				'type'  => 'text',
			),
			'production_api_key'    => array(
				'title' => __( 'Production API Key', 'ovajj-payment-gateway' ),
				'type'  => 'ovajj_secret',
			),
			'production_api_secret' => array(
				'title' => __( 'Production API Secret (Client Secret)', 'ovajj-payment-gateway' ),
				'type'  => 'ovajj_secret',
			),
			'currency_title'        => array(
				'title'       => __( 'Currency conversion', 'ovajj-payment-gateway' ),
				'type'        => 'title',
				/* translators: %s: site currency code */
				'description' => sprintf( __( 'Use this when your Ovajj merchant account has no wallet/gateway for your store currency. Site currency (from WooCommerce → Settings → General): %s. Orders already in the wallet currency are never converted.', 'ovajj-payment-gateway' ), function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ),
			),
			'convert_enabled'       => array(
				'title'   => __( 'Convert currency', 'ovajj-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Convert the order total to the Ovajj wallet currency before payment', 'ovajj-payment-gateway' ),
				'default' => 'no',
			),
			'wallet_currency'       => array(
				'title'       => __( 'Wallet currency', 'ovajj-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'The 3-letter currency of your Ovajj merchant wallet and gateway, for example USD.', 'ovajj-payment-gateway' ),
				'default'     => 'USD',
				'desc_tip'    => true,
			),
			'rate_mode'             => array(
				'title'   => __( 'Exchange rate', 'ovajj-payment-gateway' ),
				'type'    => 'select',
				'options' => array(
					'fixed' => __( 'Fixed rate (you set it)', 'ovajj-payment-gateway' ),
					'live'  => __( 'Live rate (free, updated daily)', 'ovajj-payment-gateway' ),
				),
				'default' => 'fixed',
			),
			'fixed_rate'            => array(
				'title'       => __( 'Fixed rate', 'ovajj-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Used in Fixed mode: how many units of your store currency equal 1 unit of the wallet currency. Example: store BDT, wallet USD → enter 120 for 1 USD = 120 BDT.', 'ovajj-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'rate_buffer'           => array(
				'title'       => __( 'Live rate safety margin (%)', 'ovajj-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Used in Live mode: lowers the rate by this percentage (0–20) so customers are charged slightly more of the wallet currency, protecting you from rate swings. Leave 0 for the exact rate.', 'ovajj-payment-gateway' ),
				'default'     => '0',
				'desc_tip'    => true,
			),
			'rate_info'             => array(
				'title' => __( 'Current rate', 'ovajj-payment-gateway' ),
				'type'  => 'ovajj_rate_info',
			),
			'options_title'         => array(
				'title' => __( 'Options', 'ovajj-payment-gateway' ),
				'type'  => 'title',
			),
			'allow_payment_methods' => array(
				'title'       => __( 'Allowed payment methods', 'ovajj-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Optional. Comma-separated gateway keywords/codes (for example "stripe,paypal") sent as allow_payment_methods. This only narrows what you already enabled in Ovajj → Config → Payment Gateway Controls. Leave empty to use the dashboard configuration.', 'ovajj-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'send_customer_data'    => array(
				'title'   => __( 'Customer details', 'ovajj-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Send customer name and email to Ovajj', 'ovajj-payment-gateway' ),
				'default' => 'yes',
			),
			'show_logo'             => array(
				'title'       => __( 'Logo', 'ovajj-payment-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Show the Ovajj logo returned by the Ovajj site-info API (cached when settings are saved)', 'ovajj-payment-gateway' ),
				'default'     => 'yes',
			),
			'order_ref_prefix'      => array(
				'title'       => __( 'Reference prefix', 'ovajj-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Letters and numbers only (max 12). Useful when several stores share one Ovajj merchant account.', 'ovajj-payment-gateway' ),
				'default'     => 'WC',
				'desc_tip'    => true,
			),
			'debug'                 => array(
				'title'       => __( 'Debug log', 'ovajj-payment-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable verbose logging (WooCommerce → Status → Logs, source ovajj-payment-gateway)', 'ovajj-payment-gateway' ),
				'description' => __( 'Errors and key payment events are always logged. Secrets are never logged.', 'ovajj-payment-gateway' ),
				'default'     => 'no',
			),
			'delete_data'           => array(
				'title'   => __( 'Uninstall', 'ovajj-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Delete these settings (including saved credentials) when the plugin is deleted', 'ovajj-payment-gateway' ),
				'default' => 'no',
			),
			'webhook_info'          => array(
				'title' => __( 'Webhook (IPN)', 'ovajj-payment-gateway' ),
				'type'  => 'ovajj_webhook_info',
			),
		);
	}

	/**
	 * Secret field: never prints the stored value.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field config.
	 * @return string
	 */
	public function generate_ovajj_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$has_value = '' !== (string) $this->get_option( $key, '' );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label></th>
			<td class="forminp">
				<input class="input-text regular-input" type="password" autocomplete="new-password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="" placeholder="<?php echo $has_value ? esc_attr__( 'Saved — leave blank to keep', 'ovajj-payment-gateway' ) : ''; ?>" />
				<?php if ( $has_value ) : ?>
					<p class="description"><?php esc_html_e( 'A value is saved. Enter a new value to replace it, or "-" to clear it.', 'ovajj-payment-gateway' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Validate a secret field. Blank keeps the stored value; "-" clears it.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 * @throws Exception On environment/credential mix-ups.
	 */
	public function validate_ovajj_secret_field( $key, $value ) {
		$value = is_null( $value ) ? '' : trim( wp_unslash( (string) $value ) );
		if ( '' === $value ) {
			return (string) $this->get_option( $key, '' );
		}
		if ( '-' === $value ) {
			return '';
		}
		$this->assert_key_prefix( $key, $value );
		return sanitize_text_field( $value );
	}

	/**
	 * Validate the merchant key text fields (prefix rules).
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 * @throws Exception On mix-ups.
	 */
	public function validate_text_field( $key, $value ) {
		$value = parent::validate_text_field( $key, $value );
		if ( in_array( $key, array( 'sandbox_merchant_key', 'production_merchant_key' ), true ) && '' !== $value ) {
			$this->assert_key_prefix( $key, $value );
		}
		if ( 'wallet_currency' === $key ) {
			$value = strtoupper( trim( $value ) );
			if ( ! preg_match( '/^[A-Z]{3}$/', $value ) ) {
				throw new Exception( __( 'Wallet currency must be a 3-letter currency code such as USD.', 'ovajj-payment-gateway' ) );
			}
		}
		if ( 'fixed_rate' === $key && '' !== $value ) {
			$value = str_replace( ',', '', trim( $value ) );
			if ( ! is_numeric( $value ) || (float) $value <= 0 || (float) $value > 1000000000 ) {
				throw new Exception( __( 'The fixed exchange rate must be a number greater than 0 (for example 120).', 'ovajj-payment-gateway' ) );
			}
			$value = (string) (float) $value;
		}
		if ( 'rate_buffer' === $key ) {
			$value = '' === trim( $value ) ? '0' : str_replace( ',', '.', trim( $value ) );
			if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > 20 ) {
				throw new Exception( __( 'The live rate safety margin must be between 0 and 20.', 'ovajj-payment-gateway' ) );
			}
		}
		return $value;
	}

	/**
	 * Sandbox keys must start with "test_"; production keys must not (per Ovajj docs).
	 * Filter ovajj_enforce_key_prefixes to disable.
	 *
	 * @param string $key   Field key.
	 * @param string $value Value.
	 * @throws Exception When the key looks like it belongs to the other environment.
	 */
	private function assert_key_prefix( string $key, string $value ): void {
		if ( ! apply_filters( 'ovajj_enforce_key_prefixes', true ) ) {
			return;
		}
		if ( ! preg_match( '/^(sandbox|production)_(merchant_key|api_key)$/', $key, $m ) ) {
			return; // API secrets have no documented prefix.
		}
		$is_test = 0 === strpos( $value, 'test_' );
		if ( 'production' === $m[1] && $is_test ) {
			throw new Exception( __( 'This looks like a sandbox key ("test_" prefix) but was entered in a production field. Not saved.', 'ovajj-payment-gateway' ) );
		}
		if ( 'sandbox' === $m[1] && ! $is_test ) {
			throw new Exception( __( 'Sandbox keys start with "test_". This value looks like a production key. Not saved.', 'ovajj-payment-gateway' ) );
		}
	}

	/**
	 * Webhook info row.
	 *
	 * @param string $key  Key.
	 * @param array  $data Data.
	 * @return string
	 */
	public function generate_ovajj_webhook_info_html( $key, $data ) {
		$last = get_option( 'ovajj_last_webhook' );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<input type="text" class="regular-input" readonly onfocus="this.select()" value="<?php echo esc_attr( Ovajj_Webhook::endpoint_url() ); ?>" />
				<p class="description"><?php esc_html_e( 'This URL is sent to Ovajj automatically as ipn_url with every payment, so no extra registration is needed. If your Ovajj dashboard asks for a webhook URL, use this one. It must be publicly reachable over HTTPS.', 'ovajj-payment-gateway' ); ?></p>
				<p class="description">
					<?php
					if ( is_array( $last ) && ! empty( $last['time'] ) ) {
						printf(
							/* translators: 1: time ago, 2: result code, 3: HTTP status */
							esc_html__( 'Last notification: %1$s ago — result "%2$s" (HTTP %3$d).', 'ovajj-payment-gateway' ),
							esc_html( human_time_diff( (int) $last['time'] ) ),
							esc_html( (string) ( $last['code'] ?? '' ) ),
							(int) ( $last['http'] ?? 0 )
						);
					} else {
						esc_html_e( 'No notification received yet.', 'ovajj-payment-gateway' );
					}
					?>
				</p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Current-rate row (reads the cache only, never makes a remote request).
	 *
	 * @param string $key  Key.
	 * @param array  $data Data.
	 * @return string
	 */
	public function generate_ovajj_rate_info_html( $key, $data ) {
		$site   = strtoupper( get_woocommerce_currency() );
		$wallet = Ovajj_Currency::wallet_currency();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp"><p>
			<?php
			if ( ! Ovajj_Currency::enabled() ) {
				esc_html_e( 'Conversion is off. Orders are sent to Ovajj in the store currency.', 'ovajj-payment-gateway' );
			} elseif ( $site === $wallet ) {
				esc_html_e( 'Store currency equals the wallet currency, so nothing is converted.', 'ovajj-payment-gateway' );
			} else {
				$r = Ovajj_Currency::get_rate( $site, $wallet, false );
				if ( is_wp_error( $r ) ) {
					echo esc_html( $r->get_error_message() ) . ' ' . esc_html__( 'Save the settings to fetch it.', 'ovajj-payment-gateway' );
				} else {
					echo '<strong>' . esc_html( Ovajj_Currency::rate_label( $r['rate'], $wallet, $site ) ) . '</strong> ';
					if ( 'live' === $r['source'] ) {
						/* translators: %s: time ago */
						echo esc_html( sprintf( __( '(live, fetched %s ago%s)', 'ovajj-payment-gateway' ), human_time_diff( (int) $r['time'] ), ! empty( $r['stale'] ) ? __( ', STALE', 'ovajj-payment-gateway' ) : '' ) );
					} else {
						echo esc_html__( '(fixed)', 'ovajj-payment-gateway' );
					}
				}
			}
			?>
			</p>
			<?php if ( Ovajj_Currency::enabled() && 'live' === Ovajj_Currency::mode() ) : ?>
				<p class="description"><a href="https://www.exchangerate-api.com" target="_blank" rel="noopener">Rates By Exchange Rate API</a></p>
			<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Cross-field checks for the conversion settings, run after saving.
	 */
	private function check_conversion_settings(): void {
		if ( ! Ovajj_Currency::enabled() ) {
			return;
		}
		$site   = strtoupper( get_woocommerce_currency() );
		$wallet = Ovajj_Currency::wallet_currency();
		if ( $site === $wallet ) {
			WC_Admin_Settings::add_message( __( 'Currency conversion is enabled but the store currency equals the wallet currency, so nothing will be converted.', 'ovajj-payment-gateway' ) );
			return;
		}
		if ( 'fixed' === Ovajj_Currency::mode() ) {
			if ( (float) Ovajj_Settings::get( 'fixed_rate', '0' ) <= 0 ) {
				WC_Admin_Settings::add_error( __( 'Fixed rate mode is selected but no fixed rate is set. Payments in your store currency will fail until you enter one.', 'ovajj-payment-gateway' ) );
			}
			return;
		}
		$r = Ovajj_Currency::refresh_live( $site, $wallet );
		if ( is_wp_error( $r ) ) {
			WC_Admin_Settings::add_error( sprintf( /* translators: %s: error */ __( 'Could not fetch the live exchange rate: %s', 'ovajj-payment-gateway' ), $r->get_error_message() ) );
		} else {
			WC_Admin_Settings::add_message( sprintf( /* translators: %s: rate */ __( 'Live exchange rate: %s', 'ovajj-payment-gateway' ), Ovajj_Currency::rate_label( $r['rate'], $wallet, $site ) ) );
		}
	}

	/**
	 * Text shown at checkout explaining the conversion (cached rate only; no remote calls while rendering).
	 *
	 * @return string
	 */
	private function conversion_notice(): string {
		$site  = strtoupper( get_woocommerce_currency() );
		$total = 0.0;
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$o = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			if ( $o instanceof WC_Order ) {
				$site  = strtoupper( $o->get_currency() );
				$total = (float) $o->get_total();
			}
		} elseif ( function_exists( 'WC' ) && WC()->cart ) {
			$total = (float) WC()->cart->get_total( 'edit' );
		}
		if ( ! Ovajj_Currency::needs_conversion( $site ) ) {
			return '';
		}
		$wallet = Ovajj_Currency::wallet_currency();
		$r      = Ovajj_Currency::get_rate( $site, $wallet, false );
		if ( is_wp_error( $r ) || $total <= 0 ) {
			/* translators: 1: wallet currency, 2: site currency */
			return sprintf( __( 'Payments are processed in %1$s. Your %2$s total is converted to %1$s at the exchange rate when you pay.', 'ovajj-payment-gateway' ), $wallet, $site );
		}
		/* translators: 1: wallet currency, 2: order total, 3: site currency, 4: converted amount, 5: rate label */
		return sprintf(
			__( 'Payments are processed in %1$s. Your total of %2$s %3$s will be charged as about %4$s %1$s (%5$s).', 'ovajj-payment-gateway' ),
			$wallet,
			number_format_i18n( $total, (int) wc_get_price_decimals() ),
			$site,
			number_format_i18n( round( $total / $r['rate'], Ovajj_Currency::wallet_decimals( $wallet ) ), Ovajj_Currency::wallet_decimals( $wallet ) ),
			Ovajj_Currency::rate_label( $r['rate'], $wallet, $site )
		);
	}

	/**
	 * Save settings, then validate the selected environment and refresh branding via site-info.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$this->check_conversion_settings();

		$env = Ovajj_Settings::environment();
		if ( 'yes' === $this->get_option( 'enabled' ) ) {
			if ( ! Ovajj_Settings::is_configured( $env ) ) {
				WC_Admin_Settings::add_error(
					sprintf(
						/* translators: %s: environment */
						__( 'Ovajj is enabled but the %s Merchant Key, API Key and API Secret are not all set. The payment method will not appear at checkout.', 'ovajj-payment-gateway' ),
						$env
					)
				);
				return $saved;
			}
			if ( 'production' === $env && ! is_ssl() && ! wc_checkout_is_https() ) {
				WC_Admin_Settings::add_error( __( 'Production mode requires an SSL certificate (HTTPS).', 'ovajj-payment-gateway' ) );
			}
		}

		if ( Ovajj_Settings::is_configured( $env ) ) {
			$info = Ovajj_API::from_settings( $env )->site_info();
			if ( is_wp_error( $info ) ) {
				WC_Admin_Settings::add_error( sprintf( /* translators: %s: error */ __( 'Could not authenticate with Ovajj (%s). Check that the keys and secret belong to the selected environment.', 'ovajj-payment-gateway' ), $info->get_error_message() ) );
			} else {
				$logo = isset( $info['site_logo'] ) && is_string( $info['site_logo'] ) ? esc_url_raw( $info['site_logo'], array( 'https' ) ) : '';
				update_option( 'ovajj_branding', array( 'logo' => $logo, 'name' => isset( $info['gateway_name'] ) && is_string( $info['gateway_name'] ) ? sanitize_text_field( $info['gateway_name'] ) : '' ), false );
				WC_Admin_Settings::add_message( sprintf( /* translators: %s: environment */ __( 'Connected to Ovajj (%s environment).', 'ovajj-payment-gateway' ), $env ) );
			}
		}
		return $saved;
	}

	/**
	 * Cached branding logo (only from the documented site-info response).
	 *
	 * @return string URL or ''.
	 */
	public static function logo_url(): string {
		if ( 'yes' !== Ovajj_Settings::get( 'show_logo', 'yes' ) ) {
			return '';
		}
		$b = get_option( 'ovajj_branding' );
		return is_array( $b ) && ! empty( $b['logo'] ) ? (string) $b['logo'] : '';
	}

	/**
	 * Gateway icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		$logo = self::logo_url();
		if ( '' === $logo ) {
			return '';
		}
		return apply_filters( 'woocommerce_gateway_icon', '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="max-height:24px;width:auto;" />', $this->id );
	}

	/**
	 * Hide the gateway until the active environment is fully configured.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && Ovajj_Settings::is_configured( Ovajj_Settings::environment() );
	}

	/**
	 * Checkout description / instructions / sandbox badge.
	 */
	public function payment_fields() {
		if ( 'sandbox' === Ovajj_Settings::environment() ) {
			echo '<p class="ovajj-sandbox-badge"><strong>' . esc_html__( 'SANDBOX MODE: no real money will be charged.', 'ovajj-payment-gateway' ) . '</strong></p>';
		}
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		$notice = $this->conversion_notice();
		if ( '' !== $notice ) {
			echo '<p class="ovajj-conversion-notice">' . esc_html( $notice ) . '</p>';
		}
		if ( $this->instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
		}
	}

	/**
	 * Create a hosted checkout session and return the redirect.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Order not found.', 'ovajj-payment-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$env      = Ovajj_Settings::environment();
		$api      = Ovajj_API::from_settings( $env );
		$site_dec = (int) wc_get_price_decimals();
		$site_cur = strtoupper( $order->get_currency() );
		$site_amt = round( (float) $order->get_total(), $site_dec );

		if ( ! $api->is_configured() ) {
			Ovajj_Logger::log( 'error', 'Payment attempted but credentials are incomplete', array( 'environment' => $env ) );
			wc_add_notice( __( 'This payment method is temporarily unavailable. Please choose another one.', 'ovajj-payment-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Amount/currency actually charged at Ovajj: the order's own, or converted to the wallet currency.
		$currency  = $site_cur;
		$amount    = $site_amt;
		$decimals  = $site_dec;
		$conv_text = '';
		$extra     = array(
			'site_amount'   => $site_amt,
			'site_currency' => $site_cur,
			'rate'          => 1.0,
			'converted'     => false,
			'decimals'      => $decimals,
		);
		if ( Ovajj_Currency::needs_conversion( $site_cur ) ) {
			$conv = Ovajj_Currency::convert( $site_amt, $site_cur );
			if ( is_wp_error( $conv ) ) {
				Ovajj_Logger::log( 'error', 'Currency conversion failed', array( 'order_id' => $order->get_id(), 'error' => $conv->get_error_message() ) );
				$order->add_order_note( 'Ovajj: currency conversion failed: ' . $conv->get_error_message() );
				wc_add_notice( __( 'We could not determine the exchange rate for your payment right now. Please try again in a few minutes or choose another payment method.', 'ovajj-payment-gateway' ), 'error' );
				return array( 'result' => 'failure' );
			}
			$currency  = $conv['wallet_currency'];
			$amount    = $conv['amount'];
			$decimals  = $conv['decimals'];
			$conv_text = sprintf( '%1$s %2$s = %3$s %4$s (%5$s, %6$s rate)', wc_format_decimal( $site_amt, $site_dec ), $site_cur, wc_format_decimal( $amount, $decimals ), $currency, Ovajj_Currency::rate_label( $conv['rate'], $currency, $site_cur ), $conv['rate_source'] );
			$extra     = array(
				'site_amount'   => $site_amt,
				'site_currency' => $site_cur,
				'rate'          => $conv['rate'],
				'rate_source'   => $conv['rate_source'],
				'converted'     => true,
				'decimals'      => $decimals,
			);
		}

		if ( $amount < 1.00 ) {
			wc_add_notice( __( 'Ovajj requires a minimum payment amount of 1.00. Please choose another payment method.', 'ovajj-payment-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$ref = Ovajj_Utils::generate_ref( $order->get_id(), (string) $this->get_option( 'order_ref_prefix', 'WC' ) );

		$payload = array(
			'payment_amount'   => $amount,
			'currency_code'    => $currency,
			'ref_trx'          => $ref,
			'description'      => mb_substr( sprintf( /* translators: 1: order number, 2: site name */ __( 'Order #%1$s at %2$s', 'ovajj-payment-gateway' ), $order->get_order_number(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . ( '' !== $conv_text ? ' - ' . $conv_text : '' ), 0, 190 ),
			'success_redirect' => $this->get_return_url( $order ),
			'cancel_redirect'  => add_query_arg(
				array(
					'order_id' => $order->get_id(),
					'key'      => $order->get_order_key(),
				),
				WC()->api_request_url( 'ovajj_cancel' )
			),
			'ipn_url'          => Ovajj_Webhook::endpoint_url(),
		);

		if ( 'yes' === $this->get_option( 'send_customer_data', 'yes' ) ) {
			$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$email = $order->get_billing_email();
			if ( '' !== $name ) {
				$payload['customer_name'] = mb_substr( $name, 0, 100 );
			}
			if ( is_email( $email ) ) {
				$payload['customer_email'] = mb_substr( $email, 0, 100 );
			}
		}

		$allowed = array_filter( array_map( static function ( $m ) {
			return preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( $m ) );
		}, explode( ',', (string) $this->get_option( 'allow_payment_methods', '' ) ) ) );
		if ( $allowed ) {
			$payload['allow_payment_methods'] = array_values( $allowed );
		}

		$response = $api->initiate_payment( $payload );

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( 'Ovajj could not start the payment: ' . $response->get_error_message() );
			wc_add_notice( Ovajj_API::customer_message( $response ), 'error' );
			return array( 'result' => 'failure' );
		}

		$url  = $response['payment_url'] ?? '';
		$info = isset( $response['info'] ) && is_array( $response['info'] ) ? $response['info'] : array();

		$problem = '';
		if ( ! Ovajj_Utils::is_valid_checkout_url( $url, $api->base_url() ) ) {
			$problem = 'payment_url missing or not an allowed https URL';
		} elseif ( isset( $info['ref_trx'] ) && $info['ref_trx'] !== $ref ) {
			$problem = 'response ref_trx does not match the request';
		} elseif ( isset( $info['environment'] ) && strtolower( (string) $info['environment'] ) !== $env ) {
			$problem = 'response environment does not match the selected environment';
		} elseif ( isset( $info['amount'] ) && ! Ovajj_Utils::amounts_match( $info['amount'], $amount, $decimals ) ) {
			$problem = 'response amount does not match the order total';
		} elseif ( isset( $info['currency_code'] ) && strtoupper( (string) $info['currency_code'] ) !== $currency ) {
			$problem = 'response currency does not match the order currency';
		}
		if ( '' !== $problem ) {
			Ovajj_Logger::log( 'error', 'Rejected initiate-payment response', array( 'order_id' => $order->get_id(), 'problem' => $problem, 'payment_url' => is_string( $url ) ? $url : '' ) );
			$order->add_order_note( 'Ovajj returned an invalid payment session (' . $problem . '). The customer was not redirected.' );
			wc_add_notice( __( 'We could not start your payment with Ovajj. Please try again or choose another payment method.', 'ovajj-payment-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		Ovajj_Processor::record_attempt( $order, $ref, $env, $amount, $currency, $extra );
		$order->add_order_note( sprintf( 'Ovajj payment session created%1$s. Reference: %2$s.%3$s Awaiting payment confirmation.', 'sandbox' === $env ? ' (sandbox)' : '', $ref, '' !== $conv_text ? ' Converted: ' . $conv_text . '.' : '' ) );
		Ovajj_Logger::log( 'notice', 'Payment session created', array( 'order_id' => $order->get_id(), 'environment' => $env ) );

		return array(
			'result'   => 'success',
			'redirect' => $url,
		);
	}

	/**
	 * Refund through Ovajj. WooCommerce records the refund when this returns true.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount in the order (site) currency.
	 * @param string     $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'ovajj_refund', __( 'Order not found.', 'ovajj-payment-gateway' ) );
		}
		$trx = (string) $order->get_meta( '_ovajj_trx_id' );
		if ( '' === $trx ) {
			return new WP_Error( 'ovajj_refund', __( 'The Ovajj transaction ID is not known for this order (it was not included in the payment notification), so it cannot be refunded here. Refund it from the Ovajj dashboard; the refund notification will then be recorded automatically.', 'ovajj-payment-gateway' ) );
		}
		$site_dec    = (int) wc_get_price_decimals();
		$site_refund = round( (float) $amount, $site_dec );
		if ( $site_refund <= 0 ) {
			return new WP_Error( 'ovajj_refund', __( 'Invalid refund amount.', 'ovajj-payment-gateway' ) );
		}

		// Use the attempt that was actually paid: its environment, charged currency and locked exchange rate.
		$attempts = Ovajj_Processor::attempts( $order );
		$paid_ref = (string) $order->get_meta( '_ovajj_paid_ref' );
		if ( '' === $paid_ref ) {
			$paid_ref = (string) $order->get_meta( '_ovajj_ref_trx' );
		}
		$attempt = isset( $attempts[ $paid_ref ] ) && is_array( $attempts[ $paid_ref ] ) ? $attempts[ $paid_ref ] : array();
		$env     = (string) ( $attempt['environment'] ?? $order->get_meta( '_ovajj_environment' ) );
		$env     = 'production' === $env ? 'production' : 'sandbox';
		$api     = Ovajj_API::from_settings( $env );
		if ( ! $api->is_configured() ) {
			/* translators: %s: environment */
			return new WP_Error( 'ovajj_refund', sprintf( __( 'Ovajj %s credentials are not configured; cannot refund this order.', 'ovajj-payment-gateway' ), $env ) );
		}

		$converted = ! empty( $attempt['converted'] );
		$chg_cur   = strtoupper( (string) ( $attempt['currency'] ?? $order->get_currency() ) );
		$chg_dec   = (int) ( $attempt['decimals'] ?? $site_dec );

		$processor = new Ovajj_Processor();
		if ( ! $processor->acquire_lock( $order->get_id() ) ) {
			return new WP_Error( 'ovajj_refund', __( 'This order is being processed. Please try again in a moment.', 'ovajj-payment-gateway' ) );
		}
		try {
			// Amount to refund at Ovajj, in the currency that was charged (converted at the rate locked at payment time).
			$wallet_amount = round( Ovajj_Processor::site_to_wallet_refund( $order, $attempt, $site_refund ), $chg_dec );
			if ( $wallet_amount <= 0 ) {
				return new WP_Error( 'ovajj_refund', __( 'Nothing is left to refund at Ovajj for this order.', 'ovajj-payment-gateway' ) );
			}

			// Deterministic reference: a retry of the same refund can never refund twice.
			$ref_refund = sprintf( 'WCR-%d-%d-%d', $order->get_id(), count( $order->get_refunds() ) + 1, (int) round( $site_refund * ( 10 ** $site_dec ) ) );
			$payload    = array(
				'trx_id'     => $trx,
				'amount'     => $wallet_amount,
				'ref_refund' => $ref_refund,
			);
			if ( '' !== (string) $reason ) {
				$payload['reason'] = mb_substr( (string) $reason, 0, 255 );
			}

			$response = $api->refund_payment( $payload );
			if ( is_wp_error( $response ) ) {
				$order->add_order_note( 'Ovajj refund failed: ' . $response->get_error_message() );
				return new WP_Error( 'ovajj_refund', $response->get_error_message() );
			}
			$refund_trx = isset( $response['refund_trx_id'] ) && is_string( $response['refund_trx_id'] ) ? $response['refund_trx_id'] : '';
			if ( 'success' !== ( $response['status'] ?? '' ) || '' === $refund_trx ) {
				Ovajj_Logger::log( 'error', 'Unexpected refund response', array( 'order_id' => $order->get_id() ) );
				return new WP_Error( 'ovajj_refund', __( 'Ovajj returned an unexpected refund response. Check the Ovajj dashboard before retrying.', 'ovajj-payment-gateway' ) );
			}
			Ovajj_Processor::record_refund( $order, $refund_trx, $ref_refund, $wallet_amount, 'wc' );
			$order->add_order_note(
				sprintf(
					'Refunded %1$s %2$s%3$s through Ovajj. Refund transaction: %4$s. Remaining refundable at Ovajj: %5$s. Note: Ovajj does not return the processing fee of the original payment.',
					wc_format_decimal( $site_refund, $site_dec ),
					$order->get_currency(),
					$converted ? sprintf( ' (%1$s %2$s at the rate locked at payment time)', wc_format_decimal( $wallet_amount, $chg_dec ), $chg_cur ) : '',
					$refund_trx,
					isset( $response['remaining_refund'] ) && is_numeric( $response['remaining_refund'] ) ? $response['remaining_refund'] . ' ' . $chg_cur : 'n/a'
				)
			);
			Ovajj_Logger::log( 'notice', 'Refund processed', array( 'order_id' => $order->get_id(), 'fully_refunded' => $response['fully_refunded'] ?? null ) );
			return true;
		} finally {
			$processor->release_lock( $order->get_id() );
		}
	}

	/**
	 * Thank-you page: explain pending confirmation (never marks anything paid).
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! $order->is_paid() && ! $order->has_status( 'failed' ) ) {
			echo '<p class="woocommerce-notice woocommerce-info">' . esc_html__( 'We are waiting for Ovajj to confirm your payment. This page will not change automatically; your order status will update as soon as the confirmation arrives.', 'ovajj-payment-gateway' ) . '</p>';
		}
		if ( $this->instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
		}
	}

	/**
	 * Customer returned via cancel_redirect. Adds a note and sends them back to retry; never changes status.
	 */
	public function handle_cancel() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticated by the order key below.
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
		$flag = 'ovajj_cancel_' . $order->get_id();
		if ( ! get_transient( $flag ) ) {
			$order->add_order_note( __( 'Customer returned from Ovajj without completing the payment (cancelled or could not continue).', 'ovajj-payment-gateway' ) );
			set_transient( $flag, 1, 5 * MINUTE_IN_SECONDS );
		}
		wc_add_notice( __( 'Your payment was cancelled or could not be completed. You can try again.', 'ovajj-payment-gateway' ), 'notice' );
		wp_safe_redirect( $order->needs_payment() ? $order->get_checkout_payment_url() : wc_get_cart_url() );
		exit;
	}
}
