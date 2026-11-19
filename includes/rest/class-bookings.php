<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Tenant;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

class Bookings extends Base {

	private function schema() {
		return array(
			'client_id'            => array( 'type' => 'ref', 'table' => 'clients', 'label' => 'Client' ),
			'lead_id'              => array( 'type' => 'ref', 'table' => 'leads', 'label' => 'Lead' ),
			'quotation_id'         => array( 'type' => 'ref', 'table' => 'quotations', 'label' => 'Quotation' ),
			'title'                => array( 'type' => 'string', 'required' => true ),
			'event_type'           => array( 'type' => 'enum', 'options' => Config::event_types(), 'label' => 'Event type' ),
			'package_details'      => array( 'type' => 'text', 'label' => 'Package details' ),
			'total_amount'         => array( 'type' => 'money', 'required' => true, 'label' => 'Total package amount' ),
			'status'               => array( 'type' => 'enum', 'options' => Config::booking_statuses(), 'default' => 'confirmed' ),
			'confirmed_on'         => array( 'type' => 'date', 'label' => 'Booking confirmation date' ),
			'delivery_due'         => array( 'type' => 'date', 'label' => 'Delivery due date' ),
			'special_instructions' => array( 'type' => 'text', 'label' => 'Special instructions' ),
		);
	}

	private function event_schema() {
		return array(
			'name'       => array( 'type' => 'string', 'required' => true, 'label' => 'Event name' ),
			'event_type' => array( 'type' => 'enum', 'options' => Config::event_types(), 'label' => 'Event type' ),
			'event_date' => array( 'type' => 'date', 'required' => true, 'label' => 'Event date' ),
			'start_time' => array( 'type' => 'time', 'label' => 'Start time' ),
			'end_time'   => array( 'type' => 'time', 'label' => 'End time' ),
			'venue'      => array( 'type' => 'string', 'max' => 255 ),
			'city'       => array( 'type' => 'string', 'max' => 100 ),
			'address'    => array( 'type' => 'text' ),
			'notes'      => array( 'type' => 'text' ),
		);
	}

	private function service_schema() {
		return array(
			'service_id' => array( 'type' => 'ref', 'table' => 'services', 'label' => 'Service' ),
			'name'       => array( 'type' => 'string', 'required' => true, 'label' => 'Service name' ),
			'category'   => array( 'type' => 'enum', 'options' => Config::service_categories(), 'label' => 'Category' ),
			'on_site'    => array( 'type' => 'bool', 'default' => 1 ),
			'notes'      => array( 'type' => 'text' ),
		);
	}

