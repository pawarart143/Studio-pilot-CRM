<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Dashboard_Widgets;
use PCRM\Db;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

class Dashboard extends Base {

	public function register() {
		$this->route( '/dashboard', 'GET', array( $this, 'index' ), 'dashboard.view' );
		$this->route( '/activities', 'GET', array( $this, 'activities' ), 'leads.view' );
		$this->route( '/dashboard/layout', 'GET', array( $this, 'get_layout' ), 'dashboard.view' );
		$this->route( '/dashboard/layout', 'PUT', array( $this, 'put_layout' ), 'dashboard.view' );
		$this->route( '/dashboard/layout', 'DELETE', array( $this, 'reset_layout' ), 'dashboard.view' );
		$this->route( '/dashboard/widgets/(?P<id>[a-z_]+)', 'GET', array( $this, 'widget' ), 'dashboard.view' );
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
			$out['conversion_chart']  = self::conversion_chart( 12 );
			$out['source_chart']      = self::source_chart( null, null );
		}

		if ( $money ) {
			$p                    = Db::t( 'payments' );
			$out['revenue_chart'] = self::revenue_chart( 12 );
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

	/* ---------- Shared chart builders (used by /dashboard and widget data) ---------- */

	private static function series_start( $n ) {
		return date( 'Y-m-01', strtotime( '-' . ( $n - 1 ) . ' months', strtotime( Db::date( 'Y-m-01' ) ) ) );
	}

	public static function conversion_chart( $n = 12 ) {
		global $wpdb;
		$l    = Db::t( 'leads' );
		$conv = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at,'%%Y-%%m') m, COUNT(*) total, SUM(stage='booked') booked, SUM(stage='lost') lost FROM $l WHERE studio_id=%d AND created_at >= %s GROUP BY m", Tenant::studio_id(), self::series_start( $n ) ), OBJECT_K );
		$out  = array();
		foreach ( self::month_keys( $n ) as $k ) {
			$r     = isset( $conv[ $k ] ) ? $conv[ $k ] : null;
			$out[] = array(
				'month'  => $k,
				'leads'  => $r ? (int) $r->total : 0,
				'booked' => $r ? (int) $r->booked : 0,
				'lost'   => $r ? (int) $r->lost : 0,
			);
		}
		return $out;
	}

	public static function source_chart( $from, $to ) {
		global $wpdb;
		$l     = Db::t( 'leads' );
		$where = $wpdb->prepare( 'studio_id=%d', Tenant::studio_id() );
		if ( $from ) {
			$where .= $wpdb->prepare( ' AND created_at BETWEEN %s AND %s', $from . ' 00:00:00', $to . ' 23:59:59' );
		}
		$src = $wpdb->get_results( "SELECT COALESCE(source,'other') source, COUNT(*) n, SUM(stage='booked') booked FROM $l WHERE $where GROUP BY COALESCE(source,'other') ORDER BY n DESC" );
		return array_map(
			function ( $r ) {
				return array( 'source' => $r->source, 'leads' => (int) $r->n, 'booked' => (int) $r->booked );
			},
			$src
		);
	}

