<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Installer;
use PCRM\Plans;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Super admin panel: all studios, plans and status.
 */
class Admin extends Base {

	public function register() {
		$this->route( '/admin/studios', 'GET', array( $this, 'index' ), 'super' );
		$this->route( '/admin/studios/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'super' );
		$this->route( '/admin/studios/(?P<id>\d+)/join', 'POST', array( $this, 'join' ), 'super' );
		$this->route( '/admin/settings', 'GET', array( $this, 'platform' ), 'super' );
		$this->route( '/admin/settings', 'POST', array( $this, 'save_platform' ), 'super' );
		$this->route( '/admin/billing', 'GET', array( $this, 'billing' ), 'super' );
		$this->route( '/admin/billing', 'POST', array( $this, 'save_billing' ), 'super' );
		$this->route( '/admin/plans/(?P<key>[a-z_]+)', 'PUT', array( $this, 'update_plan' ), 'super' );
	}

	public function index( $req ) {
		global $wpdb;
		$s    = Installer::table( 'studios' );
		$w    = '1=1';
		$q    = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like = $this->like( $q );
			$w   .= $wpdb->prepare( ' AND (s.name LIKE %s OR s.email LIKE %s OR s.city LIKE %s)', $like, $like, $like );
		}
		$rows = $wpdb->get_results(
			"SELECT s.id, s.name, s.slug, s.email, s.phone, s.city, s.plan, s.plan_expires_at, s.trial_ends_at, s.billing_cycle, s.billing_status, s.status, s.created_at, u.display_name AS owner_name, u.user_email AS owner_email,
				(SELECT COUNT(*) FROM " . Db::t( 'members' ) . " m WHERE m.studio_id=s.id AND m.status='active') AS members,
				(SELECT COUNT(*) FROM " . Db::t( 'leads' ) . " l WHERE l.studio_id=s.id) AS leads,
				(SELECT COUNT(*) FROM " . Db::t( 'bookings' ) . " b WHERE b.studio_id=s.id) AS bookings,
				(SELECT MAX(a.created_at) FROM " . Db::t( 'activities' ) . " a WHERE a.studio_id=s.id) AS last_active
			FROM $s s LEFT JOIN {$wpdb->users} u ON u.ID=s.owner_user_id WHERE $w ORDER BY s.created_at DESC LIMIT 500"
		);
		foreach ( $rows as $r ) {
			foreach ( array( 'id', 'members', 'leads', 'bookings' ) as $k ) {
				$r->$k = (int) $r->$k;
			}
		}
		$plans = array();
		$mrr   = 0;
		$paid  = 0;
		foreach ( $rows as $r ) {
			$r->billing        = Plans::status( $r );
			$plans[ $r->plan ] = isset( $plans[ $r->plan ] ) ? $plans[ $r->plan ] + 1 : 1;
			// Only studios paying through a subscription count toward recurring revenue.
			if ( 'active' === $r->status && in_array( $r->billing['state'], array( 'active', 'past_due' ), true ) && 'active' === $r->billing_status ) {
				$p    = Plans::get( $r->plan );
				$mrr += 'yearly' === $r->billing_cycle ? $p['price_yearly'] / 12 : $p['price'];
				$paid++;
			}
		}
		$mrr = round( $mrr );
		return $this->ok(
			array(
				'studios' => $rows,
				'stats'   => array(
					'studios' => count( $rows ),
					'active'  => count( array_filter( $rows, function ( $r ) {
						return 'active' === $r->status;
					} ) ),
					'plans'   => $plans,
					'paid'    => $paid,
					'mrr'     => $mrr,
				),
			)
		);
	}

