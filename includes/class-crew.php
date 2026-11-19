<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Crew scheduling helpers. Crew members are a separate directory
 * (pcrm_crew_members) and never use the team members table.
 */
class Crew {

	/**
	 * Working window of a schedule as [start, end) Unix seconds (studio wall clock).
	 * Uses the reporting time when it is earlier than the start time. No times
	 * means the whole day; an end time before the start runs past midnight.
	 */
	public static function window( $date, $start = null, $reporting = null, $end = null ) {
		$day   = strtotime( $date . ' 00:00:00 UTC' );
		$secs  = function ( $t ) {
			if ( ! $t ) {
				return null;
			}
			$p = array_map( 'intval', explode( ':', $t ) );
			return $p[0] * 3600 + ( isset( $p[1] ) ? $p[1] * 60 : 0 );
		};
		$s = $secs( $start );
		$r = $secs( $reporting );
		$e = $secs( $end );
		$from = null !== $r && ( null === $s || $r < $s ) ? $r : $s;
		if ( null === $from ) {
			return array( $day, $day + DAY_IN_SECONDS );
		}
		if ( null === $e ) {
			return array( $day + $from, $day + DAY_IN_SECONDS );
		}
		$to = $e <= $from ? $e + DAY_IN_SECONDS : $e;
		return array( $day + $from, $day + $to );
	}

