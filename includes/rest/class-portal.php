<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Portals;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controls for a booking's client portal. Owner/admin only.
 */
class Portal extends Base {

	public function register() {
		$this->route( '/bookings/(?P<id>\d+)/portal', 'GET', array( $this, 'show' ), 'portal.manage' );
		$this->route( '/bookings/(?P<id>\d+)/portal/pin', 'POST', array( $this, 'pin' ), 'portal.manage' );
		$this->route( '/bookings/(?P<id>\d+)/portal/pin', 'GET', array( $this, 'reveal' ), 'portal.manage' );
		$this->route( '/portals', 'GET', array( $this, 'index' ), 'portal.manage' );
		$this->route( '/bookings/(?P<id>\d+)/portal/enabled', 'POST', array( $this, 'enabled' ), 'portal.manage' );
		$this->route( '/bookings/(?P<id>\d+)/portal/new-link', 'POST', array( $this, 'new_link' ), 'portal.manage' );
		$this->route( '/bookings/(?P<id>\d+)/portal/unlock', 'POST', array( $this, 'unlock' ), 'portal.manage' );
	}

	private function portal( $req ) {
		$bid = (int) $req['id'];
		if ( ! Db::exists( 'bookings', $bid ) ) {
			return $this->not_found( 'Booking' );
		}
		$row = Portals::ensure( $bid );
		return $row ? $row : $this->not_found( 'Portal' );
	}

	public function show( $req ) {
		$row = $this->portal( $req );
		return is_wp_error( $row ) ? $row : $this->ok( Portals::summary( $row ) );
	}

