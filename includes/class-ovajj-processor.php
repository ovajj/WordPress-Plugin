<?php
/**
 * Order reconciliation: turns verified Ovajj events into WooCommerce order state.
 *
 * @package OvajjPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent payment / refund processing.
 *
 * Order meta used (all underscore-prefixed, HPOS safe via the CRUD API):
 *  _ovajj_attempts        ref_trx => [environment, amount, currency, time] (one entry per payment attempt)
 *  _ovajj_ref_trx         latest ref_trx
 *  _ovajj_trx_id          Ovajj transaction ID (trx_id), when known
 *  _ovajj_paid            'yes' once completed by this plugin
 *  _ovajj_seen_events     hashes of processed IPN bodies (duplicate protection)
 *  _ovajj_refunds         refund_trx_id => [ref_refund, amount, source, time]
 *  _ovajj_pending_confirm data needed to re-verify a payment later (cron / manual)
 */
class Ovajj_Processor {

	const CRON_HOOK    = 'ovajj_reverify_order';
	const MAX_REVERIFY = 5;

	/** @var callable */
	private $api_factory;

	/**
	 * Constructor.
	 *
	 * @param callable|null $api_factory fn( string $environment ): Ovajj_API.
	 */
	public function __construct( ?callable $api_factory = null ) {
		$this->api_factory = $api_factory ?? static function ( string $env ) {
			return Ovajj_API::from_settings( $env );
		};
	}

	/**
	 * Standard result array.
	 */
	private static function res( int $http, string $code, string $message = '' ): array {
		return array( 'http' => $http, 'code' => $code, 'message' => $message );
	}

	/**
	 * Record a payment attempt on an order (called when a hosted checkout session is created).
	 */
	public static function record_attempt( WC_Order $order, string $ref, string $env, $amount, string $currency, array $extra = array() ): void {
		$attempts         = self::attempts( $order );
		$attempts[ $ref ] = array_merge(
			array(
				'environment' => $env,
				'amount'      => $amount,   // Amount charged at Ovajj (wallet currency when converted).
				'currency'    => $currency, // Currency charged at Ovajj.
				'time'        => time(),
			),
			$extra // site_amount, site_currency, rate, rate_source, converted, decimals.
		);
		$attempts         = array_slice( $attempts, -20, null, true );
		$order->update_meta_data( '_ovajj_attempts', $attempts );
		$order->update_meta_data( '_ovajj_ref_trx', $ref );
		$order->update_meta_data( '_ovajj_environment', $env );
		$order->save();
	}

	/**
	 * Attempts map.
	 */
	public static function attempts( WC_Order $order ): array {
		$a = $order->get_meta( '_ovajj_attempts' );
		return is_array( $a ) ? $a : array();
	}