	/**
	 * Other assignments of these crew members whose working hours overlap the
	 * given window. Declined, reassigned and cancelled ones are ignored.
	 *
	 * @return array crew_member_id => list of clashes
	 */
	public static function conflicts( array $member_ids, $date, $start, $reporting, $end, $exclude_schedule = 0, $sid = null ) {
		global $wpdb;
		$member_ids = array_values( array_unique( array_filter( array_map( 'intval', $member_ids ) ) ) );
		if ( ! $member_ids || ! $date ) {
			return array();
		}
		$sid  = Db::sid( $sid );
		$win  = self::window( $date, $start, $reporting, $end );
		$from = gmdate( 'Y-m-d', strtotime( $date . ' UTC' ) - DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d', strtotime( $date . ' UTC' ) + DAY_IN_SECONDS );
		$skip = "'" . implode( "','", Config::crew_inactive_statuses() ) . "'";
		$ids  = implode( ',', $member_ids );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.id AS assignment_id, a.crew_member_id, a.role, a.service, a.status, s.id AS schedule_id, s.event_name, s.client_name, s.event_date, s.start_time, s.reporting_time, s.end_time, s.venue, s.city
				FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a JOIN ' . Db::t( 'crew_schedules' ) . " s ON s.id=a.schedule_id AND s.studio_id=a.studio_id
				WHERE a.studio_id=%d AND a.crew_member_id IN ($ids) AND a.status NOT IN ($skip) AND s.id<>%d AND s.event_date BETWEEN %s AND %s",
				$sid,
				(int) $exclude_schedule,
				$from,
				$to
			)
		);
		$out = array();
		foreach ( $rows as $r ) {
			$w = self::window( $r->event_date, $r->start_time, $r->reporting_time, $r->end_time );
			if ( $w[0] < $win[1] && $win[0] < $w[1] ) {
				$out[ (int) $r->crew_member_id ][] = array(
					'assignment_id' => (int) $r->assignment_id,
					'schedule_id'   => (int) $r->schedule_id,
					'event_name'    => $r->event_name,
					'client_name'   => $r->client_name,
					'event_date'    => $r->event_date,
					'start_time'    => $r->start_time,
					'reporting_time' => $r->reporting_time,
					'end_time'      => $r->end_time,
					'venue'         => $r->venue,
					'city'          => $r->city,
					'role'          => $r->role,
					'status'        => $r->status,
				);
			}
		}
		return $out;
	}

	/**
	 * Keeps the crew payment record of an assignment in step with it: created
	 * when it has a rate, rate updated, and removed when the assignment is
	 * dropped and nothing has been paid yet.
	 */
	public static function sync_expense( $assignment_id, $sid = null ) {
		global $wpdb;
		$sid = Db::sid( $sid );
		$a   = Db::find( 'crew_schedule_assignments', $assignment_id, $sid );
		$x   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'crew_expenses' ) . ' WHERE studio_id=%d AND assignment_id=%d', $sid, $assignment_id ) );
		$paid = $x ? (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'crew_expense_payments' ) . ' WHERE studio_id=%d AND crew_expense_id=%d', $sid, $x->id ) ) : 0;
		$dropped = ! $a || in_array( $a->status, Config::crew_inactive_statuses(), true );
		if ( $dropped || ( null === $a->rate || (float) $a->rate <= 0 ) ) {
			if ( $x && $paid <= 0 ) {
				Db::delete( 'crew_expenses', $x->id, $sid );
			}
			return;
		}
		if ( $x ) {
			Db::update( 'crew_expenses', $x->id, array( 'agreed_rate' => $a->rate, 'role' => $a->role, 'crew_member_id' => $a->crew_member_id, 'schedule_id' => $a->schedule_id ), $sid );
			return;
		}
		Db::insert(
			'crew_expenses',
			array(
				'crew_member_id' => (int) $a->crew_member_id,
				'schedule_id'    => (int) $a->schedule_id,
				'assignment_id'  => (int) $a->id,
				'role'           => $a->role,
				'agreed_rate'    => $a->rate,
				'created_by'     => get_current_user_id() ? get_current_user_id() : null,
			),
			$sid
		);
	}

	/**
	 * Adds paid totals and status to crew expense rows.
	 */
	public static function with_totals( array $rows, $sid = null ) {
		global $wpdb;
		if ( ! $rows ) {
			return $rows;
		}
		$sid  = Db::sid( $sid );
		$ids  = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		$sums = $wpdb->get_results( $wpdb->prepare( 'SELECT crew_expense_id, SUM(amount) paid, SUM(CASE WHEN payment_type=\'advance\' THEN amount ELSE 0 END) advance, MAX(paid_on) last_paid FROM ' . Db::t( 'crew_expense_payments' ) . " WHERE studio_id=%d AND crew_expense_id IN ($ids) GROUP BY crew_expense_id", $sid ), OBJECT_K );
		foreach ( $rows as $r ) {
			$s                 = isset( $sums[ $r->id ] ) ? $sums[ $r->id ] : null;
			$r->paid           = $s ? round( (float) $s->paid, 2 ) : 0.0;
			$r->advance_paid   = $s ? round( (float) $s->advance, 2 ) : 0.0;
			$r->payment_date   = $s ? $s->last_paid : null;
			$r->agreed_rate    = (float) $r->agreed_rate;
			$r->remaining      = round( max( 0, $r->agreed_rate - $r->paid ), 2 );
			$r->payment_status = $r->paid <= 0 ? 'pending' : ( $r->paid + 0.005 >= $r->agreed_rate ? 'paid' : 'partial' );
		}
		return $rows;
	}

	/** WhatsApp message for a crew member about one assignment. */
	public static function wa_text( $studio_name, $crew_name, $schedule, $assignment ) {
		$cats  = Config::crew_categories();
		$role  = isset( $cats[ $assignment->role ] ) ? $cats[ $assignment->role ] : $assignment->role;
		$lines = array( 'Hi ' . $crew_name . ',' );
		$lines[] = 'You are scheduled as ' . $role . ( $assignment->service ? ' (' . $assignment->service . ')' : '' ) . ' for ' . $schedule->event_name . ( $schedule->client_name ? ' – ' . $schedule->client_name : '' ) . '.';
		$lines[] = 'Date: ' . Format::date( $schedule->event_date );
		if ( $schedule->reporting_time ) {
			$lines[] = 'Reporting time: ' . Format::time( $schedule->reporting_time );
		}
		if ( $schedule->start_time ) {
			$lines[] = 'Event time: ' . Format::time( $schedule->start_time ) . ( $schedule->end_time ? ' – ' . Format::time( $schedule->end_time ) : '' );
		}
		$venue = trim( (string) $schedule->venue . ( $schedule->city ? ', ' . $schedule->city : '' ), ', ' );
		if ( $venue ) {
			$lines[] = 'Venue: ' . $venue;
		}
		if ( $schedule->instructions ) {
			$lines[] = 'Instructions: ' . $schedule->instructions;
		}
		$lines[] = 'Please reply to confirm.';
		$lines[] = '– ' . $studio_name;
		return implode( "\n", $lines );
	}
}
