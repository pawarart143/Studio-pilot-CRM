<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Plans;
use PCRM\Tenant;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

class Reports extends Base {

	public function register() {
		$this->route( '/reports', 'GET', array( $this, 'index' ), 'reports.view' );
	}

	public function index( $req ) {
		global $wpdb;
		$limit = Plans::check( Tenant::studio(), 'reports' );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$sid  = $this->sid();
		$from = Validator::date( (string) $req->get_param( 'from' ) );
		$to   = Validator::date( (string) $req->get_param( 'to' ) );
		$from = $from ? $from : date( 'Y-m-01', strtotime( '-11 months', strtotime( Db::date( 'Y-m-01' ) ) ) );
		$to   = $to ? $to : Db::today();
		if ( $to < $from ) {
			return new \WP_Error( 'validation_failed', 'End date must be after start date.', array( 'status' => 422 ) );
		}
		$f  = $from . ' 00:00:00';
		$t  = $to . ' 23:59:59';
		$L  = Db::t( 'leads' );
		$B  = Db::t( 'bookings' );
		$P  = Db::t( 'payments' );
		$X  = Db::t( 'expenses' );
		$own = '';
		if ( ! Tenant::can( 'leads.delete' ) && 'sales' === Tenant::member()->role ) {
			$own = $wpdb->prepare( ' AND l.assigned_to=%d', $this->member_id() );
		}

		$lead_tot = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) total, SUM(stage='booked') booked, SUM(stage='lost') lost, SUM(stage NOT IN ('booked','lost')) open FROM $L l WHERE l.studio_id=%d AND l.created_at BETWEEN %s AND %s$own", $sid, $f, $t ) );
		$bk_tot   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) v FROM $B WHERE studio_id=%d AND status<>'cancelled' AND confirmed_on BETWEEN %s AND %s", $sid, $from, $to ) );
		$coll     = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $P WHERE studio_id=%d AND status='received' AND paid_on BETWEEN %s AND %s", $sid, $from, $to ) );
		$exp      = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $X WHERE studio_id=%d AND spent_on BETWEEN %s AND %s", $sid, $from, $to ) );
		// Crew payouts are business expenses (by payment date). They are never client revenue.
		$CP       = Db::t( 'crew_expense_payments' );
		$crew     = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $CP WHERE studio_id=%d AND paid_on BETWEEN %s AND %s", $sid, $from, $to ) );
		$other    = $exp;
		$exp     += $crew;
		$pending  = 0;
		$pend_list = array();
		foreach ( Payments::all_summaries() as $b ) {
			if ( $b->pending > 0 ) {
				$pending    += $b->pending;
				$pend_list[] = array( 'id' => $b->id, 'number' => $b->number, 'title' => $b->title, 'client_name' => $b->client_name, 'total' => (float) $b->total_amount, 'paid' => $b->paid, 'pending' => $b->pending, 'status' => $b->payment_status, 'next_due' => $b->next_due );
			}
		}
		usort( $pend_list, function ( $a, $b ) {
			return $b['pending'] <=> $a['pending'];
		} );
		$total = (int) $lead_tot->total;
		$summary = array(
			'leads'        => $total,
			'booked'       => (int) $lead_tot->booked,
			'lost'         => (int) $lead_tot->lost,
			'open'         => (int) $lead_tot->open,
			'conversion'   => $total ? round( $lead_tot->booked * 100 / $total, 1 ) : 0,
			'bookings'     => (int) $bk_tot->n,
			'booked_value' => (float) $bk_tot->v,
			'avg_booking'  => $bk_tot->n ? round( $bk_tot->v / $bk_tot->n ) : 0,
			'collected'    => $coll,
			'expenses'     => $exp,
			'other_expenses' => $other,
			'crew_expenses' => $crew,
			'profit'       => $coll - $exp,
			'pending'      => $pending,
		);

		// Monthly series.
		$months = array();
		$ts     = strtotime( date( 'Y-m-01', strtotime( $from ) ) );
		$end    = strtotime( $to );
		while ( $ts <= $end && count( $months ) < 60 ) {
			$months[ date( 'Y-m', $ts ) ] = array( 'month' => date( 'Y-m', $ts ), 'leads' => 0, 'booked' => 0, 'lost' => 0, 'bookings' => 0, 'booked_value' => 0, 'collected' => 0, 'expenses' => 0, 'crew_expenses' => 0 );
			$ts                           = strtotime( '+1 month', $ts );
		}
		$fill = function ( $rows, $map ) use ( &$months ) {
			foreach ( $rows as $r ) {
				if ( isset( $months[ $r->m ] ) ) {
					foreach ( $map as $col => $key ) {
						$months[ $r->m ][ $key ] = (float) $r->$col;
					}
				}
			}
		};
		$fill( $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at,'%%Y-%%m') m, COUNT(*) n, SUM(stage='booked') b, SUM(stage='lost') lo FROM $L l WHERE l.studio_id=%d AND l.created_at BETWEEN %s AND %s$own GROUP BY m", $sid, $f, $t ) ), array( 'n' => 'leads', 'b' => 'booked', 'lo' => 'lost' ) );
		$fill( $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(confirmed_on,'%%Y-%%m') m, COUNT(*) n, SUM(total_amount) v FROM $B WHERE studio_id=%d AND status<>'cancelled' AND confirmed_on BETWEEN %s AND %s GROUP BY m", $sid, $from, $to ) ), array( 'n' => 'bookings', 'v' => 'booked_value' ) );
		$fill( $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(paid_on,'%%Y-%%m') m, SUM(amount) v FROM $P WHERE studio_id=%d AND status='received' AND paid_on BETWEEN %s AND %s GROUP BY m", $sid, $from, $to ) ), array( 'v' => 'collected' ) );
		$fill( $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(spent_on,'%%Y-%%m') m, SUM(amount) v FROM $X WHERE studio_id=%d AND spent_on BETWEEN %s AND %s GROUP BY m", $sid, $from, $to ) ), array( 'v' => 'expenses' ) );
		$fill( $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(paid_on,'%%Y-%%m') m, SUM(amount) v FROM $CP WHERE studio_id=%d AND paid_on BETWEEN %s AND %s GROUP BY m", $sid, $from, $to ) ), array( 'v' => 'crew_expenses' ) );
		foreach ( $months as &$mo ) {
			$mo['expenses'] += $mo['crew_expenses'];
		}
		unset( $mo );

		$rev_join = "LEFT JOIN (SELECT lead_id, SUM(total_amount) v FROM $B WHERE studio_id=" . (int) $sid . " AND status<>'cancelled' AND lead_id IS NOT NULL GROUP BY lead_id) bv ON bv.lead_id=l.id";

		$sources = Db::cast_all( $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(l.source,'other') AS source, COUNT(*) leads, SUM(l.stage='booked') booked, SUM(l.stage='lost') lost, COALESCE(SUM(bv.v),0) revenue FROM $L l $rev_join WHERE l.studio_id=%d AND l.created_at BETWEEN %s AND %s$own GROUP BY COALESCE(l.source,'other') ORDER BY leads DESC", $sid, $f, $t ) ) );
		$types   = Db::cast_all( $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(l.event_type,'other') AS event_type, COUNT(*) leads, SUM(l.stage='booked') booked, COALESCE(SUM(bv.v),0) revenue, COALESCE(AVG(l.budget),0) avg_budget FROM $L l $rev_join WHERE l.studio_id=%d AND l.created_at BETWEEN %s AND %s$own GROUP BY COALESCE(l.event_type,'other') ORDER BY leads DESC", $sid, $f, $t ) ) );
		$sales   = Db::cast_all( $wpdb->get_results( $wpdb->prepare( "SELECT l.assigned_to AS member_id, COALESCE(m.name,'Unassigned') AS name, COUNT(*) leads, SUM(l.stage='booked') booked, SUM(l.stage='lost') lost, COALESCE(SUM(bv.v),0) revenue, (SELECT COUNT(*) FROM " . Db::t( 'calls' ) . " c WHERE c.studio_id=l.studio_id AND c.member_id=l.assigned_to AND c.called_at BETWEEN %s AND %s) calls FROM $L l $rev_join LEFT JOIN " . Db::t( 'members' ) . " m ON m.id=l.assigned_to WHERE l.studio_id=%d AND l.created_at BETWEEN %s AND %s$own GROUP BY l.assigned_to, m.name ORDER BY booked DESC, leads DESC", $f, $t, $sid, $f, $t ) ) );
		$lost_r  = Db::cast_all( $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(l.lost_reason,''),'Not specified') reason, COUNT(*) n, COALESCE(SUM(l.budget),0) budget FROM $L l WHERE l.studio_id=%d AND l.stage='lost' AND l.created_at BETWEEN %s AND %s$own GROUP BY reason ORDER BY n DESC LIMIT 20", $sid, $f, $t ) ) );
		$lost_s  = Db::cast_all( $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(l.source,'other') source, COUNT(*) n FROM $L l WHERE l.studio_id=%d AND l.stage='lost' AND l.created_at BETWEEN %s AND %s$own GROUP BY source ORDER BY n DESC", $sid, $f, $t ) ) );
		foreach ( array_merge( $sources, $types, $sales ) as $r ) {
			$r->leads      = (int) $r->leads;
			$r->booked     = (int) $r->booked;
			$r->conversion = $r->leads ? round( $r->booked * 100 / $r->leads, 1 ) : 0;
		}

		// Year over year (all time).
		$years = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT YEAR(created_at) y, COUNT(*) n, SUM(stage='booked') b FROM $L WHERE studio_id=%d GROUP BY y ORDER BY y", $sid ) ) as $r ) {
			$years[ $r->y ] = array( 'year' => (int) $r->y, 'leads' => (int) $r->n, 'booked' => (int) $r->b, 'bookings' => 0, 'booked_value' => 0, 'collected' => 0 );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT YEAR(confirmed_on) y, COUNT(*) n, SUM(total_amount) v FROM $B WHERE studio_id=%d AND status<>'cancelled' AND confirmed_on IS NOT NULL GROUP BY y", $sid ) ) as $r ) {
			if ( ! isset( $years[ $r->y ] ) ) {
				$years[ $r->y ] = array( 'year' => (int) $r->y, 'leads' => 0, 'booked' => 0, 'bookings' => 0, 'booked_value' => 0, 'collected' => 0 );
			}
			$years[ $r->y ]['bookings']     = (int) $r->n;
			$years[ $r->y ]['booked_value'] = (float) $r->v;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT YEAR(paid_on) y, SUM(amount) v FROM $P WHERE studio_id=%d AND status='received' GROUP BY y", $sid ) ) as $r ) {
			if ( isset( $years[ $r->y ] ) ) {
				$years[ $r->y ]['collected'] = (float) $r->v;
			}
		}
		ksort( $years );
		$prev = null;
		foreach ( $years as &$y ) {
			$y['growth'] = $prev && $prev['collected'] > 0 ? round( ( $y['collected'] - $prev['collected'] ) * 100 / $prev['collected'], 1 ) : null;
			$prev        = $y;
		}
		unset( $y );

		return $this->ok(
			array(
				'from'         => $from,
				'to'           => $to,
				'summary'      => $summary,
				'monthly'      => array_values( $months ),
				'sources'      => $sources,
				'event_types'  => $types,
				'sales'        => $sales,
				'lost_reasons' => $lost_r,
				'lost_sources' => $lost_s,
				'yearly'       => array_values( $years ),
				'pending'      => array_slice( $pend_list, 0, 50 ),
			)
		);
	}
}
