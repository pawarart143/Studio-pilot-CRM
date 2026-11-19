<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * ShootPilot's own subscription billing through Razorpay Subscriptions.
 *
 * These are the PLATFORM keys (the account that receives ShootPilot's
 * subscription money). They are set by the super admin in Super Admin →
 * Subscription billing, or in wp-config.php with PCRM_BILLING_KEY_ID,
 * PCRM_BILLING_KEY_SECRET and PCRM_BILLING_WEBHOOK_SECRET. They are never the
 * per-studio keys in Settings → Online payments (rzp_key_id / rzp_key_secret),
 * which collect the studio's own client payments.
 */
class Billing {

	const API = 'https://api.razorpay.com/v1/';

	/** Number of billing cycles a subscription runs for before Razorpay marks it completed. */
	const TOTAL_COUNT = array(
		'monthly' => 120,
		'yearly'  => 10,
	);

	public static function key_id() {
		return defined( 'PCRM_BILLING_KEY_ID' ) ? (string) PCRM_BILLING_KEY_ID : (string) get_option( 'pcrm_billing_key_id', '' );
	}

	public static function key_secret() {
		return defined( 'PCRM_BILLING_KEY_SECRET' ) ? (string) PCRM_BILLING_KEY_SECRET : (string) get_option( 'pcrm_billing_key_secret', '' );
	}

	public static function webhook_secret() {
		return defined( 'PCRM_BILLING_WEBHOOK_SECRET' ) ? (string) PCRM_BILLING_WEBHOOK_SECRET : (string) get_option( 'pcrm_billing_webhook_secret', '' );
	}

	public static function from_constants() {
		return defined( 'PCRM_BILLING_KEY_ID' ) || defined( 'PCRM_BILLING_KEY_SECRET' );
	}

	public static function configured() {
		return '' !== self::key_id() && '' !== self::key_secret();
	}

	/** 'test' for rzp_test_ keys, 'live' for rzp_live_ keys. */
	public static function mode() {
		return 0 === strpos( self::key_id(), 'rzp_live_' ) ? 'live' : 'test';
	}

	public static function webhook_url() {
		return rest_url( 'pcrm/v1/billing/webhook' );
	}

	public static function cycles() {
		return array(
			'monthly' => 'Monthly',
			'yearly'  => 'Yearly',
		);
	}

	public static function price( $plan_key, $cycle ) {
		$p = Plans::get( $plan_key );
		return (int) ( 'yearly' === $cycle ? $p['price_yearly'] : $p['price'] );
	}