	public static function revenue_chart( $n = 12 ) {
		global $wpdb;
		$sid   = Tenant::studio_id();
		$start = self::series_start( $n );
		$rev   = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE_FORMAT(paid_on,\'%%Y-%%m\') m, SUM(amount) s FROM ' . Db::t( 'payments' ) . " WHERE studio_id=%d AND status='received' AND paid_on >= %s GROUP BY m", $sid, $start ), OBJECT_K );
		$bkd   = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE_FORMAT(confirmed_on,\'%%Y-%%m\') m, SUM(total_amount) s, COUNT(*) n FROM ' . Db::t( 'bookings' ) . " WHERE studio_id=%d AND status<>'cancelled' AND confirmed_on >= %s GROUP BY m", $sid, $start ), OBJECT_K );
		$out   = array();
		foreach ( self::month_keys( $n ) as $k ) {
			$out[] = array(
				'month'     => $k,
				'collected' => isset( $rev[ $k ] ) ? (float) $rev[ $k ]->s : 0,
				'booked'    => isset( $bkd[ $k ] ) ? (float) $bkd[ $k ]->s : 0,
			);
		}
		return $out;
	}

	/** Payments received vs. expenses (studio expenses + crew payouts, as in Reports). */
	public static function cashflow_chart( $n = 12 ) {
		global $wpdb;
		$sid   = Tenant::studio_id();
		$start = self::series_start( $n );
		$rev   = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE_FORMAT(paid_on,\'%%Y-%%m\') m, SUM(amount) s FROM ' . Db::t( 'payments' ) . " WHERE studio_id=%d AND status='received' AND paid_on >= %s GROUP BY m", $sid, $start ), OBJECT_K );
		$exp   = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE_FORMAT(spent_on,\'%%Y-%%m\') m, SUM(amount) s FROM ' . Db::t( 'expenses' ) . ' WHERE studio_id=%d AND spent_on >= %s GROUP BY m', $sid, $start ), OBJECT_K );
		$crew  = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE_FORMAT(paid_on,\'%%Y-%%m\') m, SUM(amount) s FROM ' . Db::t( 'crew_expense_payments' ) . ' WHERE studio_id=%d AND paid_on >= %s GROUP BY m', $sid, $start ), OBJECT_K );
		$out   = array();
		foreach ( self::month_keys( $n ) as $k ) {
			$in    = isset( $rev[ $k ] ) ? (float) $rev[ $k ]->s : 0;
			$ex    = ( isset( $exp[ $k ] ) ? (float) $exp[ $k ]->s : 0 ) + ( isset( $crew[ $k ] ) ? (float) $crew[ $k ]->s : 0 );
			$out[] = array( 'month' => $k, 'collected' => $in, 'expenses' => $ex, 'net' => $in - $ex );
		}
		return $out;
	}

	private static function sum_between( $table, $col, $from, $to, $extra = '' ) {
		global $wpdb;
		$w = $wpdb->prepare( 'studio_id=%d', Tenant::studio_id() );
		if ( $from ) {
			$w .= $wpdb->prepare( " AND $col BETWEEN %s AND %s", $from, $to );
		}
		return (float) $wpdb->get_var( 'SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( $table ) . " WHERE $w$extra" );
	}

	private static function expenses_between( $from, $to ) {
		$other = self::sum_between( 'expenses', 'spent_on', $from, $to );
		$crew  = self::sum_between( 'crew_expense_payments', 'paid_on', $from, $to );
		return array( 'total' => $other + $crew, 'other' => $other, 'crew' => $crew );
	}

	private function task_scope( $alias = 't' ) {
		global $wpdb;
		return ( Tenant::can( 'tasks.view_all' ) || Tenant::can( 'leads.view_all' ) ) ? '' : $wpdb->prepare( " AND $alias.assigned_to=%d", $this->member_id() );
	}

	/* ---------- Layout ---------- */

	private function layout_response() {
		list( $items, $is_default ) = Dashboard_Widgets::layout();
		return $this->ok(
			array(
				'widgets'    => Dashboard_Widgets::public_catalogue(),
				'items'      => $items,
				'is_default' => $is_default,
			)
		);
	}

	public function get_layout( $req ) {
		return $this->layout_response();
	}

	public function put_layout( $req ) {
		$body  = $this->body( $req );
		$items = isset( $body['items'] ) ? $body['items'] : null;
		if ( ! is_array( $items ) || count( $items ) > 200 ) {
			return new \WP_Error( 'validation_failed', 'Send the dashboard layout as a list of widgets.', array( 'status' => 422 ) );
		}
		$clean   = Dashboard_Widgets::save( $items );
		$visible = count( array_filter( wp_list_pluck( $clean, 'visible' ) ) );
		if ( 0 === $visible ) {
			Dashboard_Widgets::reset();
			return new \WP_Error( 'validation_failed', 'Keep at least one widget on your dashboard.', array( 'status' => 422 ) );
		}
		return $this->layout_response();
	}

	public function reset_layout( $req ) {
		Dashboard_Widgets::reset();
		return $this->layout_response();
	}

	/* ---------- Widget data ---------- */

	public function widget( $req ) {
		global $wpdb;
		$id = (string) $req['id'];
		$w  = Dashboard_Widgets::find( $id );
		if ( ! $w ) {
			return $this->not_found( 'Widget' );
		}
		if ( ! Dashboard_Widgets::allowed( $w ) ) {
			return new \WP_Error( 'forbidden', 'You do not have permission to see this widget.', array( 'status' => 403 ) );
		}
		$range = (string) $req->get_param( 'range' );
		if ( ! in_array( $range, $w['ranges'], true ) ) {
			$range = $w['default_range'];
		}
		$sid   = $this->sid();
		$today = Db::today();
		$L     = Db::t( 'leads' );
		list( $from, $to ) = Dashboard_Widgets::period( $range );
		$data = null;

		switch ( $id ) {
			case 'stat_upcoming_events':
				$days = Dashboard_Widgets::days( $range );
				$data = array( 'count' => count( Calendar::events( $today, date( 'Y-m-d', strtotime( "+$days days", strtotime( $today ) ) ) ) ) );
				break;

			case 'list_upcoming_events':
				$days = Dashboard_Widgets::days( $range );
				$data = array( 'events' => array_slice( Calendar::events( $today, date( 'Y-m-d', strtotime( "+$days days", strtotime( $today ) ) ) ), 0, 40 ) );
				break;

			case 'chart_revenue':
				$data = array( 'chart' => self::revenue_chart( Dashboard_Widgets::months( $range ) ) );
				break;

			case 'chart_conversion':
				$data = array( 'chart' => self::conversion_chart( Dashboard_Widgets::months( $range ) ) );
				break;

			case 'chart_sources':
				$data = array( 'chart' => self::source_chart( $from, $to ) );
				break;

			case 'chart_cashflow':
				$data = array( 'chart' => self::cashflow_chart( Dashboard_Widgets::months( $range ) ) );
				break;

			case 'stat_conversion_rate':
				$w2 = $wpdb->prepare( 'studio_id=%d', $sid ) . ( $from ? $wpdb->prepare( ' AND created_at BETWEEN %s AND %s', $from . ' 00:00:00', $to . ' 23:59:59' ) : '' );
				$r  = $wpdb->get_row( "SELECT COUNT(*) total, COALESCE(SUM(stage='booked'),0) booked FROM $L WHERE $w2" );
				$t  = (int) $r->total;
				$data = array( 'leads' => $t, 'booked' => (int) $r->booked, 'rate' => $t ? round( $r->booked * 100 / $t, 1 ) : 0 );
				break;

			case 'chart_leads_by_stage':
				$w2  = $wpdb->prepare( 'studio_id=%d', $sid ) . ( $from ? $wpdb->prepare( ' AND created_at BETWEEN %s AND %s', $from . ' 00:00:00', $to . ' 23:59:59' ) : '' );
				$by  = $wpdb->get_results( "SELECT stage, COUNT(*) n FROM $L WHERE $w2 GROUP BY stage", OBJECT_K );
				$out = array();
				foreach ( Config::stages() as $k => $label ) {
					$out[] = array( 'stage' => $k, 'label' => $label, 'leads' => isset( $by[ $k ] ) ? (int) $by[ $k ]->n : 0 );
				}
				$data = array( 'chart' => $out );
				break;

			case 'stat_bookings_confirmed':
				$w2 = $wpdb->prepare( "studio_id=%d AND status<>'cancelled'", $sid ) . ( $from ? $wpdb->prepare( ' AND confirmed_on BETWEEN %s AND %s', $from, $to ) : '' );
				$r  = $wpdb->get_row( 'SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) v FROM ' . Db::t( 'bookings' ) . " WHERE $w2" );
				$data = array( 'count' => (int) $r->n );
				if ( Tenant::can( 'payments.view' ) ) {
					$data['value'] = (float) $r->v;
				}
				break;

			case 'stat_collected':
				$data = array( 'amount' => self::sum_between( 'payments', 'paid_on', $from, $to, " AND status='received'" ) );
				break;

			case 'stat_expenses':
				$data = self::expenses_between( $from, $to );
				break;

			case 'stat_profit':
				$in   = self::sum_between( 'payments', 'paid_on', $from, $to, " AND status='received'" );
				$ex   = self::expenses_between( $from, $to );
				$data = array( 'collected' => $in, 'expenses' => $ex['total'], 'net' => $in - $ex['total'] );
				break;

			case 'list_outstanding':
				$rows = array();
				foreach ( Payments::all_summaries() as $b ) {
					if ( $b->pending > 0 ) {
						$rows[] = array( 'id' => $b->id, 'number' => $b->number, 'title' => $b->title, 'client_name' => $b->client_name, 'total' => (float) $b->total_amount, 'paid' => (float) $b->paid, 'pending' => (float) $b->pending, 'status' => $b->payment_status, 'next_due' => $b->next_due );
					}
				}
				usort( $rows, function ( $a, $b ) {
					return $b['pending'] <=> $a['pending'];
				} );
				$data = array( 'total' => array_sum( wp_list_pluck( $rows, 'pending' ) ), 'count' => count( $rows ), 'items' => array_slice( $rows, 0, 8 ) );
				break;

			case 'stat_deliverables_open':
			case 'list_deliverables':
				$D     = Db::t( 'booking_deliverables' );
				$scope = Tenant::can( 'deliverables.view_all' ) ? '' : $wpdb->prepare( ' AND d.assigned_to=%d', $this->member_id() );
				$base  = $wpdb->prepare( "d.studio_id=%d AND d.status<>'delivered' AND b.status<>'cancelled'", $sid ) . $scope;
				$join  = ' JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=d.booking_id AND b.studio_id=d.studio_id';
				if ( 'stat_deliverables_open' === $id ) {
					$r    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(d.expected_date IS NOT NULL AND d.expected_date < %s),0) overdue FROM $D d$join WHERE $base", $today ) );
					$data = array( 'count' => (int) $r->n, 'overdue' => (int) $r->overdue );
				} else {
					$rows = $wpdb->get_results( "SELECT d.id, d.booking_id, d.name, d.status, d.progress, d.expected_date, b.number AS booking_number, c.name AS client_name, m.name AS assigned_name, m.color AS assigned_color FROM $D d$join JOIN " . Db::t( 'clients' ) . ' c ON c.id=b.client_id LEFT JOIN ' . Db::t( 'members' ) . " m ON m.id=d.assigned_to WHERE $base ORDER BY d.expected_date IS NULL, d.expected_date ASC, d.id ASC LIMIT 10" );
					$data = array( 'items' => Db::cast_all( $rows ) );
				}
				break;

			case 'stat_tasks_open':
				$r    = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) n, COALESCE(SUM(t.due_at IS NOT NULL AND t.due_at < %s),0) overdue FROM ' . Db::t( 'tasks' ) . " t WHERE t.studio_id=%d AND t.status<>'done'" . $this->task_scope(), $today . ' 00:00:00', $sid ) );
				$data = array( 'count' => (int) $r->n, 'overdue' => (int) $r->overdue );
				break;

			case 'chart_task_load':
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT COALESCE(m.name, %s) name, m.color, COUNT(*) open, COALESCE(SUM(t.due_at IS NOT NULL AND t.due_at < %s),0) overdue FROM ' . Db::t( 'tasks' ) . ' t LEFT JOIN ' . Db::t( 'members' ) . " m ON m.id=t.assigned_to WHERE t.studio_id=%d AND t.status<>'done' GROUP BY t.assigned_to, m.name, m.color ORDER BY open DESC LIMIT 12", 'Unassigned', $today . ' 00:00:00', $sid ) );
				$data = array(
					'chart' => array_map(
						function ( $r ) {
							return array( 'name' => $r->name, 'color' => $r->color, 'open' => (int) $r->open, 'overdue' => (int) $r->overdue );
						},
						$rows
					),
				);
				break;

			case 'list_crew_schedule':
				// Never includes rates: production managers can see crew but not crew money.
				$days = Dashboard_Widgets::days( $range );
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.id, a.role, a.status, s.id AS schedule_id, s.event_name, s.client_name, s.event_date, s.start_time, s.reporting_time, s.venue, s.city, cm.name AS crew_name, cm.phone AS crew_phone FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a JOIN ' . Db::t( 'crew_schedules' ) . ' s ON s.id=a.schedule_id AND s.studio_id=a.studio_id JOIN ' . Db::t( 'crew_members' ) . " cm ON cm.id=a.crew_member_id WHERE a.studio_id=%d AND s.event_date BETWEEN %s AND %s AND a.status NOT IN ('declined','reassigned','cancelled') ORDER BY s.event_date, COALESCE(s.reporting_time, s.start_time), s.id, cm.name LIMIT 40", $sid, $today, date( 'Y-m-d', strtotime( "+$days days", strtotime( $today ) ) ) ) );
				$data = array( 'items' => Db::cast_all( $rows ) );
				break;

			default:
				// Widgets that only read from GET /dashboard.
				return new \WP_Error( 'not_found', 'This widget loads with the dashboard.', array( 'status' => 404 ) );
		}

		return $this->ok( array( 'id' => $id, 'range' => $range, 'data' => $data ) );
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