	/**
	 * Locate an order from a merchant reference we issued.
	 */
	public function find_order( string $ref ): ?WC_Order {
		$id = Ovajj_Utils::order_id_from_ref( $ref );
		if ( $id < 1 ) {
			return null;
		}
		$order = wc_get_order( $id );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Handle a signature-verified IPN payload.
	 *
	 * @param string $env        Environment whose secret verified the signature.
	 * @param array  $payload    Decoded body.
	 * @param string $event_hash Hash of the raw body (duplicate protection).
	 * @return array{http:int,code:string,message:string}
	 */
	public function handle_ipn( string $env, array $payload, string $event_hash ): array {
		$data = $payload['data'] ?? null;
		if ( ! is_array( $data ) ) {
			return self::res( 400, 'malformed', 'Missing data object.' );
		}
		$ref = isset( $data['ref_trx'] ) && is_string( $data['ref_trx'] ) ? $data['ref_trx'] : '';
		if ( '' === $ref ) {
			return self::res( 400, 'malformed', 'Missing ref_trx.' );
		}
		$status = strtolower( is_string( $payload['status'] ?? null ) ? $payload['status'] : '' );
		$event  = is_string( $payload['event'] ?? null ) ? $payload['event'] : '';

		$order = $this->find_order( $ref );
		if ( ! $order ) {
			Ovajj_Logger::log( 'warning', 'IPN for unknown reference', array( 'ref_trx' => $ref ) );
			return self::res( 404, 'unknown_reference', 'Unknown reference.' );
		}
		$id       = $order->get_id();
		$attempts = self::attempts( $order );
		if ( ! isset( $attempts[ $ref ] ) ) {
			Ovajj_Logger::log( 'warning', 'IPN reference not issued by this store', array( 'order_id' => $id, 'ref_trx' => $ref ) );
			return self::res( 404, 'unknown_reference', 'Unknown reference.' );
		}
		if ( ( $attempts[ $ref ]['environment'] ?? '' ) !== $env ) {
			Ovajj_Logger::log( 'warning', 'IPN environment does not match the payment attempt', array( 'order_id' => $id, 'ipn_env' => $env, 'order_env' => $attempts[ $ref ]['environment'] ?? '' ) );
			return self::res( 400, 'environment_mismatch', 'Environment mismatch.' );
		}

		if ( ! $this->acquire_lock( $id ) ) {
			return self::res( 503, 'busy', 'Order is being processed, retry later.' );
		}
		try {
			// Reload under the lock so we act on current state.
			$fresh = wc_get_order( $id );
			$order = $fresh instanceof WC_Order ? $fresh : $order;

			$seen = $order->get_meta( '_ovajj_seen_events' );
			$seen = is_array( $seen ) ? $seen : array();
			if ( in_array( $event_hash, $seen, true ) ) {
				return self::res( 200, 'duplicate', 'Duplicate notification ignored.' );
			}

			if ( 'refunded' === $status || 'payment.refunded' === $event ) {
				$result = $this->on_refund( $order, $data, $attempts[ $ref ] );
			} elseif ( Ovajj_Utils::is_success_status( $status ) ) {
				$trx = $this->extract_trx_id( $data, $payload );
				$cur = strtoupper( (string) ( $data['currency_code'] ?? $data['currency'] ?? '' ) );
				$result = $this->confirm_success( $order, $ref, $attempts[ $ref ], $trx, $data['amount'] ?? null, $cur, 'ipn' );
			} elseif ( Ovajj_Utils::is_failure_status( $status ) ) {
				$result = $this->on_failure( $order, $ref, $status );
			} elseif ( 'pending' === $status ) {
				$order->add_order_note( sprintf( 'Ovajj reports the payment is pending (reference %s).', $ref ) );
				$result = self::res( 200, 'pending_recorded' );
			} else {
				Ovajj_Logger::log( 'notice', 'IPN with unrecognised status ignored', array( 'status' => $status, 'order_id' => $id ) );
				$result = self::res( 202, 'ignored', 'Unrecognised status.' );
			}

			if ( $result['http'] < 300 ) {
				$fresh2 = wc_get_order( $id );
				$o      = $fresh2 instanceof WC_Order ? $fresh2 : $order;
				$seen   = $o->get_meta( '_ovajj_seen_events' );
				$seen   = is_array( $seen ) ? $seen : array();
				$seen[] = $event_hash;
				$o->update_meta_data( '_ovajj_seen_events', array_slice( $seen, -30 ) );
				$o->save();
			}
			return $result;
		} finally {
			$this->release_lock( $id );
		}
	}

	/**
	 * Find the Ovajj transaction ID in an IPN. The documented payment IPN does not list one explicitly,
	 * so only explicit trx fields are used; ref_trx is never treated as trx_id.
	 */
	private function extract_trx_id( array $data, array $payload ): string {
		foreach ( array( $data['trx_id'] ?? null, $data['transaction_id'] ?? null, $payload['trx_id'] ?? null ) as $v ) {
			if ( is_string( $v ) && '' !== $v && strlen( $v ) <= 100 ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * Shared success path used by IPN, cron re-verification and the manual action.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $ref      ref_trx.
	 * @param array    $attempt  Attempt record.
	 * @param string   $trx_id   Ovajj trx_id or ''.
	 * @param mixed    $amount   Amount reported by the (signed) IPN.
	 * @param string   $currency Currency reported by the IPN.
	 * @param string   $source   ipn|cron|manual.
	 * @return array
	 */
	public function confirm_success( WC_Order $order, string $ref, array $attempt, string $trx_id, $amount, string $currency, string $source ): array {
		$env      = (string) ( $attempt['environment'] ?? '' );
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$cur_o    = strtoupper( (string) $order->get_currency() );
		// What was actually charged at Ovajj (wallet currency when converted) vs. what the order is in.
		$chg_cur  = strtoupper( (string) ( $attempt['currency'] ?? $cur_o ) );
		$chg_amt  = $attempt['amount'] ?? null;
		$chg_dec  = (int) ( $attempt['decimals'] ?? $decimals );
		$site_cur = strtoupper( (string) ( $attempt['site_currency'] ?? $chg_cur ) );
		$site_amt = $attempt['site_amount'] ?? $chg_amt;

		// Already paid: never overwrite or re-complete.
		if ( $order->is_paid() || 'yes' === $order->get_meta( '_ovajj_paid' ) ) {
			$stored = (string) $order->get_meta( '_ovajj_trx_id' );
			$same   = ( '' !== $stored && $trx_id === $stored ) || ( 'yes' === $order->get_meta( '_ovajj_paid' ) && '' === $trx_id );
			if ( ! $same ) {
				$order->add_order_note( sprintf( 'Ovajj reported a successful payment (reference %1$s, transaction %2$s) for an order that is already paid. Review for a possible duplicate payment.', $ref, '' !== $trx_id ? $trx_id : 'n/a' ) );
				Ovajj_Logger::log( 'warning', 'Success notification for an already-paid order', array( 'order_id' => $order->get_id() ) );
			}
			$this->clear_pending( $order );
			return self::res( 200, 'already_paid' );
		}

		// Reconcile currency and amount against what we asked for AND the current order.
		$bad = array();
		if ( '' === $currency || $currency !== $chg_cur || $cur_o !== $site_cur ) {
			$bad[] = 'currency';
		}
		// The IPN must report exactly what we charged, and the order must not have changed since the session was created.
		if ( ! Ovajj_Utils::amounts_match( $amount, $chg_amt, $chg_dec ) || ! Ovajj_Utils::amounts_match( $order->get_total(), $site_amt, $decimals ) ) {
			$bad[] = 'amount';
		}
		if ( $bad ) {
			return $this->hold(
				$order,
				sprintf( 'Ovajj reported a successful payment but the %1$s did not match. Expected %2$s %3$s (order total %4$s %5$s), notification said %6$s %7$s (reference %8$s). Order placed on hold for manual review.', implode( ' and ', $bad ), is_scalar( $chg_amt ) ? $chg_amt : 'n/a', $chg_cur, $order->get_total(), $cur_o, is_scalar( $amount ) ? $amount : 'n/a', '' !== $currency ? $currency : 'n/a', $ref ),
				422,
				'reconciliation_failed'
			);
		}

		$verified = false;
		$fee_note = '';
		if ( '' !== $trx_id ) {
			$api = ( $this->api_factory )( $env );
			$v   = $api->verify_payment( $trx_id );

			if ( is_wp_error( $v ) ) {
				$d         = $v->get_error_data();
				$transient = is_array( $d ) && ! empty( $d['transient'] );
				$this->store_pending( $order, $ref, $trx_id, $amount, $currency );
				Ovajj_Logger::log( 'warning', 'Payment verification failed', array( 'order_id' => $order->get_id(), 'error' => $v->get_error_message(), 'transient' => $transient ) );
				if ( $transient ) {
					$this->schedule_reverify( $order, $ref );
					return self::res( 503, 'verify_unavailable', 'Verification temporarily unavailable.' );
				}
				$order->add_order_note( 'Ovajj payment verification failed: ' . $v->get_error_message() . ' The order was not marked as paid. Use "Verify payment with Ovajj" to retry.' );
				return self::res( 502, 'verify_failed', 'Verification failed.' );
			}

			$vs = strtolower( (string) ( $v['status'] ?? '' ) );
			Ovajj_Logger::log( 'notice', 'Payment verification result', array( 'order_id' => $order->get_id(), 'status' => $vs ) );

			if ( 'pending' === $vs ) {
				$this->store_pending( $order, $ref, $trx_id, $amount, $currency );
				$this->schedule_reverify( $order, $ref );
				return self::res( 202, 'pending_verification', 'Payment still pending at Ovajj.' );
			}
			if ( ! Ovajj_Utils::is_success_status( $vs ) ) {
				$this->clear_pending( $order );
				return $this->hold( $order, sprintf( 'The Ovajj notification said "completed" but verification returned status "%1$s" (transaction %2$s). Order placed on hold for manual review.', $vs, $trx_id ), 409, 'verify_mismatch' );
			}

			$vc  = strtoupper( (string) ( $v['currency'] ?? '' ) );
			$va  = $v['amount'] ?? null;
			$fee = $v['fee'] ?? 0;
			// The documented verify example reports amount net of fee (237.5 + 12.5 fee = 250), so accept either form.
			$amount_ok = Ovajj_Utils::amounts_match( $va, $chg_amt, $chg_dec )
				|| ( is_numeric( $va ) && is_numeric( $fee ) && Ovajj_Utils::amounts_match( (float) $va + (float) $fee, $chg_amt, $chg_dec ) );
			if ( $vc !== $chg_cur || ! $amount_ok ) {
				$this->clear_pending( $order );
				return $this->hold( $order, sprintf( 'Ovajj verification amount/currency did not match the order (transaction %1$s: %2$s %3$s, fee %4$s). Order placed on hold for manual review.', $trx_id, is_scalar( $va ) ? $va : 'n/a', $vc, is_scalar( $fee ) ? $fee : 'n/a' ), 422, 'verify_reconciliation_failed' );
			}
			$verified = true;
			$fee_note = is_numeric( $fee ) && (float) $fee > 0 ? ' Gateway fee: ' . $fee . ' ' . $chg_cur . '.' : '';
		}

		if ( ! empty( $attempt['converted'] ) ) {
			$fee_note .= sprintf( ' Charged %1$s %2$s for an order of %3$s %4$s (%5$s, rate source: %6$s).', $chg_amt, $chg_cur, $site_amt, $site_cur, Ovajj_Currency::rate_label( (float) ( $attempt['rate'] ?? 0 ), $chg_cur, $site_cur ), (string) ( $attempt['rate_source'] ?? 'n/a' ) );
		}

		$this->clear_pending( $order );
		if ( '' !== $trx_id ) {
			$order->update_meta_data( '_ovajj_trx_id', $trx_id );
		}
		$order->update_meta_data( '_ovajj_paid', 'yes' );
		$order->update_meta_data( '_ovajj_paid_ref', $ref );
		$order->save();

		$order->add_order_note(
			sprintf(
				'Ovajj payment completed%1$s via %2$s. Transaction ID: %3$s. Reference: %4$s.%5$s%6$s',
				'sandbox' === $env ? ' (SANDBOX TEST)' : '',
				$source,
				'' !== $trx_id ? $trx_id : 'not provided',
				$ref,
				$verified ? ' Verified with the Ovajj API.' : ' Confirmed by signed IPN (no transaction ID in the notification, so API verification was not possible).',
				$fee_note
			)
		);
		$order->payment_complete( '' !== $trx_id ? $trx_id : $ref );
		Ovajj_Logger::log( 'notice', 'Order payment completed', array( 'order_id' => $order->get_id(), 'verified' => $verified, 'environment' => $env ) );
		return self::res( 200, 'completed' );
	}

	/**
	 * Failed / cancelled / expired.
	 */
	private function on_failure( WC_Order $order, string $ref, string $status ): array {
		if ( $order->is_paid() || 'yes' === $order->get_meta( '_ovajj_paid' ) ) {
			Ovajj_Logger::log( 'notice', 'Ignoring failure notification for a paid order', array( 'order_id' => $order->get_id(), 'status' => $status ) );
			return self::res( 200, 'ignored_paid' );
		}
		$note   = sprintf( 'Ovajj reported the payment as %1$s (reference %2$s).', $status, $ref );
		$latest = (string) $order->get_meta( '_ovajj_ref_trx' );
		if ( $ref === $latest && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			$order->update_status( 'failed', $note );
		} else {
			$order->add_order_note( $note . ' No status change (older attempt or order not awaiting payment).' );
		}
		return self::res( 200, 'failure_recorded' );
	}

	/**
	 * Refund IPN (payment.refunded).
	 */
	private function on_refund( WC_Order $order, array $data, array $attempt = array() ): array {
		$refund_trx = is_string( $data['refund_trx_id'] ?? null ) ? $data['refund_trx_id'] : '';
		$original   = is_string( $data['original_trx_id'] ?? null ) ? $data['original_trx_id'] : '';
		$ref_refund = is_string( $data['ref_refund'] ?? null ) ? $data['ref_refund'] : '';
		$currency   = strtoupper( (string) ( $data['currency'] ?? $data['currency_code'] ?? '' ) );
		$amount     = $data['refunded_amount'] ?? null;
		$chg_cur    = strtoupper( (string) ( $attempt['currency'] ?? $order->get_currency() ) );

		if ( '' === $refund_trx || ! is_numeric( $amount ) || (float) $amount <= 0 ) {
			return self::res( 400, 'malformed_refund', 'Invalid refund payload.' );
		}
		// The refund is expressed in the currency that was charged at Ovajj.
		if ( '' !== $currency && $currency !== $chg_cur ) {
			$order->add_order_note( 'Ovajj refund notification ignored: currency mismatch.' );
			return self::res( 422, 'refund_currency_mismatch' );
		}
		$stored = (string) $order->get_meta( '_ovajj_trx_id' );
		if ( '' !== $original && '' !== $stored && $original !== $stored ) {
			$order->add_order_note( 'Ovajj refund notification ignored: transaction ID does not match this order.' );
			return self::res( 409, 'trx_mismatch' );
		}
		if ( '' === $stored && '' !== $original ) {
			$order->update_meta_data( '_ovajj_trx_id', $original );
		}

		$recorded = $order->get_meta( '_ovajj_refunds' );
		$recorded = is_array( $recorded ) ? $recorded : array();
		if ( isset( $recorded[ $refund_trx ] ) ) {
			return self::res( 200, 'duplicate_refund' );
		}
		if ( '' !== $ref_refund ) {
			foreach ( $recorded as $r ) {
				if ( ( $r['ref_refund'] ?? '' ) === $ref_refund ) {
					return self::res( 200, 'duplicate_refund' );
				}
			}
		}
		if ( ! $order->is_paid() ) {
			$order->add_order_note( sprintf( 'Ovajj refund notification (%s) ignored: the order is not in a paid state.', $refund_trx ) );
			return self::res( 202, 'refund_ignored' );
		}

		$wallet_amount    = (float) $amount;
		$converted        = ! empty( $attempt['converted'] );
		$wallet_remaining = $converted
			? round( (float) $attempt['amount'] - self::refunded_wallet_total( $order ), (int) ( $attempt['decimals'] ?? 2 ) )
			: (float) $order->get_remaining_refund_amount();
		$site_amount      = self::wallet_to_site_refund( $order, $attempt, $wallet_amount );
		if ( $wallet_amount > $wallet_remaining + 0.00001 || $site_amount <= 0 || $site_amount > (float) $order->get_remaining_refund_amount() + 0.00001 ) {
			$order->add_order_note( sprintf( 'Ovajj refund notification (%1$s, %2$s %3$s) exceeds the remaining refundable amount. Not recorded; review manually.', $refund_trx, $amount, $chg_cur ) );
			return self::res( 422, 'refund_exceeds_remaining' );
		}

		// Refund created outside WooCommerce (e.g. from the Ovajj dashboard): mirror it without calling the gateway again.
		$refund = wc_create_refund(
			array(
				'amount'         => $site_amount,
				'reason'         => 'Refunded via Ovajj (' . $refund_trx . ')',
				'order_id'       => $order->get_id(),
				'refund_payment' => false,
			)
		);
		if ( is_wp_error( $refund ) ) {
			Ovajj_Logger::log( 'error', 'Could not create WooCommerce refund from IPN', array( 'order_id' => $order->get_id(), 'error' => $refund->get_error_message() ) );
			$order->add_order_note( 'Ovajj refund notification received but the WooCommerce refund could not be created: ' . $refund->get_error_message() );
			return self::res( 500, 'refund_create_failed' );
		}
		self::record_refund( $order, $refund_trx, $ref_refund, $wallet_amount, 'ipn' );
		$order->add_order_note(
			sprintf(
				'Refund of %1$s %2$s recorded from Ovajj notification (refund transaction %3$s)%4$s.',
				$site_amount,
				$order->get_currency(),
				$refund_trx,
				$converted ? sprintf( ', %1$s %2$s refunded at Ovajj', $wallet_amount, $chg_cur ) : ''
			)
		);
		Ovajj_Logger::log( 'notice', 'Refund recorded from IPN', array( 'order_id' => $order->get_id() ) );
		return self::res( 200, 'refund_recorded' );
	}

	/**
	 * Sum of refunds already made at Ovajj, in the charged (wallet) currency.
	 */
	public static function refunded_wallet_total( WC_Order $order ): float {
		$recorded = $order->get_meta( '_ovajj_refunds' );
		$sum      = 0.0;
		foreach ( is_array( $recorded ) ? $recorded : array() as $r ) {
			$sum += (float) ( $r['amount'] ?? 0 );
		}
		return $sum;
	}

	/**
	 * Convert a WooCommerce (site currency) refund amount to the charged currency, using the rate locked at payment time.
	 * A refund of everything still refundable returns exactly the remaining wallet balance, so rounding never leaves dust.
	 */
	public static function site_to_wallet_refund( WC_Order $order, array $attempt, float $site_refund ): float {
		if ( empty( $attempt['converted'] ) ) {
			return $site_refund;
		}
		$cdec             = (int) ( $attempt['decimals'] ?? 2 );
		$sdec             = (int) wc_get_price_decimals();
		$wallet_total     = (float) $attempt['amount'];
		$site_total       = (float) ( $attempt['site_amount'] ?? 0 );
		$wallet_remaining = max( 0.0, round( $wallet_total - self::refunded_wallet_total( $order ), $cdec ) );
		$site_remaining   = (float) $order->get_remaining_refund_amount();
		if ( $site_total <= 0 || $wallet_total <= 0 ) {
			return 0.0;
		}
		if ( abs( $site_refund - $site_remaining ) < 0.5 * ( 10 ** -$sdec ) ) {
			return $wallet_remaining;
		}
		return min( round( $site_refund * $wallet_total / $site_total, $cdec ), $wallet_remaining );
	}

	/**
	 * Convert a refund reported by Ovajj (charged currency) to the order currency.
	 */
	public static function wallet_to_site_refund( WC_Order $order, array $attempt, float $wallet_refund ): float {
		if ( empty( $attempt['converted'] ) ) {
			return $wallet_refund;
		}
		$cdec             = (int) ( $attempt['decimals'] ?? 2 );
		$sdec             = (int) wc_get_price_decimals();
		$wallet_total     = (float) $attempt['amount'];
		$site_total       = (float) ( $attempt['site_amount'] ?? 0 );
		$wallet_remaining = round( $wallet_total - self::refunded_wallet_total( $order ), $cdec );
		$site_remaining   = (float) $order->get_remaining_refund_amount();
		if ( $site_total <= 0 || $wallet_total <= 0 ) {
			return 0.0;
		}
		if ( abs( $wallet_refund - $wallet_remaining ) < 0.5 * ( 10 ** -$cdec ) ) {
			return $site_remaining;
		}
		return min( round( $wallet_refund * $site_total / $wallet_total, $sdec ), $site_remaining );
	}

	/**
	 * Record a refund so later IPNs for it are treated as duplicates.
	 */
	public static function record_refund( WC_Order $order, string $refund_trx, string $ref_refund, float $amount, string $source ): void {
		$recorded = $order->get_meta( '_ovajj_refunds' );
		$recorded = is_array( $recorded ) ? $recorded : array();

		$recorded[ $refund_trx ] = array(
			'ref_refund' => $ref_refund,
			'amount'     => $amount,
			'source'     => $source,
			'time'       => time(),
		);
		$order->update_meta_data( '_ovajj_refunds', $recorded );
		$order->save();
	}

	/**
	 * Put an order on hold with an explanatory note.
	 */
	private function hold( WC_Order $order, string $note, int $http, string $code ): array {
		if ( ! $order->is_paid() && ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
		Ovajj_Logger::log( 'warning', 'Payment reconciliation problem', array( 'order_id' => $order->get_id(), 'code' => $code ) );
		return self::res( $http, $code );
	}

	/**
	 * Remember what is needed to re-verify later.
	 */
	private function store_pending( WC_Order $order, string $ref, string $trx_id, $amount, string $currency ): void {
		$p = $order->get_meta( '_ovajj_pending_confirm' );
		$p = is_array( $p ) ? $p : array();
		$order->update_meta_data(
			'_ovajj_pending_confirm',
			array(
				'ref'      => $ref,
				'trx_id'   => $trx_id,
				'amount'   => $amount,
				'currency' => $currency,
				'attempts' => (int) ( $p['attempts'] ?? 0 ),
			)
		);
		$order->save();
	}

	/**
	 * Clear the pending re-verification record.
	 */
	private function clear_pending( WC_Order $order ): void {
		if ( $order->get_meta( '_ovajj_pending_confirm' ) ) {
			$order->delete_meta_data( '_ovajj_pending_confirm' );
			$order->save();
		}
	}

	/**
	 * Schedule another verification attempt (backoff: 2, 4, 6, 8, 10 minutes).
	 */
	private function schedule_reverify( WC_Order $order, string $ref ): void {
		$p = $order->get_meta( '_ovajj_pending_confirm' );
		$p = is_array( $p ) ? $p : array();
		$n = (int) ( $p['attempts'] ?? 0 ) + 1;
		$p['attempts'] = $n;
		$order->update_meta_data( '_ovajj_pending_confirm', $p );
		$order->save();
		if ( $n > self::MAX_REVERIFY ) {
			$order->add_order_note( 'Ovajj payment could not be verified automatically. Use "Verify payment with Ovajj" in the order actions to retry.' );
			return;
		}
		wp_schedule_single_event( time() + 120 * $n, self::CRON_HOOK, array( $order->get_id(), $ref ) );
	}

	/**
	 * Cron callback.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $ref      ref_trx.
	 */
	public function cron_reverify( $order_id, $ref ): void {
		$order_id = (int) $order_id;
		$ref      = (string) $ref;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$p = $order->get_meta( '_ovajj_pending_confirm' );
		if ( ! is_array( $p ) || empty( $p['trx_id'] ) || ( $p['ref'] ?? '' ) !== $ref ) {
			return;
		}
		$attempts = self::attempts( $order );
		if ( ! isset( $attempts[ $ref ] ) ) {
			return;
		}
		if ( ! $this->acquire_lock( $order_id ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK, array( $order_id, $ref ) );
			return;
		}
		try {
			$fresh = wc_get_order( $order_id );
			$order = $fresh instanceof WC_Order ? $fresh : $order;
			$this->confirm_success( $order, $ref, $attempts[ $ref ], (string) $p['trx_id'], $p['amount'] ?? null, (string) ( $p['currency'] ?? '' ), 'cron' );
		} finally {
			$this->release_lock( $order_id );
		}
	}

	/**
	 * Manual "Verify payment with Ovajj" order action.
	 *
	 * @param WC_Order $order Order.
	 * @return string Message for the admin (also saved as an order note).
	 */
	public function manual_verify( WC_Order $order ): string {
		$p   = $order->get_meta( '_ovajj_pending_confirm' );
		$id  = $order->get_id();
		if ( is_array( $p ) && ! empty( $p['trx_id'] ) && ! empty( $p['ref'] ) ) {
			$attempts = self::attempts( $order );
			if ( isset( $attempts[ $p['ref'] ] ) && $this->acquire_lock( $id ) ) {
				try {
					$fresh = wc_get_order( $id );
					$o     = $fresh instanceof WC_Order ? $fresh : $order;
					$r     = $this->confirm_success( $o, (string) $p['ref'], $attempts[ $p['ref'] ], (string) $p['trx_id'], $p['amount'] ?? null, (string) ( $p['currency'] ?? '' ), 'manual' );
				} finally {
					$this->release_lock( $id );
				}
				return 'Manual verification result: ' . $r['code'];
			}
		}
		$trx = (string) $order->get_meta( '_ovajj_trx_id' );
		if ( '' === $trx ) {
			$msg = 'No Ovajj transaction ID has been received for this order yet, so it cannot be verified through the API.';
			$order->add_order_note( $msg );
			return $msg;
		}
		$env = (string) $order->get_meta( '_ovajj_environment' );
		$v   = ( $this->api_factory )( '' !== $env ? $env : 'sandbox' )->verify_payment( $trx );
		$msg = is_wp_error( $v ) ? 'Ovajj verification failed: ' . $v->get_error_message() : 'Ovajj reports status "' . ( $v['status'] ?? 'unknown' ) . '" for transaction ' . $trx . '.';
		$order->add_order_note( $msg );
		return $msg;
	}

	/**
	 * Atomic per-order lock (INSERT IGNORE, the same technique WordPress core uses for upgrade locks).
	 */
	public function acquire_lock( int $order_id ): bool {
		global $wpdb;
		$name = 'ovajj_lock_' . $order_id;
		for ( $i = 0; $i < 2; $i++ ) {
			$now = time();
			$ins = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* OVAJJ LOCK */", $name, (string) ( $now + 30 ) ) );
			if ( $ins ) {
				return true;
			}
			$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name ) );
			if ( $held < $now ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // Stale or vanished lock; retry once.
				continue;
			}
			return false;
		}
		return false;
	}

	/**
	 * Release the per-order lock.
	 */
	public function release_lock( int $order_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'ovajj_lock_' . $order_id ) );
	}
}
