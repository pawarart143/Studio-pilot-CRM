<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Tenant;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Booking deliverables (edited photos, films, album…) and reusable
 * deliverable templates. Clients see a safe subset on their portal.
 */
class Deliverables extends Base {

	public static function schema() {
		return array(
			'name'          => array( 'type' => 'string', 'required' => true, 'label' => 'Deliverable name' ),
			'description'   => array( 'type' => 'text' ),
			'assigned_to'   => array( 'type' => 'ref', 'table' => 'members', 'label' => 'Assigned team member' ),
			'expected_date' => array( 'type' => 'date', 'label' => 'Expected delivery date' ),
			'status'        => array( 'type' => 'enum', 'options' => Config::deliverable_statuses(), 'default' => 'not_started', 'label' => 'Status' ),
			'progress'      => array( 'type' => 'int', 'label' => 'Progress' ),
			'delivery_link' => array( 'type' => 'string', 'max' => 500, 'label' => 'Delivery link' ),
			'admin_notes'   => array( 'type' => 'text', 'label' => 'Admin notes' ),
		);
	}

	public function register() {
		$this->route( '/deliverables', 'GET', array( $this, 'index' ), 'deliverables.view' );
		$this->route( '/bookings/(?P<id>\d+)/deliverables', 'POST', array( $this, 'create' ), 'deliverables.edit' );
		$this->route( '/bookings/(?P<id>\d+)/deliverables/template', 'POST', array( $this, 'apply_template' ), 'deliverables.edit' );
		$this->route( '/deliverables/(?P<id>\d+)', 'PUT', array( $this, 'update' ), array( 'deliverables.edit', 'deliverables.update' ) );
		$this->route( '/deliverables/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'deliverables.edit' );
		$this->route( '/deliverable-templates', 'GET', array( $this, 'templates' ), array( 'bookings.edit', 'deliverables.edit', 'settings.edit' ) );
		$this->route( '/deliverable-templates', 'POST', array( $this, 'create_template' ), 'settings.edit' );
		$this->route( '/deliverable-templates/(?P<id>\d+)', 'PUT', array( $this, 'update_template' ), 'settings.edit' );
		$this->route( '/deliverable-templates/(?P<id>\d+)', 'DELETE', array( $this, 'delete_template' ), 'settings.edit' );
	}

	/* ---------- Helpers used by bookings, WhatsApp and the portal ---------- */

	public static function for_booking( $booking_id, $sid = null ) {
		global $wpdb;
		$sid = null === $sid ? Tenant::studio_id() : (int) $sid;
		return Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT d.*, m.name AS assigned_name, m.color AS assigned_color, m.photo_url AS assigned_photo, u.display_name AS updated_by_name FROM ' . Db::t( 'booking_deliverables' ) . ' d LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id=d.assigned_to LEFT JOIN ' . $wpdb->users . ' u ON u.ID=d.updated_by WHERE d.studio_id=%d AND d.booking_id=%d ORDER BY d.sort, d.id', $sid, $booking_id ) ) );
	}

	/** Overall delivery progress: the average of all deliverables, 0–100. */
	public static function overall( array $rows ) {
		if ( ! $rows ) {
			return 0;
		}
		$sum = 0;
		foreach ( $rows as $r ) {
			$sum += 'delivered' === $r->status ? 100 : (int) $r->progress;
		}
		return (int) round( $sum / count( $rows ) );
	}

	public static function valid_link( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Validates one deliverable. Returns the clean array or WP_Error.
	 */
	public static function clean( array $in, $partial = false, $existing = null ) {
		$v = Validator::validate( $in, self::schema(), $partial );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		if ( array_key_exists( 'delivery_link', $v ) ) {
			$link = self::valid_link( $v['delivery_link'] );
			if ( false === $link ) {
				return new \WP_Error( 'validation_failed', 'Delivery link must be a full web address starting with https://', array( 'status' => 422, 'errors' => array( 'delivery_link' => 'Enter a valid link.' ) ) );
			}
			$v['delivery_link'] = $link ? $link : null;
		}
		if ( array_key_exists( 'progress', $v ) && null !== $v['progress'] && ( $v['progress'] < 0 || $v['progress'] > 100 ) ) {
			return new \WP_Error( 'validation_failed', 'Progress must be between 0 and 100.', array( 'status' => 422, 'errors' => array( 'progress' => '0 to 100.' ) ) );
		}
		$status = isset( $v['status'] ) ? $v['status'] : ( $existing ? $existing->status : 'not_started' );
		$status_changed = isset( $v['status'] ) && ( ! $existing || $existing->status !== $v['status'] );
		if ( ( ! array_key_exists( 'progress', $v ) || null === $v['progress'] ) && ( $status_changed || ! $existing ) ) {
			$map           = Config::deliverable_progress();
			$v['progress'] = $map[ $status ];
		}
		if ( 'delivered' === $status ) {
			$v['progress'] = 100;
			if ( $status_changed ) {
				$v['delivered_at'] = Db::now();
			}
		} elseif ( $status_changed ) {
			$v['delivered_at'] = null;
		}
		if ( array_key_exists( 'progress', $v ) && null === $v['progress'] ) {
			unset( $v['progress'] );
		}
		return $v;
	}

	public static function parse_list( array $list ) {
		$out = array();
		foreach ( $list as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id       = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$existing = $id ? Db::find( 'booking_deliverables', $id ) : null;
			$v        = self::clean( $row, false, $existing );
			if ( is_wp_error( $v ) ) {
				return new \WP_Error( 'validation_failed', 'Deliverable ' . ( $i + 1 ) . ': ' . $v->get_error_message(), array( 'status' => 422 ) );
			}
			$v['id']   = $existing ? $id : 0;
			$v['sort'] = $i;
			$out[]     = $v;
		}
		return $out;
	}

	/**
	 * Saves the full deliverables list of a booking (adds, updates, removes).
	 */
	public static function save_list( $booking_id, array $rows ) {
		global $wpdb;
		$sid  = Tenant::studio_id();
		$keep = array();
		$st   = Config::deliverable_statuses();
		foreach ( $rows as $r ) {
			$id = $r['id'];
			unset( $r['id'] );
			$r['booking_id'] = $booking_id;
			$r['updated_by'] = get_current_user_id();
			$old             = $id ? Db::find( 'booking_deliverables', $id ) : null;
			if ( $old && (int) $old->booking_id === (int) $booking_id ) {
				$res = Db::update( 'booking_deliverables', $id, $r );
				if ( isset( $r['status'] ) && $r['status'] !== $old->status ) {
					Db::log( 'delivery', $old->name . ': ' . $st[ $old->status ] . ' → ' . $st[ $r['status'] ], array( 'booking_id' => $booking_id ) );
				}
			} else {
				$r['created_by'] = get_current_user_id();
				$res             = $id = Db::insert( 'booking_deliverables', $r );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$keep[] = (int) $id;
		}
		$in = implode( ',', array_map( 'intval', $keep ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::t( 'booking_deliverables' ) . ' WHERE studio_id=%d AND booking_id=%d AND id NOT IN (' . ( $in ? $in : '0' ) . ')', $sid, $booking_id ) );
		return true;
	}

	private function can_see( $d ) {
		if ( Tenant::can( 'deliverables.view_all' ) ) {
			return true;
		}
		return (int) $d->assigned_to === $this->member_id();
	}

	/* ---------- Routes ---------- */

	public function index( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$w = array( $wpdb->prepare( 'd.studio_id=%d', $this->sid() ) );
		if ( ! Tenant::can( 'deliverables.view_all' ) ) {
			$w[] = $wpdb->prepare( 'd.assigned_to=%d', $this->member_id() );
		}
		$status = (string) $req->get_param( 'status' );
		if ( 'open' === $status ) {
			$w[] = "d.status<>'delivered'";
		} elseif ( $status ) {
			$vals = array_values( array_intersect( explode( ',', $status ), array_keys( Config::deliverable_statuses() ) ) );
			if ( $vals ) {
				$w[] = "d.status IN ('" . implode( "','", array_map( 'esc_sql', $vals ) ) . "')";
			}
		}
		if ( $req->get_param( 'assigned_to' ) ) {
			$w[] = 'unassigned' === $req->get_param( 'assigned_to' ) ? 'd.assigned_to IS NULL' : $wpdb->prepare( 'd.assigned_to=%d', $req->get_param( 'assigned_to' ) );
		}
		if ( $req->get_param( 'booking_id' ) ) {
			$w[] = $wpdb->prepare( 'd.booking_id=%d', $req->get_param( 'booking_id' ) );
		}
		if ( $req->get_param( 'overdue' ) ) {
			$w[] = $wpdb->prepare( "d.status<>'delivered' AND d.expected_date IS NOT NULL AND d.expected_date < %s", Db::today() );
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like = $this->like( $q );
			$w[]  = $wpdb->prepare( '(d.name LIKE %s OR b.number LIKE %s OR b.title LIKE %s OR c.name LIKE %s)', $like, $like, $like, $like );
		}
		$where = implode( ' AND ', $w );
		$join  = ' JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=d.booking_id JOIN ' . Db::t( 'clients' ) . ' c ON c.id=b.client_id LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id=d.assigned_to';
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'booking_deliverables' ) . " d $join WHERE $where" );
		$rows  = $wpdb->get_results( 'SELECT d.*, b.number AS booking_number, b.title AS booking_title, b.status AS booking_status, c.name AS client_name, m.name AS assigned_name, m.color AS assigned_color FROM ' . Db::t( 'booking_deliverables' ) . " d $join WHERE $where ORDER BY d.status='delivered', d.expected_date IS NULL, d.expected_date ASC, d.id DESC LIMIT $per OFFSET $off" );
		// Counts by status for the filter chips (same visibility, ignoring the status filter).
		$cw   = array( $wpdb->prepare( 'studio_id=%d', $this->sid() ) );
		if ( ! Tenant::can( 'deliverables.view_all' ) ) {
			$cw[] = $wpdb->prepare( 'assigned_to=%d', $this->member_id() );
		}
		$counts = array();
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . Db::t( 'booking_deliverables' ) . ' WHERE ' . implode( ' AND ', $cw ) . ' GROUP BY status' ) as $c ) {
			$counts[ $c->status ] = (int) $c->n;
		}
		$overdue = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'booking_deliverables' ) . ' WHERE ' . implode( ' AND ', $cw ) . " AND status<>'delivered' AND expected_date IS NOT NULL AND expected_date < %s", Db::today() ) );
		return $this->ok( array( 'items' => Db::cast_all( $rows ), 'total' => $total, 'page' => $page, 'per_page' => $per, 'counts' => $counts, 'overdue' => $overdue ) );
	}

	public function create( $req ) {
		$bid = (int) $req['id'];
		if ( ! Db::exists( 'bookings', $bid ) ) {
			return $this->not_found( 'Booking' );
		}
		$v = self::clean( $this->body( $req ) );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		global $wpdb;
		$v['booking_id'] = $bid;
		$v['sort']       = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort) FROM ' . Db::t( 'booking_deliverables' ) . ' WHERE studio_id=%d AND booking_id=%d', $this->sid(), $bid ) );
		$v['created_by'] = get_current_user_id();
		$v['updated_by'] = get_current_user_id();
		$id              = Db::insert( 'booking_deliverables', $v );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Db::log( 'delivery', 'Deliverable added: ' . $v['name'], array( 'booking_id' => $bid ) );
		return $this->ok( $this->one( $id ), 201 );
	}

	private function one( $id ) {
		global $wpdb;
		return Db::cast( $wpdb->get_row( $wpdb->prepare( 'SELECT d.*, m.name AS assigned_name, m.color AS assigned_color FROM ' . Db::t( 'booking_deliverables' ) . ' d LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id=d.assigned_to WHERE d.id=%d AND d.studio_id=%d', $id, $this->sid() ) ) );
	}

	/**
	 * Admin / production manager can change everything. Crew with
	 * deliverables.update can only move status, progress and link of the
	 * deliverables assigned to them.
	 */
	public function update( $req ) {
		$d = Db::find( 'booking_deliverables', (int) $req['id'] );
		if ( ! $d || ! $this->can_see( $d ) ) {
			return $this->not_found( 'Deliverable' );
		}
		$body = $this->body( $req );
		if ( ! Tenant::can( 'deliverables.edit' ) ) {
			if ( (int) $d->assigned_to !== $this->member_id() ) {
				return new \WP_Error( 'forbidden', 'You can only update deliverables assigned to you.', array( 'status' => 403 ) );
			}
			$extra = array_diff( array_keys( $body ), array( 'status', 'progress', 'delivery_link' ) );
			if ( $extra ) {
				return new \WP_Error( 'forbidden', 'You can update the status, progress and delivery link only.', array( 'status' => 403 ) );
			}
		}
		$v = self::clean( $body, true, $d );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$v['updated_by'] = get_current_user_id();
		$r               = Db::update( 'booking_deliverables', $d->id, $v );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$st = Config::deliverable_statuses();
		if ( isset( $v['status'] ) && $v['status'] !== $d->status ) {
			Db::log( 'delivery', $d->name . ': ' . $st[ $d->status ] . ' → ' . $st[ $v['status'] ], array( 'booking_id' => (int) $d->booking_id ), array( 'deliverable_id' => (int) $d->id, 'from' => $d->status, 'to' => $v['status'] ) );
		} elseif ( isset( $v['delivery_link'] ) && $v['delivery_link'] !== $d->delivery_link ) {
			Db::log( 'delivery', $d->name . ': delivery link ' . ( $v['delivery_link'] ? 'updated' : 'removed' ), array( 'booking_id' => (int) $d->booking_id ) );
		}
		return $this->ok( $this->one( $d->id ) );
	}

	public function destroy( $req ) {
		$d = Db::find( 'booking_deliverables', (int) $req['id'] );
		if ( ! $d ) {
			return $this->not_found( 'Deliverable' );
		}
		Db::delete( 'booking_deliverables', $d->id );
		Db::log( 'delivery', 'Deliverable removed: ' . $d->name, array( 'booking_id' => (int) $d->booking_id ) );
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Adds a template's deliverables to an existing booking. Expected dates are
	 * counted from the booking's last event date.
	 */
	public function apply_template( $req ) {
		global $wpdb;
		$bid = (int) $req['id'];
		if ( ! Db::exists( 'bookings', $bid ) ) {
			return $this->not_found( 'Booking' );
		}
		$b   = $this->body( $req );
		$tpl = isset( $b['template_id'] ) ? self::template( (int) $b['template_id'] ) : null;
		if ( ! $tpl ) {
			return $this->not_found( 'Template' );
		}
		$last = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(event_date) FROM ' . Db::t( 'booking_events' ) . ' WHERE studio_id=%d AND booking_id=%d', $this->sid(), $bid ) );
		$sort = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort) FROM ' . Db::t( 'booking_deliverables' ) . ' WHERE studio_id=%d AND booking_id=%d', $this->sid(), $bid ) );
		$have = array_map( 'strtolower', $wpdb->get_col( $wpdb->prepare( 'SELECT name FROM ' . Db::t( 'booking_deliverables' ) . ' WHERE studio_id=%d AND booking_id=%d', $this->sid(), $bid ) ) );
		$n    = 0;
		foreach ( $tpl->items as $it ) {
			if ( in_array( strtolower( $it->name ), $have, true ) ) {
				continue;
			}
			Db::insert(
				'booking_deliverables',
				array(
					'booking_id'    => $bid,
					'name'          => $it->name,
					'description'   => $it->description,
					'expected_date' => $last && null !== $it->days_after_event ? gmdate( 'Y-m-d', strtotime( $last . ' +' . (int) $it->days_after_event . ' days' ) ) : null,
					'status'        => 'not_started',
					'progress'      => 0,
					'sort'          => ++$sort,
					'created_by'    => get_current_user_id(),
					'updated_by'    => get_current_user_id(),
				)
			);
			$n++;
		}
		Db::log( 'delivery', 'Template "' . $tpl->name . '" applied: ' . $n . ' deliverable' . ( 1 === $n ? '' : 's' ) . ' added', array( 'booking_id' => $bid ) );
		return $this->ok( array( 'added' => $n, 'deliverables' => self::for_booking( $bid ) ) );
	}

	/* ---------- Templates ---------- */

	public static function template( $id ) {
		global $wpdb;
		$t = Db::cast( Db::find( 'deliverable_templates', $id ) );
		if ( ! $t ) {
			return null;
		}
		$t->items = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'deliverable_template_items' ) . ' WHERE studio_id=%d AND template_id=%d ORDER BY sort, id', Tenant::studio_id(), $id ) ) );
		return $t;
	}

	public function templates( $req ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'deliverable_templates' ) . ' WHERE studio_id=%d' . ( $req->get_param( 'active' ) ? ' AND is_active=1' : '' ) . ' ORDER BY name', $this->sid() ) );
		return $this->ok( array_map( array( __CLASS__, 'template' ), array_map( 'intval', $ids ) ) );
	}

	private function template_input( $req, $partial = false ) {
		$b = $this->body( $req );
		$v = Validator::validate(
			$b,
			array(
				'name'        => array( 'type' => 'string', 'required' => true, 'label' => 'Template name' ),
				'event_type'  => array( 'type' => 'enum', 'options' => Config::event_types(), 'label' => 'Event type' ),
				'description' => array( 'type' => 'text' ),
				'is_active'   => array( 'type' => 'bool', 'default' => 1 ),
			),
			$partial
		);
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$items = null;
		if ( isset( $b['items'] ) && is_array( $b['items'] ) ) {
			$items = array();
			foreach ( $b['items'] as $i => $it ) {
				$iv = Validator::validate(
					is_array( $it ) ? $it : array(),
					array(
						'name'             => array( 'type' => 'string', 'required' => true, 'label' => 'Deliverable name' ),
						'description'      => array( 'type' => 'text' ),
						'days_after_event' => array( 'type' => 'int', 'label' => 'Days after event' ),
					)
				);
				if ( is_wp_error( $iv ) ) {
					return new \WP_Error( 'validation_failed', 'Item ' . ( $i + 1 ) . ': ' . $iv->get_error_message(), array( 'status' => 422 ) );
				}
				if ( null !== $iv['days_after_event'] && ( $iv['days_after_event'] < 0 || $iv['days_after_event'] > 730 ) ) {
					return new \WP_Error( 'validation_failed', 'Item ' . ( $i + 1 ) . ': days after event must be between 0 and 730.', array( 'status' => 422 ) );
				}
				$iv['sort'] = $i;
				$items[]    = $iv;
			}
			if ( ! $items ) {
				return new \WP_Error( 'validation_failed', 'Add at least one deliverable to the template.', array( 'status' => 422 ) );
			}
		} elseif ( ! $partial ) {
			return new \WP_Error( 'validation_failed', 'Add at least one deliverable to the template.', array( 'status' => 422 ) );
		}
		return array( $v, $items );
	}

	private function save_items( $tid, array $items ) {
		Db::delete_where( 'deliverable_template_items', array( 'template_id' => (int) $tid ) );
		foreach ( $items as $it ) {
			$it['template_id'] = (int) $tid;
			Db::insert( 'deliverable_template_items', $it );
		}
	}

	public function create_template( $req ) {
		$in = $this->template_input( $req );
		if ( is_wp_error( $in ) ) {
			return $in;
		}
		list( $v, $items ) = $in;
		Db::begin();
		$id = Db::insert( 'deliverable_templates', $v );
		if ( is_wp_error( $id ) ) {
			Db::rollback();
			return $id;
		}
		$this->save_items( $id, $items );
		Db::commit();
		return $this->ok( self::template( $id ), 201 );
	}

	public function update_template( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'deliverable_templates', $id ) ) {
			return $this->not_found( 'Template' );
		}
		$in = $this->template_input( $req, true );
		if ( is_wp_error( $in ) ) {
			return $in;
		}
		list( $v, $items ) = $in;
		Db::begin();
		Db::update( 'deliverable_templates', $id, $v );
		if ( null !== $items ) {
			$this->save_items( $id, $items );
		}
		Db::commit();
		return $this->ok( self::template( $id ) );
	}

	public function delete_template( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'deliverable_templates', $id ) ) {
			return $this->not_found( 'Template' );
		}
		Db::delete_where( 'deliverable_template_items', array( 'template_id' => $id ) );
		Db::delete( 'deliverable_templates', $id );
		return $this->ok( array( 'deleted' => true ) );
	}
}
