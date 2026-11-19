<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the logged-in user's current studio and CRM membership.
 */
class Tenant {

	private static $member = false;
	private static $studio = false;

	public static function reset() {
		self::$member = false;
		self::$studio = false;
	}

	public static function memberships( $user_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.id AS member_id, m.role, s.id, s.name, s.status, s.plan FROM ' . Installer::table( 'members' ) . ' m JOIN ' . Installer::table( 'studios' ) . " s ON s.id = m.studio_id WHERE m.user_id = %d AND m.status = 'active' ORDER BY s.name",
				$user_id
			)
		);
	}

	public static function member() {
		if ( false !== self::$member ) {
			return self::$member;
		}
		self::$member = null;
		$uid          = get_current_user_id();
		if ( ! $uid ) {
			return null;
		}
		global $wpdb;
		$m  = Installer::table( 'members' );
		$sid = (int) get_user_meta( $uid, 'pcrm_current_studio', true );
		$row = null;
		if ( $sid ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $m WHERE user_id=%d AND studio_id=%d AND status='active'", $uid, $sid ) );
		}
		if ( ! $row ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $m WHERE user_id=%d AND status='active' ORDER BY id LIMIT 1", $uid ) );
			if ( $row ) {
				update_user_meta( $uid, 'pcrm_current_studio', $row->studio_id );
			}
		}
		if ( $row ) {
			$row->id        = (int) $row->id;
			$row->studio_id = (int) $row->studio_id;
		}
		self::$member = $row;
		return $row;
	}

	/**
	 * Runs as a studio's owner for server-to-server calls (e.g. payment webhooks).
	 */
	public static function act_as_studio( $studio_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'members' ) . " WHERE studio_id=%d AND role='owner' AND status='active' ORDER BY id LIMIT 1", $studio_id ) );
		if ( ! $row ) {
			return false;
		}
		$row->id        = (int) $row->id;
		$row->studio_id = (int) $row->studio_id;
		wp_set_current_user( (int) $row->user_id );
		self::$member = $row;
		self::$studio = self::load_studio( $studio_id );
		return (bool) self::$studio;
	}

	public static function studio() {
		if ( false !== self::$studio ) {
			return self::$studio;
		}
		self::$studio = null;
		$m            = self::member();
		if ( ! $m ) {
			return null;
		}
		self::$studio = self::load_studio( $m->studio_id );
		return self::$studio;
	}

	public static function load_studio( $id ) {
		global $wpdb;
		$s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'studios' ) . ' WHERE id=%d', $id ) );
		if ( $s ) {
			$s->id       = (int) $s->id;
			$settings    = $s->settings ? json_decode( $s->settings, true ) : array();
			$s->settings = array_merge( Config::default_settings(), is_array( $settings ) ? $settings : array() );
		}
		return $s;
	}

	public static function studio_id() {
		$m = self::member();
		return $m ? (int) $m->studio_id : 0;
	}

	public static function setting( $key ) {
		$s = self::studio();
		return $s && isset( $s->settings[ $key ] ) ? $s->settings[ $key ] : null;
	}

	public static function can( $perm ) {
		return Permissions::can( self::member(), $perm );
	}

	/**
	 * Only the platform owner is super admin: the users listed in the
	 * pcrm_super_admins option. If that list is empty, the WordPress user whose
	 * email is the site's admin email (Settings > General) is the super admin.
	 */
	public static function super_admin_ids() {
		$ids = array_filter( array_map( 'intval', (array) get_option( 'pcrm_super_admins', array() ) ) );
		if ( ! $ids ) {
			$u = get_user_by( 'email', get_option( 'admin_email' ) );
			if ( $u && user_can( $u, 'manage_options' ) ) {
				$ids = array( (int) $u->ID );
			}
		}
		return array_values( $ids );
	}

	public static function is_super_admin( $user_id = null ) {
		$uid = null === $user_id ? get_current_user_id() : (int) $user_id;
		return $uid > 0 && user_can( $uid, 'manage_options' ) && in_array( $uid, self::super_admin_ids(), true );
	}

	public static function switch_studio( $studio_id ) {
		global $wpdb;
		$uid = get_current_user_id();
		$ok  = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'members' ) . " WHERE user_id=%d AND studio_id=%d AND status='active'", $uid, $studio_id ) );
		if ( ! $ok ) {
			return false;
		}
		update_user_meta( $uid, 'pcrm_current_studio', (int) $studio_id );
		self::reset();
		return true;
	}
}
