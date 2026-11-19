<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

class Tasks extends Base {

	private function schema() {
		return array(
			'title'       => array( 'type' => 'string', 'required' => true, 'max' => 255 ),
			'description' => array( 'type' => 'text' ),
			'category'    => array( 'type' => 'enum', 'options' => Config::task_categories(), 'default' => 'other' ),
			'lead_id'     => array( 'type' => 'ref', 'table' => 'leads', 'label' => 'Lead' ),
			'booking_id'  => array( 'type' => 'ref', 'table' => 'bookings', 'label' => 'Booking' ),
			'assigned_to' => array( 'type' => 'ref', 'table' => 'members', 'label' => 'Assigned person' ),
			'priority'    => array( 'type' => 'enum', 'options' => Config::priorities(), 'default' => 'medium' ),
			'due_at'      => array( 'type' => 'datetime', 'label' => 'Deadline' ),
			'status'      => array( 'type' => 'enum', 'options' => Config::task_statuses(), 'default' => 'todo' ),
		);
	}

	public function register() {
		$this->route( '/tasks', 'GET', array( $this, 'index' ), 'tasks.view' );
		$this->route( '/tasks', 'POST', array( $this, 'create' ), 'tasks.edit' );
		$this->route( '/tasks/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'tasks.edit' );
		$this->route( '/tasks/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'tasks.edit' );
	}

	/**
	 * Members without tasks.view_all only see tasks assigned to or created by them.
	 */
	private function scope() {
		global $wpdb;
		if ( Tenant::can( 'tasks.view_all' ) || Tenant::can( 'leads.view_all' ) ) {
			return '';
		}
		return $wpdb->prepare( ' AND (t.assigned_to=%d OR t.created_by=%d)', $this->member_id(), get_current_user_id() );
	}

	private function can_touch( $task ) {
		if ( Tenant::can( 'tasks.view_all' ) || Tenant::can( 'leads.view_all' ) ) {
			return true;
		}
		return (int) $task->assigned_to === $this->member_id() || (int) $task->created_by === get_current_user_id();
	}

	public function index( $req ) {
		global $wpdb;
		$w   = array( $wpdb->prepare( 't.studio_id=%d', $this->sid() ) );
		$now = Db::now();
		$s   = $req->get_param( 'status' );
		if ( 'open' === $s ) {
			$w[] = "t.status<>'done'";
		} elseif ( $s && array_key_exists( $s, Config::task_statuses() ) ) {
			$w[] = $wpdb->prepare( 't.status=%s', $s );
		}
		$due = $req->get_param( 'due' );
		if ( 'today' === $due ) {
			$w[] = $wpdb->prepare( 'DATE(t.due_at)=%s', Db::today() );
		} elseif ( 'overdue' === $due ) {
			$w[] = $wpdb->prepare( "t.due_at < %s AND t.status<>'done'", $now );
		} elseif ( 'week' === $due ) {
			$w[] = $wpdb->prepare( 't.due_at BETWEEN %s AND %s', Db::today() . ' 00:00:00', date( 'Y-m-d 23:59:59', strtotime( '+7 days', Db::ts() ) ) );
		}
		if ( $req->get_param( 'mine' ) ) {
			$w[] = $wpdb->prepare( 't.assigned_to=%d', $this->member_id() );
		} elseif ( $req->get_param( 'assigned_to' ) ) {
			$w[] = $wpdb->prepare( 't.assigned_to=%d', $req->get_param( 'assigned_to' ) );
		}
		foreach ( array( 'category' => Config::task_categories(), 'priority' => Config::priorities() ) as $f => $opts ) {
			if ( $req->get_param( $f ) && array_key_exists( $req->get_param( $f ), $opts ) ) {
				$w[] = $wpdb->prepare( "t.$f=%s", $req->get_param( $f ) );
			}
		}
		foreach ( array( 'lead_id', 'booking_id' ) as $f ) {
			if ( $req->get_param( $f ) ) {
				$w[] = $wpdb->prepare( "t.$f=%d", $req->get_param( $f ) );
			}
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$w[] = $wpdb->prepare( 't.title LIKE %s', $this->like( $q ) );
		}
		$where = implode( ' AND ', $w ) . $this->scope();
		$rows  = $wpdb->get_results( 'SELECT t.*, m.name AS assigned_name, m.color AS assigned_color, l.name AS lead_name, b.title AS booking_title, b.number AS booking_number FROM ' . Db::t( 'tasks' ) . ' t LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id=t.assigned_to LEFT JOIN ' . Db::t( 'leads' ) . ' l ON l.id=t.lead_id LEFT JOIN ' . Db::t( 'bookings' ) . " b ON b.id=t.booking_id WHERE $where ORDER BY t.status='done', t.due_at IS NULL, t.due_at ASC, FIELD(t.priority,'hot','high','medium','low') LIMIT 500" );
		return $this->ok( Db::cast_all( $rows ) );
	}

	public function create( $req ) {
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! Tenant::can( 'tasks.view_all' ) && ! Tenant::can( 'leads.view_all' ) ) {
			$data['assigned_to'] = $this->member_id();
		}
		if ( 'done' === $data['status'] ) {
			$data['completed_at'] = Db::now();
		}
		$data['created_by'] = get_current_user_id();
		$id                 = Db::insert( 'tasks', $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( $data['lead_id'] || $data['booking_id'] ) {
			Db::log( 'task', 'Task added: ' . $data['title'], array( 'lead_id' => $data['lead_id'], 'booking_id' => $data['booking_id'] ) );
		}
		return $this->ok( Db::cast( Db::find( 'tasks', $id ) ), 201 );
	}

	public function update( $req ) {
		$t = Db::find( 'tasks', (int) $req['id'] );
		if ( ! $t || ! $this->can_touch( $t ) ) {
			return $this->not_found( 'Task' );
		}
		$data = $this->validate( $req, $this->schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['status'] ) ) {
			if ( 'done' === $data['status'] && 'done' !== $t->status ) {
				$data['completed_at'] = Db::now();
				if ( $t->lead_id || $t->booking_id ) {
					Db::log( 'task', 'Task completed: ' . $t->title, array( 'lead_id' => $t->lead_id, 'booking_id' => $t->booking_id ) );
				}
			} elseif ( 'done' !== $data['status'] ) {
				$data['completed_at'] = null;
			}
		}
		if ( ! Tenant::can( 'tasks.view_all' ) && ! Tenant::can( 'leads.view_all' ) ) {
			unset( $data['assigned_to'] );
		}
		Db::update( 'tasks', $t->id, $data );
		return $this->ok( Db::cast( Db::find( 'tasks', $t->id ) ) );
	}

	public function destroy( $req ) {
		$t = Db::find( 'tasks', (int) $req['id'] );
		if ( ! $t || ! $this->can_touch( $t ) ) {
			return $this->not_found( 'Task' );
		}
		Db::delete( 'tasks', $t->id );
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Standard production tasks created with every new booking.
	 */
	public static function auto_for_booking( $booking_id, array $events ) {
		$bk    = Db::find( 'bookings', $booking_id );
		$dates = array_filter( wp_list_pluck( $events, 'event_date' ) );
		sort( $dates );
		$first = $dates ? $dates[0] : null;
		$last  = $dates ? end( $dates ) : null;
		$mgr   = null;
		global $wpdb;
		$mgr = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'members' ) . " WHERE studio_id=%d AND role='production_manager' AND status='active' ORDER BY id LIMIT 1", Tenant::studio_id() ) );
		$me  = Tenant::member();
		$own = $mgr ? (int) $mgr : ( $me ? $me->id : null );
		$list = array();
		$no_team = array_filter(
			$events,
			function ( $e ) {
				return empty( $e['team'] );
			}
		);
		if ( $no_team ) {
			$list[] = array( 'Assign photography team', 'team', $first ? date( 'Y-m-d 18:00:00', strtotime( $first . ' -7 days' ) ) : null, 'high' );
		}
		if ( $first ) {
			$list[] = array( 'Confirm venue and timings with client', 'venue', date( 'Y-m-d 12:00:00', strtotime( $first . ' -3 days' ) ), 'medium' );
		}
		if ( $last ) {
			$list[] = array( 'Complete photo editing', 'editing', date( 'Y-m-d 18:00:00', strtotime( $last . ' +21 days' ) ), 'medium' );
			$list[] = array( 'Deliver final photos and videos', 'delivery', date( 'Y-m-d 18:00:00', strtotime( $last . ' +30 days' ) ), 'medium' );
		}
		$today = Db::now();
		foreach ( $list as $t ) {
			$due = $t[2] && $t[2] < $today ? date( 'Y-m-d 18:00:00', Db::ts() + DAY_IN_SECONDS ) : $t[2];
			Db::insert(
				'tasks',
				array(
					'title'       => $t[0] . ' – ' . $bk->title,
					'category'    => $t[1],
					'booking_id'  => $booking_id,
					'assigned_to' => $own,
					'priority'    => $t[3],
					'due_at'      => $due,
					'status'      => 'todo',
					'created_by'  => get_current_user_id(),
				)
			);
		}
	}
}