	/** Calls the Razorpay API with the platform keys. Returns the decoded body or WP_Error. */
	public static function api( $method, $path, $body = null ) {
		if ( ! self::configured() ) {
			return new \WP_Error( 'billing_not_configured', 'Online subscription payments are not set up yet. Please contact ShootPilot support to upgrade.', array( 'status' => 503 ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( self::key_id() . ':' . self::key_secret() ), // phpcs:ignore
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'gateway_error', 'Could not reach Razorpay: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 300 ) {
			$msg = isset( $data['error']['description'] ) ? $data['error']['description'] : 'HTTP ' . $code;
			if ( 401 === $code ) {
				$msg = 'the platform API keys were rejected. Check them in Super Admin → Subscription billing.';
			}
			error_log( '[pcrm billing] ' . $method . ' ' . $path . ' → ' . $code . ' ' . $msg );
			return new \WP_Error( 'gateway_error', 'Razorpay: ' . $msg, array( 'status' => 502 ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Razorpay plan id for a ShootPilot plan and cycle. Created on Razorpay the
	 * first time it is needed and remembered per key + price, so test and live
	 * keys, and a later price change, each get their own Razorpay plan.
	 */
	public static function gateway_plan_id( $plan_key, $cycle ) {
		$amount = self::price( $plan_key, $cycle );
		$cache  = (array) get_option( 'pcrm_billing_gateway_plans', array() );
		$ck     = implode( '|', array( self::key_id(), $plan_key, $cycle, $amount ) );
		if ( ! empty( $cache[ $ck ] ) ) {
			return $cache[ $ck ];
		}
		$p   = Plans::get( $plan_key );
		$res = self::api(
			'POST',
			'plans',
			array(
				'period'   => $cycle,
				'interval' => 1,
				'item'     => array(
					'name'        => 'ShootPilot ' . $p['label'] . ' (' . self::cycles()[ $cycle ] . ')',
					'amount'      => $amount * 100,
					'currency'    => 'INR',
					'description' => 'ShootPilot ' . $p['label'] . ' plan, billed ' . strtolower( self::cycles()[ $cycle ] ),
				),
				'notes'    => array(
					'shootpilot_plan' => $plan_key,
					'cycle'           => $cycle,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['id'] ) ) {
			return new \WP_Error( 'gateway_error', 'Razorpay did not return a plan.', array( 'status' => 502 ) );
		}
		$cache[ $ck ] = sanitize_text_field( $res['id'] );
		update_option( 'pcrm_billing_gateway_plans', $cache, false );
		return $cache[ $ck ];
	}

	public static function t() {
		return Installer::table( 'subscriptions' );
	}

	public static function find_sub( $gateway_id ) {
		global $wpdb;
		$t = self::t();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE gateway_subscription_id=%s", $gateway_id ) ); // phpcs:ignore
	}

	public static function sub( $id ) {
		global $wpdb;
		$t = self::t();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $id ) ); // phpcs:ignore
	}

	/** The subscription currently paying for the studio's plan, if any. */
	public static function current_sub( $studio ) {
		if ( empty( $studio->subscription_id ) ) {
			return null;
		}
		$s = self::sub( (int) $studio->subscription_id );
		return $s && (int) $s->studio_id === (int) $studio->id ? $s : null;
	}

	/**
	 * Starts a Razorpay subscription for the studio. Returns the data the
	 * browser needs to open Razorpay Checkout.
	 */
	public static function start_checkout( $studio, $plan_key, $cycle ) {
		global $wpdb;
		$plan_key = Plans::normalize( $plan_key );
		if ( ! Plans::exists( $plan_key ) || ! isset( self::cycles()[ $cycle ] ) ) {
			return new \WP_Error( 'validation_failed', 'Choose a plan and a billing period.', array( 'status' => 422 ) );
		}
		$amount = self::price( $plan_key, $cycle );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'validation_failed', 'That plan has no price to pay.', array( 'status' => 422 ) );
		}
		$cur    = self::current_sub( $studio );
		$status = Plans::status( $studio );
		if ( $cur && $cur->plan_key === $plan_key && $cur->billing_cycle === $cycle && in_array( $status['state'], array( 'active', 'past_due' ), true ) && empty( $cur->cancel_requested_at ) ) {
			return new \WP_Error( 'validation_failed', 'You are already on this plan.', array( 'status' => 422 ) );
		}
		$gplan = self::gateway_plan_id( $plan_key, $cycle );
		if ( is_wp_error( $gplan ) ) {
			return $gplan;
		}
		$body = array(
			'plan_id'         => $gplan,
			'total_count'     => self::TOTAL_COUNT[ $cycle ],
			'quantity'        => 1,
			'customer_notify' => 1,
			'notes'           => array(
				'studio_id'       => (string) $studio->id,
				'studio_name'     => mb_substr( (string) $studio->name, 0, 200 ),
				'shootpilot_plan' => $plan_key,
				'cycle'           => $cycle,
				'site'            => home_url( '/' ),
			),
		);
		// A new subscription is charged straight away. When it activates, any older one is cancelled (no proration).
		$res = self::api( 'POST', 'subscriptions', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['id'] ) ) {
			return new \WP_Error( 'gateway_error', 'Razorpay did not return a subscription.', array( 'status' => 502 ) );
		}
		$now = Db::now();
		$wpdb->insert(
			self::t(),
			array(
				'studio_id'               => (int) $studio->id,
				'plan_key'                => $plan_key,
				'billing_cycle'           => $cycle,
				'amount'                  => $amount,
				'gateway'                 => 'razorpay',
				'gateway_plan_id'         => $gplan,
				'gateway_subscription_id' => sanitize_text_field( $res['id'] ),
				'mode'                    => self::mode(),
				'status'                  => isset( $res['status'] ) ? sanitize_key( $res['status'] ) : 'created',
				'short_url'               => isset( $res['short_url'] ) ? esc_url_raw( $res['short_url'] ) : null,
				'created_by'              => get_current_user_id() ? get_current_user_id() : null,
				'created_at'              => $now,
				'updated_at'              => $now,
			)
		);
		Db::audit( 'billing', (int) $wpdb->insert_id, 'checkout', sprintf( 'Started checkout for %s (%s, ₹%s)', Plans::get( $plan_key )['label'], $cycle, Format::inr( $amount ) ), null, (int) $studio->id );
		$user = wp_get_current_user();
		return array(
			'key_id'          => self::key_id(),
			'subscription_id' => sanitize_text_field( $res['id'] ),
			'short_url'       => isset( $res['short_url'] ) ? esc_url_raw( $res['short_url'] ) : null,
			'mode'            => self::mode(),
			'name'            => 'ShootPilot',
			'description'     => Plans::get( $plan_key )['label'] . ' plan · ₹' . Format::inr( $amount ) . ( 'yearly' === $cycle ? '/year' : '/month' ),
			'prefill'         => array(
				'name'    => $user && $user->ID ? $user->display_name : '',
				'email'   => $user && $user->ID ? $user->user_email : (string) $studio->email,
				'contact' => (string) $studio->phone,
			),
		);
	}

	/**
	 * Checks the signature Razorpay Checkout returns after the first payment,
	 * then activates the plan. The webhook does the same, so whichever arrives
	 * first wins and the other is a no-op.
	 */
	public static function verify_checkout( $studio, $payment_id, $subscription_id, $signature ) {
		$row = self::find_sub( $subscription_id );
		if ( ! $row || (int) $row->studio_id !== (int) $studio->id ) {
			return new \WP_Error( 'not_found', 'Subscription not found.', array( 'status' => 404 ) );
		}
		$expected = hash_hmac( 'sha256', $payment_id . '|' . $subscription_id, self::key_secret() );
		if ( ! $signature || ! hash_equals( $expected, (string) $signature ) ) {
			return new \WP_Error( 'bad_signature', 'We could not verify this payment. If money was deducted, it will be activated automatically within a few minutes, or contact support.', array( 'status' => 400 ) );
		}
		$entity = self::api( 'GET', 'subscriptions/' . rawurlencode( $subscription_id ) );
		if ( is_wp_error( $entity ) ) {
			// Signature is valid, so the payment is real. Activate now; the webhook fills in the exact dates.
			$entity = array( 'id' => $subscription_id, 'status' => 'active' );
		}
		if ( ! in_array( isset( $entity['status'] ) ? $entity['status'] : '', array( 'active', 'authenticated', 'charged' ), true ) ) {
			$entity['status'] = 'active';
		}
		$payment = array( 'id' => $payment_id, 'amount' => (int) round( (float) $row->amount * 100 ) );
		$pay     = self::api( 'GET', 'payments/' . rawurlencode( $payment_id ) );
		if ( ! is_wp_error( $pay ) && ! empty( $pay['id'] ) ) {
			$payment = $pay;
		}
		self::apply( $row, $entity, $payment, 'checkout' );
		return self::sub( $row->id );
	}

	private static function local_dt( $ts ) {
		if ( ! $ts ) {
			return null;
		}
		$d = new \DateTime( '@' . (int) $ts );
		$d->setTimezone( Db::tz() );
		return $d->format( 'Y-m-d H:i:s' );
	}

	/** End of the paid period when Razorpay did not send one (for example right after checkout). */
	private static function fallback_end( $row ) {
		$d = new \DateTime( 'now', Db::tz() );
		$d->modify( 'yearly' === $row->billing_cycle ? '+1 year' : '+1 month' );
		return $d->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Applies a Razorpay subscription entity (and optional payment entity) to
	 * our row and the studio. Safe to call repeatedly with the same data.
	 */
	public static function apply( $row, array $entity, $payment = null, $source = 'webhook' ) {
		global $wpdb;
		$status = isset( $entity['status'] ) ? sanitize_key( $entity['status'] ) : $row->status;
		$upd    = array(
			'status'     => $status,
			'updated_at' => Db::now(),
		);
		$start = isset( $entity['current_start'] ) ? self::local_dt( $entity['current_start'] ) : null;
		$end   = isset( $entity['current_end'] ) ? self::local_dt( $entity['current_end'] ) : null;
		if ( $start ) {
			$upd['current_start'] = $start;
		}
		if ( $end && ( ! $row->current_end || $end > $row->current_end ) ) {
			$upd['current_end'] = $end;
		}
		if ( isset( $entity['paid_count'] ) ) {
			$upd['paid_count'] = (int) $entity['paid_count'];
		}
		$wpdb->update( self::t(), $upd, array( 'id' => (int) $row->id ) );
		$row = self::sub( (int) $row->id );

		if ( $payment && ! empty( $payment['id'] ) ) {
			self::record_payment( $row, $payment );
		}

		$studio = Tenant::load_studio( (int) $row->studio_id );
		if ( ! $studio ) {
			return $row;
		}
		$is_current = (int) $studio->subscription_id === (int) $row->id;

		if ( in_array( $status, array( 'active', 'authenticated' ), true ) || ( $payment && ! empty( $payment['id'] ) && ! in_array( $status, array( 'cancelled', 'completed', 'expired' ), true ) ) ) {
			// Paid (or the mandate is set up and the first charge is done): this subscription now runs the studio's plan.
			$period_end = $row->current_end ? $row->current_end : self::fallback_end( $row );
			if ( ! $row->current_end ) {
				$wpdb->update( self::t(), array( 'current_end' => $period_end ), array( 'id' => (int) $row->id ) );
			}
			$new_exp = substr( $period_end, 0, 10 );
			$data    = array(
				'plan'            => $row->plan_key,
				'billing_cycle'   => $row->billing_cycle,
				'billing_status'  => 'active',
				'subscription_id' => (int) $row->id,
				'updated_at'      => Db::now(),
			);
			// Never shorten time already paid for.
			if ( ! $is_current || ! $studio->plan_expires_at || $new_exp > $studio->plan_expires_at || $studio->plan !== $row->plan_key ) {
				$data['plan_expires_at'] = $new_exp;
			}
			$wpdb->update( Installer::table( 'studios' ), $data, array( 'id' => (int) $studio->id ) );
			if ( ! $is_current ) {
				self::retire_others( $studio, $row );
				Db::audit( 'billing', (int) $row->id, 'activated', sprintf( '%s plan activated (%s) until %s via %s', Plans::get( $row->plan_key )['label'], $row->billing_cycle, Format::date( $new_exp ), $source ), null, (int) $studio->id );
			}
		} elseif ( $is_current ) {
			$map = array(
				'pending'   => 'pending',
				'halted'    => 'halted',
				'cancelled' => 'cancelled',
				'completed' => 'cancelled',
				'expired'   => 'cancelled',
				'paused'    => 'paused',
			);
			if ( isset( $map[ $status ] ) ) {
				// The plan stays until the paid period (plan_expires_at) ends, then Plans::status() lapses it.
				$wpdb->update( Installer::table( 'studios' ), array( 'billing_status' => $map[ $status ], 'updated_at' => Db::now() ), array( 'id' => (int) $studio->id ) );
				Db::audit( 'billing', (int) $row->id, $status, sprintf( 'Subscription %s on Razorpay', $status ), null, (int) $studio->id );
			}
		}
		Tenant::reset();
		return self::sub( (int) $row->id );
	}

	/** After an upgrade, cancels the studio's other live subscriptions so it is never billed twice. */
	private static function retire_others( $studio, $keep ) {
		global $wpdb;
		$t    = self::t();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE studio_id=%d AND id<>%d AND status IN ('active','authenticated','pending','halted','created')", $studio->id, $keep->id ) ); // phpcs:ignore
		foreach ( $rows as $r ) {
			if ( 'created' !== $r->status ) {
				$res = self::api( 'POST', 'subscriptions/' . rawurlencode( $r->gateway_subscription_id ) . '/cancel', array( 'cancel_at_cycle_end' => 0 ) );
				if ( is_wp_error( $res ) ) {
					error_log( '[pcrm billing] could not cancel replaced subscription ' . $r->gateway_subscription_id . ': ' . $res->get_error_message() );
				}
			}
			$wpdb->update( $t, array( 'status' => 'replaced', 'updated_at' => Db::now() ), array( 'id' => (int) $r->id ) );
		}
	}

	public static function record_payment( $row, array $p ) {
		global $wpdb;
		$t = Installer::table( 'billing_payments' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE gateway_payment_id=%s", $p['id'] ) ) ) { // phpcs:ignore
			return;
		}
		$wpdb->insert(
			$t,
			array(
				'studio_id'          => (int) $row->studio_id,
				'subscription_id'    => (int) $row->id,
				'gateway_payment_id' => sanitize_text_field( $p['id'] ),
				'gateway_invoice_id' => ! empty( $p['invoice_id'] ) ? sanitize_text_field( $p['invoice_id'] ) : null,
				'amount'             => isset( $p['amount'] ) ? round( $p['amount'] / 100, 2 ) : (float) $row->amount,
				'currency'           => ! empty( $p['currency'] ) ? substr( sanitize_text_field( $p['currency'] ), 0, 3 ) : 'INR',
				'method'             => ! empty( $p['method'] ) ? sanitize_key( $p['method'] ) : null,
				'plan_key'           => $row->plan_key,
				'billing_cycle'      => $row->billing_cycle,
				'period_end'         => $row->current_end,
				'paid_at'            => ! empty( $p['created_at'] ) ? self::local_dt( $p['created_at'] ) : Db::now(),
				'created_at'         => Db::now(),
			)
		);
	}

	/** Cancels at the end of the paid period. The plan keeps working until then. */
	public static function cancel( $studio ) {
		global $wpdb;
		$row = self::current_sub( $studio );
		if ( ! $row || in_array( $row->status, array( 'cancelled', 'completed', 'expired', 'replaced' ), true ) ) {
			return new \WP_Error( 'validation_failed', 'There is no active subscription to cancel.', array( 'status' => 422 ) );
		}
		$res = self::api( 'POST', 'subscriptions/' . rawurlencode( $row->gateway_subscription_id ) . '/cancel', array( 'cancel_at_cycle_end' => 1 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$wpdb->update( self::t(), array( 'cancel_requested_at' => Db::now(), 'updated_at' => Db::now() ), array( 'id' => (int) $row->id ) );
		$wpdb->update( Installer::table( 'studios' ), array( 'billing_status' => 'cancelled', 'updated_at' => Db::now() ), array( 'id' => (int) $studio->id ) );
		Db::audit( 'billing', (int) $row->id, 'cancel', 'Auto-renew turned off. Plan stays until ' . Format::date( substr( (string) $studio->plan_expires_at, 0, 10 ) ), null, (int) $studio->id );
		Tenant::reset();
		return self::sub( (int) $row->id );
	}

	/**
	 * Handles a Razorpay webhook for subscriptions. Returns array result or WP_Error.
	 * Events: subscription.authenticated, activated, charged, pending, halted,
	 * cancelled, completed, paused, resumed, updated.
	 */
	public static function webhook( $raw, $signature, $event_id ) {
		global $wpdb;
		$secret = self::webhook_secret();
		if ( ! $secret || ! $signature || ! hash_equals( hash_hmac( 'sha256', $raw, $secret ), (string) $signature ) ) {
			return new \WP_Error( 'bad_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}
		$ev   = json_decode( $raw, true );
		$name = isset( $ev['event'] ) ? sanitize_text_field( $ev['event'] ) : '';
		if ( 0 !== strpos( $name, 'subscription.' ) ) {
			return array( 'ignored' => 'not a subscription event' );
		}
		$entity = isset( $ev['payload']['subscription']['entity'] ) ? $ev['payload']['subscription']['entity'] : null;
		if ( ! $entity || empty( $entity['id'] ) ) {
			return array( 'ignored' => 'no subscription' );
		}
		$row = self::find_sub( $entity['id'] );
		if ( ! $row ) {
			// Not started from this site (Razorpay sends every event on the account to every webhook).
			return array( 'ignored' => 'unknown subscription' );
		}
		$event_id = $event_id ? sanitize_text_field( $event_id ) : 'h_' . hash( 'sha256', $raw );
		$et       = Installer::table( 'billing_events' );
		$quiet    = $wpdb->suppress_errors( true ); // A repeated event id is expected (Razorpay retries).
		$ok       = $wpdb->insert(
			$et,
			array(
				'event_id'                => substr( $event_id, 0, 64 ),
				'event'                   => substr( $name, 0, 60 ),
				'studio_id'               => (int) $row->studio_id,
				'gateway_subscription_id' => $row->gateway_subscription_id,
				'created_at'              => Db::now(),
			)
		);
		$wpdb->suppress_errors( $quiet );
		if ( ! $ok ) {
			return array( 'duplicate' => true );
		}
		$payment = isset( $ev['payload']['payment']['entity'] ) && 'subscription.charged' === $name ? $ev['payload']['payment']['entity'] : null;
		if ( 'subscription.resumed' === $name || 'subscription.activated' === $name ) {
			$entity['status'] = 'active';
		}
		$after = self::apply( $row, $entity, $payment, 'webhook ' . $name );
		$wpdb->update( $et, array( 'result' => 'status ' . ( $after ? $after->status : '?' ) ), array( 'event_id' => substr( $event_id, 0, 64 ) ) );
		return array( 'ok' => true );
	}
}