	public function update( $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'studios' ) . ' WHERE id=%d', $id ) ) ) {
			return $this->not_found( 'Studio' );
		}
		$data = Validator::validate(
			$this->body( $req ),
			array(
				'plan'            => array( 'type' => 'enum', 'options' => Plans::all() ),
				'plan_expires_at' => array( 'type' => 'date', 'label' => 'Plan expiry' ),
				'trial_ends_at'   => array( 'type' => 'date', 'label' => 'Trial end' ),
				'status'          => array( 'type' => 'enum', 'options' => array( 'active' => 1, 'suspended' => 1 ) ),
			),
			true
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['updated_at'] = Db::now();
		$wpdb->update( Installer::table( 'studios' ), $data, array( 'id' => $id ) );
		\PCRM\Db::audit( 'billing', $id, 'admin_update', 'Plan changed by super admin: ' . wp_json_encode( $data ), null, $id );
		return $this->ok( array( 'ok' => true ) );
	}

	/**
	 * Lets the super admin join a studio as admin (for support).
	 */
	public function join( $req ) {
		global $wpdb;
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'studios' ) . ' WHERE id=%d', $id ) ) ) {
			return $this->not_found( 'Studio' );
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'members' ) . ' WHERE studio_id=%d AND user_id=%d', $id, $uid ) );
		if ( ! $exists ) {
			$u = wp_get_current_user();
			Db::insert( 'members', array( 'user_id' => $uid, 'name' => $u->display_name . ' (Support)', 'email' => $u->user_email, 'role' => 'admin', 'status' => 'active', 'color' => '#64748b' ), $id );
		} else {
			$wpdb->update( Db::t( 'members' ), array( 'status' => 'active' ), array( 'id' => $exists ) );
		}
		update_user_meta( $uid, 'pcrm_current_studio', $id );
		return $this->ok( array( 'ok' => true ) );
	}

	public function platform() {
		return $this->ok(
			array(
				'google_client_id'  => get_option( 'pcrm_google_client_id', '' ),
				'google_secret_set' => (bool) get_option( 'pcrm_google_client_secret' ),
				'google_redirect'   => \PCRM\Google_Auth::redirect_uri(),
				'google_origin'     => untrailingslashit( home_url() ),
				'google_enabled'    => (bool) \PCRM\Google_Auth::enabled(),
			)
		);
	}

	public function save_platform( $req ) {
		$b = $this->body( $req );
		if ( isset( $b['google_client_id'] ) ) {
			$id = sanitize_text_field( $b['google_client_id'] );
			if ( $id && ! preg_match( '/\.apps\.googleusercontent\.com$/', $id ) ) {
				return new \WP_Error( 'validation_failed', 'Client ID should end with .apps.googleusercontent.com', array( 'status' => 422, 'errors' => array( 'google_client_id' => 'Should end with .apps.googleusercontent.com' ) ) );
			}
			update_option( 'pcrm_google_client_id', $id, false );
		}
		if ( isset( $b['google_client_secret'] ) && '' !== $b['google_client_secret'] ) {
			update_option( 'pcrm_google_client_secret', sanitize_text_field( $b['google_client_secret'] ), false );
		}
		if ( ! empty( $b['google_remove'] ) ) {
			delete_option( 'pcrm_google_client_id' );
			delete_option( 'pcrm_google_client_secret' );
		}
		return $this->platform();
	}

	/** Platform Razorpay keys for ShootPilot's own subscriptions. Secrets are never returned. */
	public function billing() {
		$id = \PCRM\Billing::key_id();
		return $this->ok(
			array(
				'key_id'          => $id,
				'secret_set'      => '' !== \PCRM\Billing::key_secret(),
				'webhook_set'     => '' !== \PCRM\Billing::webhook_secret(),
				'from_constants'  => \PCRM\Billing::from_constants(),
				'configured'      => \PCRM\Billing::configured(),
				'mode'            => $id ? \PCRM\Billing::mode() : null,
				'webhook_url'     => \PCRM\Billing::webhook_url(),
				'after_expiry'    => Plans::after_expiry_mode(),
				'plans'           => Billing::public_plans(),
				'studio_keys_note' => 'These keys receive ShootPilot subscription payments. Studios connect their own Razorpay account separately in Settings → Online payments.',
			)
		);
	}

	public function save_billing( $req ) {
		$b = $this->body( $req );
		if ( isset( $b['key_id'] ) && ! \PCRM\Billing::from_constants() ) {
			$id = trim( sanitize_text_field( $b['key_id'] ) );
			if ( $id && ! preg_match( '/^rzp_(test|live)_[A-Za-z0-9]+$/', $id ) ) {
				return new \WP_Error( 'validation_failed', 'Key ID should start with rzp_test_ or rzp_live_.', array( 'status' => 422, 'errors' => array( 'key_id' => 'Should start with rzp_test_ or rzp_live_' ) ) );
			}
			update_option( 'pcrm_billing_key_id', $id, false );
		}
		if ( ! empty( $b['key_secret'] ) && ! \PCRM\Billing::from_constants() ) {
			update_option( 'pcrm_billing_key_secret', trim( sanitize_text_field( $b['key_secret'] ) ), false );
		}
		if ( ! empty( $b['webhook_secret'] ) && ! defined( 'PCRM_BILLING_WEBHOOK_SECRET' ) ) {
			$w = trim( (string) $b['webhook_secret'] );
			if ( strlen( $w ) < 12 ) {
				return new \WP_Error( 'validation_failed', 'Use a webhook secret of at least 12 characters.', array( 'status' => 422, 'errors' => array( 'webhook_secret' => 'At least 12 characters.' ) ) );
			}
			update_option( 'pcrm_billing_webhook_secret', sanitize_text_field( $w ), false );
		}
		if ( isset( $b['after_expiry'] ) && in_array( $b['after_expiry'], array( 'read_only', 'starter_limits' ), true ) ) {
			update_option( 'pcrm_billing_after_expiry', $b['after_expiry'], false );
		}
		if ( ! empty( $b['remove_keys'] ) && ! \PCRM\Billing::from_constants() ) {
			delete_option( 'pcrm_billing_key_id' );
			delete_option( 'pcrm_billing_key_secret' );
			delete_option( 'pcrm_billing_webhook_secret' );
		}
		return $this->billing();
	}

	/** Edits a plan's price, limits and features. Running subscriptions keep their old price on Razorpay. */
	public function update_plan( $req ) {
		global $wpdb;
		$key = sanitize_key( $req['key'] );
		$t   = Installer::table( 'plans' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE plan_key=%s", $key ) ) ) { // phpcs:ignore
			return $this->not_found( 'Plan' );
		}
		$data = Validator::validate(
			$this->body( $req ),
			array(
				'label'           => array( 'type' => 'string', 'max' => 60 ),
				'tagline'         => array( 'type' => 'string', 'max' => 190 ),
				'price'           => array( 'type' => 'int', 'min' => 0, 'max' => 1000000, 'label' => 'Monthly price' ),
				'price_yearly'    => array( 'type' => 'int', 'min' => 0, 'max' => 10000000, 'label' => 'Yearly price' ),
				'trial_days'      => array( 'type' => 'int', 'min' => 0, 'max' => 365, 'label' => 'Trial days' ),
				'members'         => array( 'type' => 'int', 'min' => -1, 'max' => 100000, 'label' => 'Team logins' ),
				'leads_per_month' => array( 'type' => 'int', 'min' => -1, 'max' => 10000000, 'label' => 'Leads per month' ),
			),
			true
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		foreach ( array( 'price' => 0, 'price_yearly' => 0, 'trial_days' => 0, 'members' => -1, 'leads_per_month' => -1 ) as $k => $min ) {
			if ( isset( $data[ $k ] ) && $data[ $k ] < $min ) {
				return new \WP_Error( 'validation_failed', -1 === $min ? 'Use -1 for unlimited, or a number of 0 or more.' : 'Use 0 or more.', array( 'status' => 422, 'errors' => array( $k => 'Too small.' ) ) );
			}
		}
		$row = array();
		$map = array( 'price' => 'price_monthly' );
		foreach ( $data as $k => $v ) {
			$row[ isset( $map[ $k ] ) ? $map[ $k ] : $k ] = $v;
		}
		$b = $this->body( $req );
		if ( isset( $b['features'] ) && is_array( $b['features'] ) ) {
			$row['features'] = wp_json_encode( array_values( array_intersect( Plans::FEATURES, array_map( 'strval', $b['features'] ) ) ) );
		}
		if ( $row ) {
			$row['updated_at'] = Db::now();
			$wpdb->update( $t, $row, array( 'plan_key' => $key ) );
			Plans::flush();
		}
		return $this->ok( Billing::public_plans() );
	}
}
