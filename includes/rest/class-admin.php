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
			"SELECT s.id, s.name, s.slug, s.email, s.phone, s.city, s.plan, s.plan_expires_at, s.status, s.created_at, u.display_name AS owner_name, u.user_email AS owner_email,
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
		foreach ( $rows as $r ) {
			$plans[ $r->plan ] = isset( $plans[ $r->plan ] ) ? $plans[ $r->plan ] + 1 : 1;
		}
		$mrr = 0;
		foreach ( $rows as $r ) {
			if ( 'active' === $r->status ) {
				$mrr += Plans::get( $r->plan )['price'];
			}
		}
		return $this->ok(
			array(
				'studios' => $rows,
				'stats'   => array(
					'studios' => count( $rows ),
					'active'  => count( array_filter( $rows, function ( $r ) {
						return 'active' === $r->status;
					} ) ),
					'plans'   => $plans,
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
				'status'          => array( 'type' => 'enum', 'options' => array( 'active' => 1, 'suspended' => 1 ) ),
			),
			true
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['updated_at'] = Db::now();
		$wpdb->update( Installer::table( 'studios' ), $data, array( 'id' => $id ) );
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
}
