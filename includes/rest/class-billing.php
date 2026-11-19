<?php
namespace PCRM\Rest;

use PCRM\Billing as B;
use PCRM\Db;
use PCRM\Installer;
use PCRM\Plans;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Billing & plan page for studios, plus the platform's Razorpay Subscriptions webhook.
 * Owners and admins (settings.edit) can see usage and upgrade; every member gets the plan state via /me.
 */
class Billing extends Base {

	public function register() {
		$this->route( '/billing', 'GET', array( $this, 'show' ), 'settings.edit' );
		$this->route( '/billing/checkout', 'POST', array( $this, 'checkout' ), 'settings.edit' );
		$this->route( '/billing/verify', 'POST', array( $this, 'verify' ), 'settings.edit' );
		$this->route( '/billing/cancel', 'POST', array( $this, 'cancel' ), 'settings.edit' );
		$this->route( '/billing/webhook', 'POST', array( $this, 'webhook' ), 'public' );
	}

	public static function public_plans() {
		$out = array();
		foreach ( Plans::all() as $k => $p ) {
			$out[ $k ] = array(
				'key'             => $k,
				'label'           => $p['label'],
				'tagline'         => $p['tagline'],
				'price'           => $p['price'],
				'price_yearly'    => $p['price_yearly'],
				'trial_days'      => $p['trial_days'],
				'members'         => $p['members'],
				'leads_per_month' => $p['leads_per_month'],
				'features'        => $p['features'],
				'paid'            => $p['paid'],
			);
		}
		return $out;
	}

	public function show() {
		global $wpdb;
		$studio = Tenant::studio();
		$sub    = B::current_sub( $studio );
		$pay    = Installer::table( 'billing_payments' );
		$hist   = $wpdb->get_results( $wpdb->prepare( "SELECT id, gateway_payment_id, gateway_invoice_id, amount, currency, method, plan_key, billing_cycle, period_end, paid_at FROM $pay WHERE studio_id=%d ORDER BY paid_at DESC, id DESC LIMIT 100", $studio->id ) ); // phpcs:ignore
		foreach ( $hist as $h ) {
			$h->id     = (int) $h->id;
			$h->amount = (float) $h->amount;
		}
		return $this->ok(
			array(
				'status'       => Plans::status( $studio ),
				'usage'        => Plans::usage( $studio ),
				'plans'        => self::public_plans(),
				'subscription' => $sub ? array(
					'id'                  => (int) $sub->id,
					'plan'                => $sub->plan_key,
					'cycle'               => $sub->billing_cycle,
					'amount'              => (float) $sub->amount,
					'status'              => $sub->status,
					'current_end'         => $sub->current_end,
					'cancel_requested_at' => $sub->cancel_requested_at,
					'mode'                => $sub->mode,
				) : null,
				'history'      => $hist,
				'checkout'     => array(
					'enabled' => B::configured(),
					'mode'    => B::configured() ? B::mode() : null,
				),
				'trial_started' => substr( (string) $studio->created_at, 0, 10 ),
			)
		);
	}

	public function checkout( $req ) {
		$b     = $this->body( $req );
		$plan  = isset( $b['plan'] ) ? (string) $b['plan'] : '';
		$cycle = isset( $b['cycle'] ) ? (string) $b['cycle'] : '';
		$r     = B::start_checkout( Tenant::studio(), $plan, $cycle );
		return is_wp_error( $r ) ? $r : $this->ok( $r, 201 );
	}

	public function verify( $req ) {
		$b = $this->body( $req );
		foreach ( array( 'razorpay_payment_id', 'razorpay_subscription_id', 'razorpay_signature' ) as $k ) {
			if ( empty( $b[ $k ] ) || ! is_string( $b[ $k ] ) ) {
				return new \WP_Error( 'validation_failed', 'Payment details are missing.', array( 'status' => 422 ) );
			}
		}
		$r = B::verify_checkout( Tenant::studio(), sanitize_text_field( $b['razorpay_payment_id'] ), sanitize_text_field( $b['razorpay_subscription_id'] ), sanitize_text_field( $b['razorpay_signature'] ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		Tenant::reset();
		return $this->show();
	}

	public function cancel() {
		$r = B::cancel( Tenant::studio() );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		Tenant::reset();
		return $this->show();
	}

	/**
	 * Razorpay → Account & Settings → Webhooks. URL /wp-json/pcrm/v1/billing/webhook,
	 * signed with the platform webhook secret (not a studio's secret).
	 */
	public function webhook( $req ) {
		$r = B::webhook( (string) $req->get_body(), (string) $req->get_header( 'x_razorpay_signature' ), (string) $req->get_header( 'x_razorpay_event_id' ) );
		return is_wp_error( $r ) ? $r : $this->ok( $r );
	}
}
