<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

class Dashboard extends Base {

	public function register() {
		$this->route( '/dashboard', 'GET', array( $this, 'index' ), 'dashboard.view' );
		$this->route( '/activities', 'GET', array( $this, 'activities' ), 'leads.view' );
	}

	public static function month_keys( $n = 12 ) {
		$keys = array();
		$ts   = strtotime( Db::date( 'Y-m-01' ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$keys[] = date( 'Y-m', strtotime( "-$i months", $ts ) );
		}
		return $keys;
	}

	public function index( $req ) {
		global $wpdb;
		$sid   = $this->sid();
		$today = Db::today();
		$now   = Db::now();
		$l     = Db::t( 'leads' );
		$out   = array();
		$leads = Tenant::can( 'leads.view' );
		$money = Tenant::can( 'payments.view' );

		if ( $leads ) {
			$by = $wpdb->get_results( $wpdb->prepare( "SELECT stage, COUNT(*) n FROM $l WHERE studio_id=%d GROUP BY stage", $sid ), OBJECT_K );
			$st = array();
			foreach ( Config::stages() as $k => $v ) {
				$st[ $k ] = isset( $by[ $k ] ) ? (int) $by[ $k ]->n : 0;
			}
			$out['stages']            = $st;
			$out['total_leads']       = array_sum( $st );
			$out['new_this_month']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE studio_id=%d AND created_at >= %s", $sid, Db::date( 'Y-m-01 00:00:00' ) ) );
			$out['followups_pending'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE studio_id=%d AND next_followup_at IS NOT NULL AND next_followup_at <= %s AND stage NOT IN ('booked','lost')", $sid, $today . ' 23:59:59' ) );
			$out['followups_today']   = Db::cast_all( $wpdb->get_results( $wpdb->prepare( "SELECT id, name, phone, whatsapp, stage, event_type, event_date, next_followup_at FROM $l WHERE studio_id=%d AND next_followup_at <= %s AND stage NOT IN ('booked','lost') ORDER BY next_followup_at ASC LIMIT 12", $sid, $today . ' 23:59:59' ) ) );
			$conv                     = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at,'%%Y-%%m') m, COUNT(*) total, SUM(stage='booked') booked, SUM(stage='lost') lost FROM $l WHERE studio_id=%d AND created_at >= %s GROUP BY m", $sid, date( 'Y-m-01', strtotime( '-11 months', strtotime( Db::date( 'Y-m-01' ) ) ) ) ), OBJECT_K );
			$out['conversion_chart']  = array();
			foreach ( self::month_keys() as $k ) {
				$r                          = isset( $conv[ $k ] ) ? $conv[ $k ] : null;
				$out['conversion_chart'][] = array(
					'month'  => $k,
					'leads'  => $r ? (int) $r->total : 0,
					'booked' => $r ? (int) $r->booked : 0,
					'lost'   => $r ? (int) $r->lost : 0,
				);
			}
			$src = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(source,'other') source, COUNT(*) n, SUM(stage='booked') booked FROM $l WHERE studio_id=%d GROUP BY COALESCE(source,'other') ORDER BY n DESC", $sid ) );
			$out['source_chart'] = array_map(
				function ( $r ) {
					return array( 'source' => $r->source, 'leads' => (int) $r->n, 'booked' => (int) $r->booked );
				},
				$src
			);
		}

		if ( $money ) {
			$p    = Db::t( 'payments' );
			$rev  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(paid_on,'%%Y-%%m') m, SUM(amount) s FROM $p WHERE studio_id=%d AND status='received' AND paid_on >= %s GROUP BY m", $sid, date( 'Y-m-01', strtotime( '-11 months', strtotime( Db::date( 'Y-m-01' ) ) ) ) ), OBJECT_K );
			$bkd  = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE_FORMAT(confirmed_on,\'%%Y-%%m\') m, SUM(total_amount) s, COUNT(*) n FROM ' . Db::t( 'bookings' ) . " WHERE studio_id=%d AND status<>'cancelled' AND confirmed_on >= %s GROUP BY m", $sid, date( 'Y-m-01', strtotime( '-11 months', strtotime( Db::date( 'Y-m-01' ) ) ) ) ), OBJECT_K );
			$out['revenue_chart'] = array();
			foreach ( self::month_keys() as $k ) {
				$out['revenue_chart'][] = array(
					'month'     => $k,
					'collected' => isset( $rev[ $k ] ) ? (float) $rev[ $k ]->s : 0,
					'booked'    => isset( $bkd[ $k ] ) ? (float) $bkd[ $k ]->s : 0,
				);
			}
			$out['total_revenue'] = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $p WHERE studio_id=%d AND status='received'", $sid ) );
			$out['revenue_month'] = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $p WHERE studio_id=%d AND status='received' AND paid_on >= %s", $sid, Db::date( 'Y-m-01' ) ) );
			$pending              = 0;
			$overdue              = 0;
			foreach ( Payments::all_summaries() as $b ) {
				$pending += $b->pending;
				if ( 'overdue' === $b->payment_status ) {
					$overdue++;
				}
			}
			$out['pending_payments'] = $pending;
			$out['overdue_bookings'] = $overdue;
		}

		if ( Tenant::can( 'calendar.view' ) ) {
			$events                = Calendar::events( $today, date( 'Y-m-d', strtotime( '+60 days', Db::ts() ) ) );
			$out['upcoming_count'] = count( array_filter( $events, function ( $e ) use ( $today ) {
				return $e->event_date <= date( 'Y-m-d', strtotime( '+30 days', strtotime( $today ) ) );
			} ) );
			$out['upcoming_events'] = array_slice( $events, 0, 40 );
		}

		if ( Tenant::can( 'tasks.view' ) ) {
			$scope = ( Tenant::can( 'tasks.view_all' ) || Tenant::can( 'leads.view_all' ) ) ? '' : $wpdb->prepare( ' AND t.assigned_to=%d', $this->member_id() );
			$out['tasks_today'] = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT t.id, t.title, t.priority, t.status, t.due_at, t.category, m.name AS assigned_name FROM ' . Db::t( 'tasks' ) . ' t LEFT JOIN ' . Db::t( 'members' ) . " m ON m.id=t.assigned_to WHERE t.studio_id=%d AND t.status<>'done' AND t.due_at <= %s$scope ORDER BY t.due_at ASC LIMIT 12", $sid, $today . ' 23:59:59' ) ) );
		}

		if ( $leads ) {
			$out['activities'] = $this->recent( 15 );
		}
		return $this->ok( $out );
	}

	private function recent( $limit, $offset = 0, $type = null ) {
		global $wpdb;
		$t    = $type ? $wpdb->prepare( ' AND a.type=%s', $type ) : '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.id, a.type, a.message, a.lead_id, a.client_id, a.booking_id, a.created_at, u.display_name AS user_name, l.name AS lead_name, c.name AS client_name, b.title AS booking_title FROM ' . Db::t( 'activities' ) . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID=a.user_id LEFT JOIN ' . Db::t( 'leads' ) . ' l ON l.id=a.lead_id LEFT JOIN ' . Db::t( 'clients' ) . ' c ON c.id=a.client_id LEFT JOIN ' . Db::t( 'bookings' ) . " b ON b.id=a.booking_id WHERE a.studio_id=%d$t ORDER BY a.created_at DESC, a.id DESC LIMIT %d OFFSET %d", $this->sid(), $limit, $offset ) );
		return Db::cast_all( $rows );
	}

	public function activities( $req ) {
		list( $page, $per, $off ) = $this->paginate( $req );
		$type = $req->get_param( 'type' ) ? sanitize_key( $req->get_param( 'type' ) ) : null;
		return $this->ok( array( 'items' => $this->recent( $per, $off, $type ), 'page' => $page ) );
	}
}