	/**
	 * Generates a PIN, or sets the custom PIN sent as "pin". The plain PIN is
	 * returned only in this response; the database keeps a hash.
	 */
	public function pin( $req ) {
		$row = $this->portal( $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$b      = $this->body( $req );
		$custom = isset( $b['pin'] ) ? trim( (string) $b['pin'] ) : '';
		$had    = ! empty( $row->pin_hash );
		$pin    = Portals::set_pin( $row, '' === $custom ? null : $custom );
		if ( is_wp_error( $pin ) ) {
			return $pin;
		}
		Db::log( 'portal', 'Client portal PIN ' . ( $had ? ( '' === $custom ? 'reset' : 'changed' ) : 'created' ) . ( '' === $custom ? ' (generated)' : ' (custom)' ) . '. Devices using the old PIN were signed out.', array( 'booking_id' => (int) $row->booking_id ) );
		return $this->ok( array( 'pin' => $pin, 'portal' => Portals::summary( Portals::for_booking( (int) $row->booking_id ) ) ) );
	}

	/** Shows the current PIN again to an owner/admin. Every view is logged. */
	public function reveal( $req ) {
		$row = $this->portal( $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( empty( $row->pin_hash ) ) {
			return new \WP_Error( 'no_pin', 'No PIN has been generated for this portal yet.', array( 'status' => 404 ) );
		}
		$pin = Portals::reveal_pin( $row );
		if ( ! $pin ) {
			return new \WP_Error( 'pin_hidden', 'This PIN was set before PINs could be viewed again (or the site security keys changed). Generate a new PIN to view and share it.', array( 'status' => 409 ) );
		}
		Db::log( 'portal', 'Client portal PIN viewed by an admin', array( 'booking_id' => (int) $row->booking_id ) );
		return $this->ok( array( 'pin' => $pin ) );
	}

	/**
	 * Every booking with its portal status, for Settings → Client portal.
	 */
	public function index( $req ) {
		global $wpdb;
		$sid    = $this->sid();
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		$search = trim( (string) $req->get_param( 'search' ) );
		$where  = $wpdb->prepare( 'b.studio_id=%d', $sid );
		if ( '' !== $search ) {
			$l      = $this->like( $search );
			$where .= $wpdb->prepare( ' AND (b.number LIKE %s OR b.title LIKE %s OR c.name LIKE %s OR c.phone LIKE %s)', $l, $l, $l, $l );
		}
		$rows = $wpdb->get_results(
			'SELECT b.id, b.number, b.title, b.status AS booking_status, c.name AS client_name, c.phone AS client_phone,
				(SELECT MIN(e.event_date) FROM ' . Db::t( 'booking_events' ) . ' e WHERE e.studio_id=b.studio_id AND e.booking_id=b.id) AS first_date,
				p.id AS portal_id, p.pin_hash, p.pin_encrypted, p.is_enabled, p.pin_set_at, p.view_count, p.last_viewed_at, p.token
			FROM ' . Db::t( 'bookings' ) . ' b
			LEFT JOIN ' . Db::t( 'clients' ) . ' c ON c.id=b.client_id AND c.studio_id=b.studio_id
			LEFT JOIN ' . Db::t( 'client_portals' ) . " p ON p.booking_id=b.id AND p.studio_id=b.studio_id
			WHERE $where AND b.status<>'cancelled'
			ORDER BY first_date DESC, b.id DESC LIMIT 500"
		);
		$out = array();
		foreach ( $rows as $r ) {
			$st = ! $r->portal_id || empty( $r->pin_hash ) ? 'not_activated' : ( (int) $r->is_enabled ? 'active' : 'disabled' );
			if ( $status && $status !== $st ) {
				continue;
			}
			$out[] = array(
				'booking_id'     => (int) $r->id,
				'number'         => $r->number,
				'title'          => $r->title,
				'booking_status' => $r->booking_status,
				'client_name'    => $r->client_name,
				'client_phone'   => $r->client_phone,
				'first_date'     => $r->first_date,
				'status'         => $st,
				'pin_viewable'   => ! empty( $r->pin_hash ) && ! empty( $r->pin_encrypted ),
				'pin_set_at'     => $r->pin_set_at,
				'view_count'     => (int) $r->view_count,
				'last_viewed_at' => $r->last_viewed_at,
				'url'            => $r->token ? home_url( '/portal/' . $r->token ) : null,
			);
		}
		return $this->ok( $out );
	}

	public function enabled( $req ) {
		$row = $this->portal( $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$b  = $this->body( $req );
		$on = ! empty( $b['enabled'] ) && filter_var( $b['enabled'], FILTER_VALIDATE_BOOLEAN );
		if ( $on && empty( $row->pin_hash ) ) {
			return new \WP_Error( 'no_pin', 'Generate a PIN first. The portal turns on as soon as it has a PIN.', array( 'status' => 422 ) );
		}
		Db::update( 'client_portals', $row->id, array( 'is_enabled' => $on ? 1 : 0 ) );
		\PCRM\Plugin::purge_url( Portals::url( $row ) );
		Db::log( 'portal', 'Client portal ' . ( $on ? 'enabled' : 'disabled' ), array( 'booking_id' => (int) $row->booking_id ) );
		return $this->ok( Portals::summary( Portals::for_booking( (int) $row->booking_id ) ) );
	}

	/** Replaces the link, so an old shared link stops working. */
	public function new_link( $req ) {
		$row = $this->portal( $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		Db::update( 'client_portals', $row->id, array( 'token' => Portals::new_token() ) );
		\PCRM\Plugin::purge_url( Portals::url( $row ) );
		Db::log( 'portal', 'Client portal link replaced. The old link no longer works.', array( 'booking_id' => (int) $row->booking_id ) );
		return $this->ok( Portals::summary( Portals::for_booking( (int) $row->booking_id ) ) );
	}

	public function unlock( $req ) {
		$row = $this->portal( $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		global $wpdb;
		$wpdb->update( Db::t( 'client_portals' ), array( 'failed_attempts' => 0, 'locked_until' => null ), array( 'id' => (int) $row->id, 'studio_id' => $this->sid() ) );
		Db::log( 'portal', 'Client portal unlocked', array( 'booking_id' => (int) $row->booking_id ) );
		return $this->ok( Portals::summary( Portals::for_booking( (int) $row->booking_id ) ) );
	}
}