	public function register() {
		$this->route( '/bookings', 'GET', array( $this, 'index' ), 'bookings.view' );
		$this->route( '/bookings', 'POST', array( $this, 'create' ), 'bookings.edit' );
		$this->route( '/bookings/conflicts', 'POST', array( $this, 'conflicts' ), array( 'bookings.edit', 'bookings.assign' ) );
		$this->route( '/bookings/(?P<id>\d+)', 'GET', array( $this, 'show' ), 'bookings.view' );
		$this->route( '/bookings/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'bookings.edit' );
		$this->route( '/bookings/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'bookings.delete' );
		$this->route( '/bookings/(?P<id>\d+)/status', 'POST', array( $this, 'set_status' ), array( 'bookings.edit', 'bookings.assign' ) );
		$this->route( '/bookings/(?P<id>\d+)/notes', 'POST', array( $this, 'add_note' ), array( 'bookings.edit', 'bookings.assign' ) );
		$this->route( '/booking-events/(?P<id>\d+)/team', 'PUT', array( $this, 'assign_team' ), 'bookings.assign' );
		$this->route( '/bookings/(?P<id>\d+)/services', 'PUT', array( $this, 'update_services' ), array( 'bookings.edit', 'bookings.assign' ) );
		$this->route( '/leads/(?P<id>\d+)/convert', 'GET', array( $this, 'convert_preview' ), 'bookings.edit' );
	}

	/**
	 * Booking ids visible to members who can only see their own assignments.
	 */
	private function visibility_sql( $alias = 'b' ) {
		if ( Tenant::can( 'bookings.view_all' ) ) {
			return '';
		}
		return ' AND ' . $alias . '.id IN (' . self::member_booking_ids_sql( $this->member_id() ) . ')';
	}

	/**
	 * Booking ids a crew member works on: event team, a service team, or an
	 * assigned deliverable.
	 */
	public static function member_booking_ids_sql( $member_id ) {
		global $wpdb;
		$sid = Tenant::studio_id();
		return $wpdb->prepare(
			'SELECT e.booking_id FROM ' . Db::t( 'booking_events' ) . ' e JOIN ' . Db::t( 'event_assignments' ) . ' a ON a.booking_event_id=e.id WHERE a.studio_id=%d AND a.member_id=%d
			UNION SELECT booking_id FROM ' . Db::t( 'service_team_assignments' ) . ' WHERE studio_id=%d AND member_id=%d
			UNION SELECT booking_id FROM ' . Db::t( 'booking_deliverables' ) . ' WHERE studio_id=%d AND assigned_to=%d',
			$sid,
			$member_id,
			$sid,
			$member_id,
			$sid,
			$member_id
		);
	}

	/**
	 * Who works on which event: the event's own team plus everyone on an
	 * on-site service of that booking (on-site services cover every event date).
	 * Columns: member_id, booking_event_id, role, source, service_name.
	 */
	public static function work_sql() {
		global $wpdb;
		$sid = Tenant::studio_id();
		return $wpdb->prepare(
			"(SELECT a.member_id, a.booking_event_id, a.role, 'event' AS source, '' AS service_name FROM " . Db::t( 'event_assignments' ) . ' a WHERE a.studio_id=%d
			UNION ALL
			SELECT sta.member_id, e.id AS booking_event_id, \'service\' AS role, \'service\' AS source, s.name AS service_name FROM ' . Db::t( 'service_team_assignments' ) . ' sta JOIN ' . Db::t( 'booking_services' ) . ' s ON s.id=sta.booking_service_id JOIN ' . Db::t( 'booking_events' ) . ' e ON e.booking_id=s.booking_id AND e.studio_id=s.studio_id WHERE sta.studio_id=%d AND s.on_site=1)',
			$sid,
			$sid
		);
	}

	/**
	 * Payment summary for a booking: paid, pending, status and per-installment status.
	 */
	public static function payment_summary( $booking, $installments = null, $paid = null ) {
		global $wpdb;
		$sid = (int) $booking->studio_id;
		if ( null === $installments ) {
			$installments = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'installments' ) . ' WHERE studio_id=%d AND booking_id=%d ORDER BY sort, due_date, id', $sid, $booking->id ) ) );
		}
		if ( null === $paid ) {
			$paid = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'payments' ) . " WHERE studio_id=%d AND booking_id=%d AND status='received'", $sid, $booking->id ) );
		}
		$total   = (float) $booking->total_amount;
		$pending = round( max( 0, $total - $paid ), 2 );
		$today   = Db::today();
		$left    = $paid;
		$overdue = false;
		$next    = null;
		foreach ( $installments as $in ) {
			$alloc         = min( $left, (float) $in->amount );
			$left         -= $alloc;
			$in->paid      = round( $alloc, 2 );
			$in->balance   = round( (float) $in->amount - $alloc, 2 );
			if ( $in->balance <= 0.009 ) {
				$in->status = 'paid';
			} elseif ( $in->due_date && $in->due_date < $today ) {
				$in->status = 'overdue';
				$overdue    = true;
			} elseif ( $alloc > 0 ) {
				$in->status = 'partial';
			} else {
				$in->status = 'pending';
			}
			if ( ! $next && 'paid' !== $in->status ) {
				$next = $in;
			}
		}
		if ( 'cancelled' === $booking->status ) {
			$status = 'cancelled';
		} elseif ( $total > 0 && $pending <= 0.009 ) {
			$status = 'paid';
		} elseif ( $overdue ) {
			$status = 'overdue';
		} elseif ( $paid > 0 ) {
			$status = 'partial';
		} else {
			$status = 'pending';
		}
		return array(
			'total'        => $total,
			'paid'         => round( $paid, 2 ),
			'pending'      => $pending,
			'status'       => $status,
			'next_due'     => $next ? array( 'label' => $next->label, 'amount' => $next->balance, 'due_date' => $next->due_date ) : null,
			'installments' => $installments,
		);
	}

	public function index( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$w = array( $wpdb->prepare( 'b.studio_id=%d', $this->sid() ) );
		$s = $req->get_param( 'status' );
		if ( $s ) {
			$vals = array_values( array_intersect( explode( ',', $s ), array_keys( Config::booking_statuses() ) ) );
			if ( $vals ) {
				$w[] = "b.status IN ('" . implode( "','", array_map( 'esc_sql', $vals ) ) . "')";
			}
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like = $this->like( $q );
			$w[]  = $wpdb->prepare( '(b.number LIKE %s OR b.title LIKE %s OR c.name LIKE %s OR c.phone LIKE %s)', $like, $like, $like, $like );
		}
		if ( $req->get_param( 'client_id' ) ) {
			$w[] = $wpdb->prepare( 'b.client_id=%d', $req->get_param( 'client_id' ) );
		}
		if ( $req->get_param( 'event_type' ) && array_key_exists( $req->get_param( 'event_type' ), Config::event_types() ) ) {
			$w[] = $wpdb->prepare( 'b.event_type=%s', $req->get_param( 'event_type' ) );
		}
		$from = Validator::date( (string) $req->get_param( 'from' ) );
		$to   = Validator::date( (string) $req->get_param( 'to' ) );
		if ( $from || $to ) {
			$w[] = 'b.id IN (SELECT booking_id FROM ' . Db::t( 'booking_events' ) . ' WHERE ' . $wpdb->prepare( 'studio_id=%d', $this->sid() ) . ( $from ? $wpdb->prepare( ' AND event_date >= %s', $from ) : '' ) . ( $to ? $wpdb->prepare( ' AND event_date <= %s', $to ) : '' ) . ')';
		}
		$where = implode( ' AND ', $w ) . $this->visibility_sql();
		$join  = ' JOIN ' . Db::t( 'clients' ) . ' c ON c.id=b.client_id';
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " b $join WHERE $where" );
		$e     = Db::t( 'booking_events' );
		$rows  = $wpdb->get_results(
			'SELECT b.*, c.name AS client_name, c.phone AS client_phone,
				(SELECT MIN(event_date) FROM ' . $e . ' WHERE booking_id=b.id AND studio_id=b.studio_id) AS first_date,
				(SELECT MAX(event_date) FROM ' . $e . ' WHERE booking_id=b.id AND studio_id=b.studio_id) AS last_date,
				(SELECT COUNT(*) FROM ' . $e . ' WHERE booking_id=b.id AND studio_id=b.studio_id) AS events_count,
				(SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'payments' ) . " WHERE booking_id=b.id AND studio_id=b.studio_id AND status='received') AS paid
			FROM " . Db::t( 'bookings' ) . " b $join WHERE $where ORDER BY first_date IS NULL, first_date ASC, b.id DESC LIMIT $per OFFSET $off"
		);
		$show_money = Tenant::can( 'payments.view' );
		foreach ( $rows as $r ) {
			$r->events_count = (int) $r->events_count;
			$sum             = self::payment_summary( $r, null, (float) $r->paid );
			$r->pending      = $sum['pending'];
			$r->payment_status = $sum['status'];
			if ( ! $show_money ) {
				unset( $r->total_amount, $r->paid, $r->pending );
			}
		}
		return $this->ok( array( 'items' => Db::cast_all( $rows ), 'total' => $total, 'page' => $page, 'per_page' => $per ) );
	}

	public static function load( $id ) {
		global $wpdb;
		$b = Db::find( 'bookings', $id );
		if ( ! $b ) {
			return null;
		}
		$sid       = (int) $b->studio_id;
		$b         = Db::cast( $b );
		$b->client = Db::cast( Db::find( 'clients', $b->client_id ) );
		$events    = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'booking_events' ) . ' WHERE studio_id=%d AND booking_id=%d ORDER BY event_date, start_time, id', $sid, $id ) ) );
		$eids      = array_map( 'intval', wp_list_pluck( $events, 'id' ) );
		$team      = $eids ? Db::cast_all( $wpdb->get_results( 'SELECT a.*, m.name, m.phone, m.role AS member_role, m.color FROM ' . Db::t( 'event_assignments' ) . ' a JOIN ' . Db::t( 'members' ) . ' m ON m.id=a.member_id WHERE a.studio_id=' . $sid . ' AND a.booking_event_id IN (' . implode( ',', $eids ) . ')' ) ) : array();
		foreach ( $events as $e ) {
			$e->team = array_values(
				array_filter(
					$team,
					function ( $t ) use ( $e ) {
						return $t->booking_event_id === $e->id;
					}
				)
			);
		}
		$b->events    = $events;
		$b->payments  = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT p.*, u.display_name AS created_by_name FROM ' . Db::t( 'payments' ) . ' p LEFT JOIN ' . $wpdb->users . ' u ON u.ID=p.created_by WHERE p.studio_id=%d AND p.booking_id=%d ORDER BY p.paid_on, p.id', $sid, $id ) ) );
		$b->payment   = self::payment_summary( $b );
		$b->expenses  = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'expenses' ) . ' WHERE studio_id=%d AND booking_id=%d ORDER BY spent_on DESC', $sid, $id ) ) );
		$acts         = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, u.display_name AS user_name FROM ' . Db::t( 'activities' ) . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID=a.user_id WHERE a.studio_id=%d AND a.booking_id=%d ORDER BY a.created_at DESC, a.id DESC LIMIT 200', $sid, $id ) );
		$b->activities = Db::cast_all( $acts );
		$b->tasks     = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT t.*, m.name AS assigned_name FROM ' . Db::t( 'tasks' ) . ' t LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id=t.assigned_to WHERE t.studio_id=%d AND t.booking_id=%d ORDER BY t.status=\'done\', t.due_at', $sid, $id ) ) );
		$b->documents = Documents::list_for( array( 'booking_ids' => array( $id ) ) );
		$b->quotation = $b->quotation_id ? Db::cast( $wpdb->get_row( $wpdb->prepare( 'SELECT id, number, title, total, status FROM ' . Db::t( 'quotations' ) . ' WHERE id=%d AND studio_id=%d', $b->quotation_id, $sid ) ) ) : null;
		$b->services     = self::services_for( $id, $sid );
		$b->deliverables = Deliverables::for_booking( $id, $sid );
		$b->delivery_progress = Deliverables::overall( $b->deliverables );
		$b->portal       = Tenant::can( 'portal.manage' ) ? \PCRM\Portals::summary( \PCRM\Portals::ensure( $id ) ) : null;
		return $b;
	}

	/**
	 * Services of a booking with their assigned team.
	 */
	public static function services_for( $booking_id, $sid = null ) {
		global $wpdb;
		$sid  = null === $sid ? Tenant::studio_id() : (int) $sid;
		$rows = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'booking_services' ) . ' WHERE studio_id=%d AND booking_id=%d ORDER BY sort, id', $sid, $booking_id ) ) );
		if ( ! $rows ) {
			return array();
		}
		$team = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT sta.booking_service_id, sta.member_id, m.name, m.color, m.phone, m.photo_url, m.team_role, m.status FROM ' . Db::t( 'service_team_assignments' ) . ' sta JOIN ' . Db::t( 'members' ) . ' m ON m.id=sta.member_id WHERE sta.studio_id=%d AND sta.booking_id=%d ORDER BY m.name', $sid, $booking_id ) ) );
		foreach ( $rows as $r ) {
			$r->on_site = (bool) $r->on_site;
			$r->team    = array_values(
				array_filter(
					$team,
					function ( $t ) use ( $r ) {
						return $t->booking_service_id === $r->id;
					}
				)
			);
		}
		return $rows;
	}

	public function show( $req ) {
		$id = (int) $req['id'];
		if ( ! Tenant::can( 'bookings.view_all' ) ) {
			global $wpdb;
			$ok = $wpdb->get_var( $wpdb->prepare( 'SELECT b.id FROM ' . Db::t( 'bookings' ) . ' b WHERE b.id=%d AND b.studio_id=%d' . $this->visibility_sql(), $id, $this->sid() ) );
			if ( ! $ok ) {
				return $this->not_found( 'Booking' );
			}
		}
		$b = self::load( $id );
		if ( ! $b ) {
			return $this->not_found( 'Booking' );
		}
		if ( ! Tenant::can( 'payments.view' ) ) {
			unset( $b->payments, $b->payment, $b->expenses, $b->total_amount );
			if ( $b->quotation ) {
				unset( $b->quotation->total );
			}
			// Timeline entries such as "Booking confirmed for ₹…" carry amounts.
			$b->activities = array_values(
				array_filter(
					$b->activities,
					function ( $a ) {
						return ! in_array( $a->type, array( 'payment', 'expense', 'quotation' ), true ) && false === strpos( (string) $a->message, '₹' );
					}
				)
			);
		}
		if ( ! Tenant::can( 'expenses.view' ) ) {
			unset( $b->expenses );
		}
		if ( ! Tenant::can( 'deliverables.view' ) ) {
			unset( $b->deliverables, $b->delivery_progress );
		}
		return $this->ok( $b );
	}

	/**
	 * Pre-filled booking from a lead (and its latest accepted/sent quotation).
	 */
	public function convert_preview( $req ) {
		global $wpdb;
		$lead = Db::find( 'leads', (int) $req['id'] );
		if ( ! $lead ) {
			return $this->not_found( 'Lead' );
		}
		$q = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'quotations' ) . " WHERE studio_id=%d AND lead_id=%d ORDER BY FIELD(status,'accepted','viewed','sent','draft','rejected','expired'), id DESC LIMIT 1", $this->sid(), $lead->id ) );
		$items = $q ? $wpdb->get_col( $wpdb->prepare( 'SELECT CONCAT(name, IF(qty<>1, CONCAT(\' × \', TRIM(TRAILING \'.00\' FROM qty)), \'\')) FROM ' . Db::t( 'quotation_items' ) . ' WHERE quotation_id=%d AND studio_id=%d ORDER BY sort', $q->id, $this->sid() ) ) : array();
		$types = Config::event_types();
		$events = array();
		if ( $lead->event_date ) {
			$start = strtotime( $lead->event_date );
			$end   = $lead->event_end_date ? strtotime( $lead->event_end_date ) : $start;
			$days  = 0;
			for ( $d = $start; $d <= $end && $days < 10; $d += DAY_IN_SECONDS, $days++ ) {
				$events[] = array(
					'name'       => ( $lead->event_type ? $types[ $lead->event_type ] : 'Event' ) . ( $end > $start ? ' – Day ' . ( $days + 1 ) : '' ),
					'event_type' => $lead->event_type,
					'event_date' => date( 'Y-m-d', $d ),
					'venue'      => $lead->venue,
					'city'       => $lead->city,
					'team'       => array(),
				);
			}
		}
		return $this->ok(
			array(
				'lead_id'         => (int) $lead->id,
				'client_id'       => $lead->client_id ? (int) $lead->client_id : null,
				'client'          => array( 'name' => $lead->name, 'phone' => $lead->phone, 'email' => $lead->email, 'city' => $lead->city ),
				'quotation_id'    => $q ? (int) $q->id : null,
				'title'           => $lead->name . ( $lead->event_type ? ' – ' . $types[ $lead->event_type ] : '' ),
				'event_type'      => $lead->event_type,
				'package_details' => $items ? implode( "\n", $items ) : '',
				'total_amount'    => $q ? (float) $q->total : (float) $lead->budget,
				'confirmed_on'    => Db::today(),
				'events'          => $events,
			)
		);
	}

	/**
	 * Finds team members already booked on overlapping events.
	 */
	public static function find_conflicts( array $events, $booking_id = 0, array $services = array() ) {
		global $wpdb;
		$out = array();
		$sid = Tenant::studio_id();
		// Members on on-site services work every event date of this booking.
		$svc = array();
		foreach ( $services as $si => $s ) {
			if ( isset( $s['on_site'] ) && ! filter_var( $s['on_site'], FILTER_VALIDATE_BOOLEAN ) ) {
				continue;
			}
			foreach ( ( isset( $s['team'] ) && is_array( $s['team'] ) ? $s['team'] : array() ) as $t ) {
				$mid = is_array( $t ) ? ( isset( $t['member_id'] ) ? (int) $t['member_id'] : 0 ) : (int) $t;
				if ( $mid ) {
					$svc[ $mid ][] = $si;
				}
			}
		}
		$work = self::work_sql();
		foreach ( $events as $idx => $ev ) {
			if ( empty( $ev['event_date'] ) || ! Validator::date( (string) $ev['event_date'] ) ) {
				continue;
			}
			$mids = ! empty( $ev['team'] ) && is_array( $ev['team'] ) ? array_map( 'intval', wp_list_pluck( $ev['team'], 'member_id' ) ) : array();
			$mids = array_values( array_unique( array_filter( array_merge( $mids, array_keys( $svc ) ) ) ) );
			if ( ! $mids ) {
				continue;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DISTINCT w.member_id, m.name AS member_name, w.source, w.service_name, e.id AS event_id, e.name AS event_name, e.event_date, e.start_time, e.end_time, e.venue, b.id AS booking_id, b.number AS booking_number, b.title AS booking_title
					FROM ' . $work . ' w
					JOIN ' . Db::t( 'booking_events' ) . ' e ON e.id=w.booking_event_id
					JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=e.booking_id
					JOIN ' . Db::t( 'members' ) . " m ON m.id=w.member_id
					WHERE e.studio_id=%d AND e.event_date=%s AND b.id<>%d AND b.status<>'cancelled' AND w.member_id IN (" . implode( ',', $mids ) . ')',
					$sid,
					Validator::date( $ev['event_date'] ),
					(int) $booking_id
				)
			);
			foreach ( $rows as $r ) {
				$s1 = ! empty( $ev['start_time'] ) ? $ev['start_time'] : null;
				$e1 = ! empty( $ev['end_time'] ) ? $ev['end_time'] : null;
				// Only when both events have times can we tell they don't overlap.
				if ( $s1 && $e1 && $r->start_time && $r->end_time && ( $e1 <= $r->start_time || $s1 >= $r->end_time ) ) {
					continue;
				}
				$r->index    = $idx;
				$r->services = isset( $svc[ (int) $r->member_id ] ) ? $svc[ (int) $r->member_id ] : array();
				$out[]       = Db::cast( $r );
			}
		}
		return $out;
	}

	public function conflicts( $req ) {
		$b = $this->body( $req );
		$events = isset( $b['events'] ) && is_array( $b['events'] ) ? $b['events'] : array();
		$bid    = isset( $b['booking_id'] ) ? (int) $b['booking_id'] : 0;
		// Assigning a team from the booking page sends only services: use the saved events.
		if ( ! $events && $bid && Db::exists( 'bookings', $bid ) ) {
			global $wpdb;
			$events = array_map( 'get_object_vars', $wpdb->get_results( $wpdb->prepare( 'SELECT id, event_date, start_time, end_time FROM ' . Db::t( 'booking_events' ) . ' WHERE studio_id=%d AND booking_id=%d', $this->sid(), $bid ) ) );
		}
		return $this->ok( self::find_conflicts( $events, $bid, isset( $b['services'] ) && is_array( $b['services'] ) ? $b['services'] : array() ) );
	}

	/**
	 * Validates nested events and installments from the request.
	 */
	private function parse_nested( $b, $total ) {
		$events = array();
		foreach ( ( isset( $b['events'] ) && is_array( $b['events'] ) ? $b['events'] : array() ) as $i => $ev ) {
			$v = Validator::validate( is_array( $ev ) ? $ev : array(), $this->event_schema() );
			if ( is_wp_error( $v ) ) {
				return new \WP_Error( 'validation_failed', 'Event ' . ( $i + 1 ) . ': ' . $v->get_error_message(), array( 'status' => 422 ) );
			}
			if ( ! empty( $v['start_time'] ) && ! empty( $v['end_time'] ) && $v['end_time'] <= $v['start_time'] && $v['end_time'] !== '00:00:00' ) {
				return new \WP_Error( 'validation_failed', 'Event ' . ( $i + 1 ) . ': end time must be after start time.', array( 'status' => 422 ) );
			}
			$v['id']   = isset( $ev['id'] ) ? (int) $ev['id'] : 0;
			$v['team'] = array();
			foreach ( ( isset( $ev['team'] ) && is_array( $ev['team'] ) ? $ev['team'] : array() ) as $t ) {
				$mid  = isset( $t['member_id'] ) ? (int) $t['member_id'] : 0;
				$role = isset( $t['role'] ) && array_key_exists( $t['role'], Config::event_roles() ) ? $t['role'] : 'photographer';
				if ( ! $mid || ! Db::exists( 'members', $mid ) ) {
					return new \WP_Error( 'validation_failed', 'Event ' . ( $i + 1 ) . ': team member not found.', array( 'status' => 422 ) );
				}
				$v['team'][ $mid ] = array( 'member_id' => $mid, 'role' => $role );
			}
			$v['team'] = array_values( $v['team'] );
			$events[]  = $v;
		}
		if ( ! $events ) {
			return new \WP_Error( 'validation_failed', 'Add at least one event date.', array( 'status' => 422 ) );
		}
		$inst = array();
		$sum  = 0;
		foreach ( ( isset( $b['installments'] ) && is_array( $b['installments'] ) ? $b['installments'] : array() ) as $i => $in ) {
			$v = Validator::validate(
				is_array( $in ) ? $in : array(),
				array(
					'label'    => array( 'type' => 'string', 'required' => true, 'max' => 100 ),
					'amount'   => array( 'type' => 'money', 'required' => true ),
					'due_date' => array( 'type' => 'date', 'label' => 'Due date' ),
				)
			);
			if ( is_wp_error( $v ) ) {
				return new \WP_Error( 'validation_failed', 'Installment ' . ( $i + 1 ) . ': ' . $v->get_error_message(), array( 'status' => 422 ) );
			}
			$v['id']   = isset( $in['id'] ) ? (int) $in['id'] : 0;
			$v['sort'] = $i;
			$sum      += $v['amount'];
			$inst[]    = $v;
		}
		if ( $sum - $total > 0.009 ) {
			return new \WP_Error( 'validation_failed', 'Installments add up to more than the total package amount.', array( 'status' => 422 ) );
		}
		return array( $events, $inst );
	}

	/**
	 * Validates the services list: each needs a name, team members must be in
	 * this studio's directory.
	 */
	private function parse_services( $b ) {
		$out = array();
		foreach ( ( isset( $b['services'] ) && is_array( $b['services'] ) ? $b['services'] : array() ) as $i => $sv ) {
			$v = Validator::validate( is_array( $sv ) ? $sv : array(), $this->service_schema() );
			if ( is_wp_error( $v ) ) {
				return new \WP_Error( 'validation_failed', 'Service ' . ( $i + 1 ) . ': ' . $v->get_error_message(), array( 'status' => 422 ) );
			}
			$v['id']   = isset( $sv['id'] ) ? (int) $sv['id'] : 0;
			$v['sort'] = $i;
			$team      = array();
			foreach ( ( isset( $sv['team'] ) && is_array( $sv['team'] ) ? $sv['team'] : array() ) as $t ) {
				$mid = is_array( $t ) ? ( isset( $t['member_id'] ) ? (int) $t['member_id'] : 0 ) : (int) $t;
				if ( ! $mid || ! Db::exists( 'members', $mid ) ) {
					return new \WP_Error( 'validation_failed', 'Service ' . ( $i + 1 ) . ' (' . $v['name'] . '): team member not found.', array( 'status' => 422 ) );
				}
				$team[ $mid ] = $mid;
			}
			$v['team'] = array_values( $team );
			$out[]     = $v;
		}
		return $out;
	}

	public static function save_services( $booking_id, array $services ) {
		global $wpdb;
		$sid  = Tenant::studio_id();
		$keep = array();
		foreach ( $services as $sv ) {
			$team = $sv['team'];
			$id   = $sv['id'];
			unset( $sv['team'], $sv['id'] );
			$sv['booking_id'] = $booking_id;
			if ( $id && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'booking_services' ) . ' WHERE id=%d AND booking_id=%d AND studio_id=%d', $id, $booking_id, $sid ) ) ) {
				Db::update( 'booking_services', $id, $sv );
			} else {
				$id = Db::insert( 'booking_services', $sv );
			}
			$id     = (int) $id;
			$keep[] = $id;
			Db::delete_where( 'service_team_assignments', array( 'booking_service_id' => $id ) );
			foreach ( $team as $mid ) {
				Db::insert( 'service_team_assignments', array( 'booking_service_id' => $id, 'booking_id' => $booking_id, 'member_id' => (int) $mid ) );
			}
		}
		$in = implode( ',', array_map( 'intval', $keep ) );
		$in = $in ? $in : '0';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::t( 'service_team_assignments' ) . " WHERE studio_id=%d AND booking_id=%d AND booking_service_id NOT IN ($in)", $sid, $booking_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::t( 'booking_services' ) . " WHERE studio_id=%d AND booking_id=%d AND id NOT IN ($in)", $sid, $booking_id ) );
	}

	/** One line for the timeline: "Candid Photography: Akshay, Rahul". */
	private static function services_summary( $booking_id ) {
		$parts = array();
		foreach ( self::services_for( $booking_id ) as $s ) {
			$parts[] = $s->name . ': ' . ( $s->team ? implode( ', ', wp_list_pluck( $s->team, 'name' ) ) : 'no team' );
		}
		return implode( ' · ', $parts );
	}

	private function save_events( $booking_id, array $events ) {
		global $wpdb;
		$sid  = $this->sid();
		$keep = array();
		foreach ( $events as $ev ) {
			$team = $ev['team'];
			$eid  = $ev['id'];
			unset( $ev['team'], $ev['id'] );
			$ev['booking_id'] = $booking_id;
			if ( $eid && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'booking_events' ) . ' WHERE id=%d AND booking_id=%d AND studio_id=%d', $eid, $booking_id, $sid ) ) ) {
				Db::update( 'booking_events', $eid, $ev );
			} else {
				$eid = Db::insert( 'booking_events', $ev );
			}
			$keep[] = (int) $eid;
			$this->save_team( $eid, $team );
		}
		$old = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'booking_events' ) . ' WHERE booking_id=%d AND studio_id=%d AND id NOT IN (' . $this->ids_in( $keep ) . ')', $booking_id, $sid ) );
		foreach ( $old as $oid ) {
			Db::delete_where( 'event_assignments', array( 'booking_event_id' => (int) $oid ) );
			Db::delete( 'booking_events', $oid );
		}
	}

	private function save_team( $event_id, array $team ) {
		Db::delete_where( 'event_assignments', array( 'booking_event_id' => (int) $event_id ) );
		foreach ( $team as $t ) {
			Db::insert( 'event_assignments', array( 'booking_event_id' => (int) $event_id, 'member_id' => $t['member_id'], 'role' => $t['role'] ) );
		}
	}

	private function save_installments( $booking_id, array $inst ) {
		global $wpdb;
		$sid  = $this->sid();
		$keep = array();
		foreach ( $inst as $in ) {
			$iid = $in['id'];
			unset( $in['id'] );
			$in['booking_id'] = $booking_id;
			if ( $iid && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'installments' ) . ' WHERE id=%d AND booking_id=%d AND studio_id=%d', $iid, $booking_id, $sid ) ) ) {
				Db::update( 'installments', $iid, $in );
			} else {
				$iid = Db::insert( 'installments', $in );
			}
			$keep[] = (int) $iid;
		}
		$in_sql = $this->ids_in( $keep );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'payments' ) . " SET installment_id=NULL WHERE studio_id=%d AND booking_id=%d AND installment_id NOT IN ($in_sql)", $sid, $booking_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::t( 'installments' ) . " WHERE studio_id=%d AND booking_id=%d AND id NOT IN ($in_sql)", $sid, $booking_id ) );
	}

	public function create( $req ) {
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$b      = $this->body( $req );
		$nested = $this->parse_nested( $b, $data['total_amount'] );
		if ( is_wp_error( $nested ) ) {
			return $nested;
		}
		list( $events, $inst ) = $nested;
		$services = $this->parse_services( $b );
		if ( is_wp_error( $services ) ) {
			return $services;
		}
		$deliverables = null;
		if ( isset( $b['deliverables'] ) && is_array( $b['deliverables'] ) && Tenant::can( 'deliverables.edit' ) ) {
			$deliverables = Deliverables::parse_list( $b['deliverables'] );
			if ( is_wp_error( $deliverables ) ) {
				return $deliverables;
			}
		}
		if ( empty( $b['force'] ) ) {
			$conf = self::find_conflicts( $events, 0, $services );
			if ( $conf ) {
				return new \WP_Error( 'conflict', 'Some team members are already booked on these dates.', array( 'status' => 409, 'conflicts' => $conf ) );
			}
		} elseif ( ! self::can_override() && self::find_conflicts( $events, 0, $services ) ) {
			return new \WP_Error( 'forbidden', 'Only an admin or production manager can override a double booking.', array( 'status' => 403 ) );
		}
		$advance = null;
		// Recording money is admin-only, also when it comes in with a new booking.
		if ( ! empty( $b['advance'] ) && is_array( $b['advance'] ) && ! empty( $b['advance']['amount'] ) && ! \PCRM\Permissions::is_admin( Tenant::member() ) ) {
			return new \WP_Error( 'forbidden', 'Only the studio owner or an admin can record payments.', array( 'status' => 403 ) );
		}
		if ( ! empty( $b['advance'] ) && is_array( $b['advance'] ) && ! empty( $b['advance']['amount'] ) ) {
			$advance = Validator::validate( $b['advance'], Payments::schema() );
			if ( is_wp_error( $advance ) ) {
				return $advance;
			}
			if ( $advance['amount'] - $data['total_amount'] > 0.009 ) {
				return new \WP_Error( 'validation_failed', 'Advance cannot be more than the total package amount.', array( 'status' => 422 ) );
			}
		}

		Db::begin();
		$lead = $data['lead_id'] ? Db::find( 'leads', $data['lead_id'] ) : null;
		if ( ! $data['client_id'] ) {
			if ( $lead ) {
				$cid = Clients::ensure_from_lead( $lead );
			} elseif ( ! empty( $b['client'] ) && is_array( $b['client'] ) ) {
				$cdata = Validator::validate( $b['client'], Clients::schema() );
				if ( is_wp_error( $cdata ) ) {
					Db::rollback();
					return $cdata;
				}
				global $wpdb;
				$cid = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'clients' ) . ' WHERE studio_id=%d AND phone=%s', $this->sid(), $cdata['phone'] ) );
				if ( ! $cid ) {
					$cdata['whatsapp']   = $cdata['whatsapp'] ? $cdata['whatsapp'] : $cdata['phone'];
					$cdata['created_by'] = get_current_user_id();
					$cid                 = Db::insert( 'clients', $cdata );
				}
			} else {
				Db::rollback();
				return new \WP_Error( 'validation_failed', 'Choose a client for this booking.', array( 'status' => 422, 'errors' => array( 'client_id' => 'Client is required.' ) ) );
			}
			if ( is_wp_error( $cid ) ) {
				Db::rollback();
				return $cid;
			}
			$data['client_id'] = (int) $cid;
		}
		if ( ! $data['confirmed_on'] ) {
			$data['confirmed_on'] = Db::today();
		}
		$data['number']     = Db::next_number( 'bookings', Tenant::setting( 'booking_prefix' ) ? Tenant::setting( 'booking_prefix' ) : 'BK' );
		$data['created_by'] = get_current_user_id();
		$id                 = Db::insert( 'bookings', $data );
		if ( is_wp_error( $id ) ) {
			Db::rollback();
			return $id;
		}
		$this->save_events( $id, $events );
		$this->save_installments( $id, $inst );
		self::save_services( $id, $services );
		if ( $deliverables ) {
			$dr = Deliverables::save_list( $id, $deliverables, $events );
			if ( is_wp_error( $dr ) ) {
				Db::rollback();
				return $dr;
			}
		}
		$refs = array( 'booking_id' => $id, 'client_id' => $data['client_id'], 'lead_id' => $data['lead_id'] );
		Db::log( 'booking', 'Booking ' . $data['number'] . ' confirmed for ' . Quotations::money( $data['total_amount'] ), $refs );
		if ( $lead && 'booked' !== $lead->stage ) {
			Leads::apply_update( $lead, array( 'stage' => 'booked', 'client_id' => $data['client_id'] ) );
		}
		if ( $data['quotation_id'] ) {
			$q = Db::find( 'quotations', $data['quotation_id'] );
			if ( $q && 'accepted' !== $q->status ) {
				Db::update( 'quotations', $q->id, array( 'status' => 'accepted', 'responded_at' => Db::now() ) );
			}
			if ( $q && ! $q->client_id ) {
				Db::update( 'quotations', $q->id, array( 'client_id' => $data['client_id'] ) );
			}
		}
		if ( $advance ) {
			global $wpdb;
			$first = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'installments' ) . ' WHERE studio_id=%d AND booking_id=%d ORDER BY sort, id LIMIT 1', $this->sid(), $id ) );
			$advance['installment_id'] = $first ? (int) $first : null;
			$r = Payments::record( $id, $advance );
			if ( is_wp_error( $r ) ) {
				Db::rollback();
				return $r;
			}
		}
		Tasks::auto_for_booking( $id, $events );
		if ( $services ) {
			Db::log( 'team', 'Services & team: ' . self::services_summary( $id ), array( 'booking_id' => $id ) );
		}
		// Every confirmed booking gets its private client portal straight away.
		$pin = null;
		if ( 'cancelled' !== $data['status'] ) {
			$portal = \PCRM\Portals::ensure( $id );
			if ( $portal && Tenant::can( 'portal.manage' ) ) {
				$pin = \PCRM\Portals::set_pin( $portal, null );
			}
		}
		Db::commit();
		$out = self::load( $id );
		if ( $pin && ! is_wp_error( $pin ) ) {
			// Shown once: only a hash is stored.
			$out->portal_pin_once = $pin;
		}
		return $this->ok( $out, 201 );
	}

	public function update( $req ) {
		$id       = (int) $req['id'];
		$existing = Db::find( 'bookings', $id );
		if ( ! $existing ) {
			return $this->not_found( 'Booking' );
		}
		$data = $this->validate( $req, $this->schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$b      = $this->body( $req );
		$total  = isset( $data['total_amount'] ) ? $data['total_amount'] : (float) $existing->total_amount;
		$events   = null;
		$inst     = null;
		$services = null;
		if ( isset( $b['services'] ) ) {
			$services = $this->parse_services( $b );
			if ( is_wp_error( $services ) ) {
				return $services;
			}
		}
		$deliverables = null;
		if ( isset( $b['deliverables'] ) && is_array( $b['deliverables'] ) && Tenant::can( 'deliverables.edit' ) ) {
			$deliverables = Deliverables::parse_list( $b['deliverables'] );
			if ( is_wp_error( $deliverables ) ) {
				return $deliverables;
			}
		}
		if ( null !== $services && ! isset( $b['events'] ) && empty( $b['force'] ) ) {
			global $wpdb;
			$saved = array_map( 'get_object_vars', $wpdb->get_results( $wpdb->prepare( 'SELECT id, event_date, start_time, end_time FROM ' . Db::t( 'booking_events' ) . ' WHERE studio_id=%d AND booking_id=%d', $this->sid(), $id ) ) );
			$conf  = self::find_conflicts( $saved, $id, $services );
			if ( $conf ) {
				return new \WP_Error( 'conflict', 'Some team members are already booked on these dates.', array( 'status' => 409, 'conflicts' => $conf ) );
			}
		}
		if ( isset( $b['events'] ) || isset( $b['installments'] ) ) {
			if ( ! isset( $b['events'] ) ) {
				$cur          = self::load( $id );
				$b['events']  = array_map(
					function ( $e ) {
						$e       = (array) $e;
						$e['team'] = array_map( 'get_object_vars', $e['team'] );
						return $e;
					},
					$cur->events
				);
			}
			$nested = $this->parse_nested( $b, $total );
			if ( is_wp_error( $nested ) ) {
				return $nested;
			}
			list( $events, $inst ) = $nested;
			if ( ! isset( $b['installments'] ) ) {
				$inst = null;
			}
			if ( empty( $b['force'] ) ) {
				$conf = self::find_conflicts( $events, $id, null !== $services ? $services : array_map( array( __CLASS__, 'service_to_array' ), self::services_for( $id ) ) );
				if ( $conf ) {
					return new \WP_Error( 'conflict', 'Some team members are already booked on these dates.', array( 'status' => 409, 'conflicts' => $conf ) );
				}
			}
		}
		if ( ! empty( $b['force'] ) && ! self::can_override() && ( null !== $events || null !== $services ) ) {
			global $wpdb;
			$check_events = null !== $events ? $events : array_map( 'get_object_vars', $wpdb->get_results( $wpdb->prepare( 'SELECT id, event_date, start_time, end_time FROM ' . Db::t( 'booking_events' ) . ' WHERE studio_id=%d AND booking_id=%d', $this->sid(), $id ) ) );
			if ( self::find_conflicts( $check_events, $id, null !== $services ? $services : array() ) ) {
				return new \WP_Error( 'forbidden', 'Only an admin or production manager can override a double booking.', array( 'status' => 403 ) );
			}
		}
		Db::begin();
		Db::update( 'bookings', $id, $data );
		if ( null !== $events ) {
			$this->save_events( $id, $events );
		}
		if ( null !== $inst ) {
			$this->save_installments( $id, $inst );
		}
		if ( null !== $services ) {
			$before = self::services_summary( $id );
			self::save_services( $id, $services );
			$after = self::services_summary( $id );
			if ( $before !== $after ) {
				Db::log( 'team', 'Services & team updated: ' . ( $after ? $after : 'none' ), array( 'booking_id' => $id ) );
			}
		}
		if ( null !== $deliverables ) {
			$dr = Deliverables::save_list( $id, $deliverables );
			if ( is_wp_error( $dr ) ) {
				Db::rollback();
				return $dr;
			}
		}
		if ( isset( $data['status'] ) && 'cancelled' !== $data['status'] ) {
			\PCRM\Portals::ensure( $id );
		}
		if ( isset( $data['status'] ) && $data['status'] !== $existing->status ) {
			Db::log( 'booking', 'Project status changed to ' . Config::booking_statuses()[ $data['status'] ], array( 'booking_id' => $id, 'client_id' => (int) $existing->client_id ) );
		} else {
			Db::log( 'booking', 'Booking details updated', array( 'booking_id' => $id ) );
		}
		Db::commit();
		return $this->ok( self::load( $id ) );
	}

	/**
	 * Replace a booking's services and their team (used from the booking page).
	 * Checks availability against the booking's saved events; force needs admin or bookings.assign.
	 */
	public function update_services( $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		if ( ! Db::find( 'bookings', $id ) ) {
			return $this->not_found( 'Booking' );
		}
		$b        = $this->body( $req );
		$services = $this->parse_services( array( 'services' => isset( $b['services'] ) ? $b['services'] : array() ) );
		if ( is_wp_error( $services ) ) {
			return $services;
		}
		$saved = array_map( 'get_object_vars', $wpdb->get_results( $wpdb->prepare( 'SELECT id, event_date, start_time, end_time FROM ' . Db::t( 'booking_events' ) . ' WHERE studio_id=%d AND booking_id=%d', $this->sid(), $id ) ) );
		$conf  = self::find_conflicts( $saved, $id, $services );
		if ( $conf && ( empty( $b['force'] ) || ! self::can_override() ) ) {
			if ( ! empty( $b['force'] ) ) {
				return new \WP_Error( 'forbidden', 'Only an admin or production manager can override a double booking.', array( 'status' => 403 ) );
			}
			return new \WP_Error( 'conflict', 'Some team members are already booked on these dates.', array( 'status' => 409, 'conflicts' => $conf ) );
		}
		Db::begin();
		$before = self::services_summary( $id );
		self::save_services( $id, $services );
		$after = self::services_summary( $id );
		if ( $before !== $after ) {
			Db::log( 'team', 'Services & team updated: ' . ( $after ? $after : 'none' ) . ( $conf ? ' (double booking overridden)' : '' ), array( 'booking_id' => $id ) );
		}
		Db::commit();
		return $this->ok( self::load( $id ) );
	}

	/** Admin (or production manager, who manages crew) may override availability warnings. */
	public static function can_override() {
		return \PCRM\Permissions::is_admin( Tenant::member() ) || Tenant::can( 'bookings.assign' );
	}

	public static function service_to_array( $s ) {
		return array( 'on_site' => $s->on_site, 'team' => wp_list_pluck( $s->team, 'member_id' ) );
	}

	public function set_status( $req ) {
		$id = (int) $req['id'];
		$bk = Db::find( 'bookings', $id );
		if ( ! $bk ) {
			return $this->not_found( 'Booking' );
		}
		$b      = $this->body( $req );
		$status = isset( $b['status'] ) ? (string) $b['status'] : '';
		if ( ! array_key_exists( $status, Config::booking_statuses() ) ) {
			return new \WP_Error( 'validation_failed', 'Invalid status.', array( 'status' => 422 ) );
		}
		if ( $status !== $bk->status ) {
			Db::update( 'bookings', $id, array( 'status' => $status ) );
			Db::log( 'booking', 'Project status changed to ' . Config::booking_statuses()[ $status ], array( 'booking_id' => $id, 'client_id' => (int) $bk->client_id ), array( 'from' => $bk->status, 'to' => $status ) );
			if ( 'cancelled' !== $status ) {
				\PCRM\Portals::ensure( $id );
			}
		}
		return $this->ok( array( 'ok' => true, 'status' => $status ) );
	}

	public function add_note( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'bookings', $id ) ) {
			return $this->not_found( 'Booking' );
		}
		$data = $this->validate( $req, array( 'message' => array( 'type' => 'text', 'required' => true, 'label' => 'Note' ) ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return $this->ok( array( 'id' => Db::log( 'note', $data['message'], array( 'booking_id' => $id ) ) ), 201 );
	}

	public function assign_team( $req ) {
		global $wpdb;
		$eid = (int) $req['id'];
		$ev  = Db::find( 'booking_events', $eid );
		if ( ! $ev ) {
			return $this->not_found( 'Event' );
		}
		$b    = $this->body( $req );
		$team = array();
		foreach ( ( isset( $b['team'] ) && is_array( $b['team'] ) ? $b['team'] : array() ) as $t ) {
			$mid = isset( $t['member_id'] ) ? (int) $t['member_id'] : 0;
			if ( ! $mid || ! Db::exists( 'members', $mid ) ) {
				return new \WP_Error( 'validation_failed', 'Team member not found.', array( 'status' => 422 ) );
			}
			$team[ $mid ] = array( 'member_id' => $mid, 'role' => isset( $t['role'] ) && array_key_exists( $t['role'], Config::event_roles() ) ? $t['role'] : 'photographer' );
		}
		$team = array_values( $team );
		if ( empty( $b['force'] ) ) {
			$conf = self::find_conflicts( array( array( 'event_date' => $ev->event_date, 'start_time' => $ev->start_time, 'end_time' => $ev->end_time, 'team' => $team ) ), (int) $ev->booking_id );
			if ( $conf ) {
				return new \WP_Error( 'conflict', 'Some team members are already booked at this time.', array( 'status' => 409, 'conflicts' => $conf ) );
			}
		}
		$this->save_team( $eid, $team );
		$names = $team ? $wpdb->get_col( 'SELECT name FROM ' . Db::t( 'members' ) . ' WHERE id IN (' . $this->ids_in( wp_list_pluck( $team, 'member_id' ) ) . ')' ) : array();
		Db::log( 'team', 'Team for ' . $ev->name . ' (' . \PCRM\Format::date( $ev->event_date ) . '): ' . ( $names ? implode( ', ', $names ) : 'none' ), array( 'booking_id' => (int) $ev->booking_id ) );
		return $this->ok( self::load( (int) $ev->booking_id ) );
	}

	public function destroy( $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		$bk = Db::find( 'bookings', $id );
		if ( ! $bk ) {
			return $this->not_found( 'Booking' );
		}
		$sid = $this->sid();
		Db::begin();
		$eids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'booking_events' ) . ' WHERE booking_id=%d AND studio_id=%d', $id, $sid ) );
		if ( $eids ) {
			$wpdb->query( 'DELETE FROM ' . Db::t( 'event_assignments' ) . ' WHERE studio_id=' . (int) $sid . ' AND booking_event_id IN (' . $this->ids_in( $eids ) . ')' );
		}
		// Keep the payment audit trail: log each payment that goes with the booking.
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . ' WHERE studio_id=%d AND booking_id=%d', $sid, $id ) ) as $pay ) {
			Payments::audit( 'deleted', Db::cast( $pay ), 'Payment ' . $pay->receipt_no . ' of ₹' . \PCRM\Format::inr( $pay->amount ) . ' deleted with booking ' . $bk->number );
		}
		foreach ( array( 'booking_events', 'installments', 'payments', 'activities', 'service_team_assignments', 'booking_services', 'booking_deliverables', 'client_portals' ) as $t ) {
			Db::delete_where( $t, array( 'booking_id' => $id ) );
		}
		$wpdb->update( Db::t( 'payment_links' ), array( 'payment_id' => null ), array( 'booking_id' => $id, 'studio_id' => $sid ) );
		$wpdb->update( Db::t( 'expenses' ), array( 'booking_id' => null ), array( 'booking_id' => $id, 'studio_id' => $sid ) );
		$wpdb->update( Db::t( 'tasks' ), array( 'booking_id' => null ), array( 'booking_id' => $id, 'studio_id' => $sid ) );
		Documents::delete_for( array( 'booking_id' => $id ) );
		Db::delete( 'bookings', $id );
		if ( $bk->lead_id ) {
			$lead = Db::find( 'leads', $bk->lead_id );
			if ( $lead && 'booked' === $lead->stage ) {
				Leads::apply_update( $lead, array( 'stage' => 'negotiation' ) );
			}
		}
		Db::commit();
		return $this->ok( array( 'deleted' => true ) );
	}
}
