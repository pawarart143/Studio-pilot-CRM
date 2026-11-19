<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Google Calendar events a studio should have, straight from the
 * CRM tables. The CRM stays the source of truth: nothing here is stored
 * except a hash of what was last sent.
 *
 * Keys: booking:<booking_event id>, task:<id>, followup:<id>, payment:<installment id>.
 * Must run inside Tenant::act_as_studio() (Gcal::run_studio does that).
 */
class Gcal_Events {

	/** Records older than this many days are left alone (not created, not deleted). */
	const PAST_DAYS = 30;
	/** And nothing further out than this. */
	const FUTURE_DAYS = 730;

	public static function window() {
		$today = Db::today();
		return array(
			date( 'Y-m-d', strtotime( $today . ' -' . self::PAST_DAYS . ' days' ) ),
			date( 'Y-m-d', strtotime( $today . ' +' . self::FUTURE_DAYS . ' days' ) ),
		);
	}

	/** Everything the calendar should show, keyed by entity key. */
	public static function desired( $acc ) {
		$kinds = Gcal::kinds( $acc );
		list( $from, $to ) = self::window();
		$out = array();
		if ( in_array( 'bookings', $kinds, true ) ) {
			$out += self::bookings( $acc, $from, $to );
		}
		if ( in_array( 'tasks', $kinds, true ) ) {
			$out += self::tasks( $acc, $from, $to );
		}
		if ( in_array( 'followups', $kinds, true ) ) {
			$out += self::followups( $acc, $from, $to );
		}
		if ( in_array( 'payments', $kinds, true ) ) {
			$out += self::payments( $acc, $from, $to );
		}
		return $out;
	}

	/** The event for one record, or null when it should not be in the calendar. */
	public static function one( $acc, $key ) {
		list( $type, $id ) = array_pad( explode( ':', $key, 2 ), 2, 0 );
		$id                = (int) $id;
		$kinds             = Gcal::kinds( $acc );
		list( $from, $to ) = self::window();
		$map               = array( 'booking' => 'bookings', 'task' => 'tasks', 'followup' => 'followups', 'payment' => 'payments' );
		if ( ! $id || ! isset( $map[ $type ] ) || ! in_array( $map[ $type ], $kinds, true ) ) {
			return null;
		}
		$fn  = $map[ $type ];
		$all = self::$fn( $acc, $from, $to, $id );
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * True when the record still exists (and its kind is on) but its date is
	 * now older than the sync window, so its Google event should be kept.
	 */
	public static function outside_window( $acc, $key ) {
		global $wpdb;
		list( $type, $id ) = array_pad( explode( ':', $key, 2 ), 2, 0 );
		$id                = (int) $id;
		$sid               = (int) $acc->studio_id;
		$map               = array( 'booking' => 'bookings', 'task' => 'tasks', 'followup' => 'followups', 'payment' => 'payments' );
		if ( ! isset( $map[ $type ] ) || ! in_array( $map[ $type ], Gcal::kinds( $acc ), true ) ) {
			return false;
		}
		list( $from ) = self::window();
		$wide         = '2000-01-01';
		$fn           = $map[ $type ];
		$all          = self::$fn( $acc, $wide, $from, $id );
		return isset( $all[ $key ] ) && $from > self::event_day( $all[ $key ] );
	}

	private static function event_day( $ev ) {
		return isset( $ev['start']['date'] ) ? $ev['start']['date'] : substr( $ev['start']['dateTime'], 0, 10 );
	}

	/** Short description of a key for error lists. */
	public static function label( $key ) {
		global $wpdb;
		list( $type, $id ) = array_pad( explode( ':', $key, 2 ), 2, 0 );
		$id                = (int) $id;
		$sid               = Tenant::studio_id();
		switch ( $type ) {
			case 'booking':
				$r = $wpdb->get_row( $wpdb->prepare( 'SELECT e.name, e.event_date, b.number FROM ' . Db::t( 'booking_events' ) . ' e JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=e.booking_id WHERE e.id=%d AND e.studio_id=%d', $id, $sid ) );
				return $r ? $r->number . ' · ' . $r->name . ' (' . Format::date( $r->event_date ) . ')' : 'Booking event (deleted)';
			case 'task':
				$r = $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM ' . Db::t( 'tasks' ) . ' WHERE id=%d AND studio_id=%d', $id, $sid ) );
				return $r ? 'Task: ' . $r : 'Task (deleted)';
			case 'followup':
				$r = $wpdb->get_var( $wpdb->prepare( 'SELECT l.name FROM ' . Db::t( 'followups' ) . ' f JOIN ' . Db::t( 'leads' ) . ' l ON l.id=f.lead_id WHERE f.id=%d AND f.studio_id=%d', $id, $sid ) );
				return $r ? 'Follow-up: ' . $r : 'Follow-up (deleted)';
			case 'payment':
				$r = $wpdb->get_row( $wpdb->prepare( 'SELECT i.label, b.number FROM ' . Db::t( 'installments' ) . ' i JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=i.booking_id WHERE i.id=%d AND i.studio_id=%d', $id, $sid ) );
				return $r ? 'Payment: ' . $r->number . ' · ' . $r->label : 'Payment (deleted)';
		}
		return $key;
	}

	/* ------------------------------------------------------------------ */

	private static function tz() {
		return Db::tz()->getName();
	}

	private static function crm_url( $path ) {
		return home_url( '/crm/' . ltrim( $path, '/' ) );
	}

	private static function base( $acc, $kind, $key, $summary, $desc, $path ) {
		return array(
			'summary'            => mb_substr( $summary, 0, 250 ),
			'description'        => trim( $desc ),
			'source'             => array( 'title' => 'ShootPilot', 'url' => self::crm_url( $path ) ),
			'extendedProperties' => array(
				'private' => array(
					'shootpilot' => '1',
					'sp_studio'  => (string) (int) $acc->studio_id,
					'sp_key'     => $key,
				),
			),
			'colorId'            => array( 'booking' => '9', 'task' => '5', 'followup' => '6', 'payment' => '11' )[ $kind ],
			'transparency'       => 'booking' === $kind ? 'opaque' : 'transparent',
		);
	}

	private static function timed( $date, $time, $minutes ) {
		$start = new \DateTime( $date . ' ' . $time, Db::tz() );
		$end   = clone $start;
		$end->modify( '+' . (int) $minutes . ' minutes' );
		return array(
			'start' => array( 'dateTime' => $start->format( 'Y-m-d\TH:i:s' ), 'timeZone' => self::tz() ),
			'end'   => array( 'dateTime' => $end->format( 'Y-m-d\TH:i:s' ), 'timeZone' => self::tz() ),
		);
	}

	private static function all_day( $date ) {
		return array(
			'start' => array( 'date' => $date ),
			'end'   => array( 'date' => date( 'Y-m-d', strtotime( $date . ' +1 day' ) ) ),
		);
	}

	private static function reminders( array $minutes ) {
		$m = array();
		foreach ( array_unique( array_filter( $minutes, 'is_numeric' ) ) as $x ) {
			if ( $x >= 0 && $x <= 40320 ) {
				$m[] = array( 'method' => 'popup', 'minutes' => (int) $x );
			}
		}
		return array( 'useDefault' => false, 'overrides' => array_slice( $m, 0, 5 ) );
	}

	private static function lines( array $l ) {
		return implode( "\n", array_filter( $l, 'strlen' ) );
	}

	/* ---------- Bookings: one event per event date / venue ---------- */

	private static function bookings( $acc, $from, $to, $only = 0 ) {
		global $wpdb;
		$sid = (int) $acc->studio_id;
		if ( $only ) {
			$d = $wpdb->get_var( $wpdb->prepare( 'SELECT event_date FROM ' . Db::t( 'booking_events' ) . ' WHERE id=%d AND studio_id=%d', $only, $sid ) );
			if ( ! $d || $d < $from || $d > $to ) {
				return array();
			}
			$from = $to = $d;
		}
		$rows = Rest\Calendar::events( $from, $to );
		if ( ! $rows ) {
			return array();
		}
		if ( $only ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $only ) { return (int) $r->id === $only; } ) );
		}
		// Crew (freelancers) scheduled for these events.
		$crew = array();
		$ids  = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		if ( $ids ) {
			$inactive = "'" . implode( "','", array_map( 'esc_sql', Config::crew_inactive_statuses() ) ) . "'";
			$cr       = $wpdb->get_results( 'SELECT s.booking_event_id, cm.name, a.role FROM ' . Db::t( 'crew_schedules' ) . ' s JOIN ' . Db::t( 'crew_schedule_assignments' ) . ' a ON a.schedule_id=s.id AND a.studio_id=s.studio_id JOIN ' . Db::t( 'crew_members' ) . " cm ON cm.id=a.crew_member_id WHERE s.studio_id=$sid AND s.booking_event_id IN ($ids) AND a.status NOT IN ($inactive) ORDER BY cm.name" );
			$roles    = Config::crew_categories();
			foreach ( $cr as $c ) {
				$crew[ $c->booking_event_id ][] = $c->name . ( isset( $roles[ $c->role ] ) ? ' (' . $roles[ $c->role ] . ')' : '' );
			}
		}
		$types    = Config::event_types();
		$statuses = Config::booking_statuses();
		$out      = array();
		foreach ( $rows as $r ) {
			$key   = 'booking:' . (int) $r->id;
			$team  = array_map( function ( $t ) { return is_array( $t ) ? $t['name'] : $t->name; }, $r->crew );
			$svc   = array_map( function ( $s ) { return $s['name']; }, $r->services );
			$place = implode( ', ', array_filter( array( $r->venue, $r->address, $r->city ) ) );
			$desc  = self::lines(
				array(
					'Client: ' . $r->client_name . ( $r->client_phone ? ' · ' . Format::phone( $r->client_phone ) : '' ),
					'Booking: ' . $r->booking_number . ' · ' . $r->booking_title,
					$r->type && isset( $types[ $r->type ] ) ? 'Event type: ' . $types[ $r->type ] : '',
					'Status: ' . ( $statuses[ $r->booking_status ] ?? $r->booking_status ),
					'Date: ' . Format::date( $r->event_date ) . ( $r->start_time ? ' · ' . Format::time( $r->start_time ) . ( $r->end_time ? ' – ' . Format::time( $r->end_time ) : '' ) : '' ),
					$place ? 'Venue: ' . $place : '',
					$team ? 'Team: ' . implode( ', ', $team ) : 'Team: not assigned yet',
					! empty( $crew[ $r->id ] ) ? 'Crew: ' . implode( ', ', $crew[ $r->id ] ) : '',
					$svc ? 'Services: ' . implode( ', ', $svc ) : '',
					$r->notes ? "\nNotes: " . $r->notes : '',
					"\nOpen in ShootPilot: " . self::crm_url( 'bookings/' . (int) $r->booking_id ),
				)
			);
			$ev = self::base( $acc, 'booking', $key, $r->name . ' – ' . $r->client_name, $desc, 'bookings/' . (int) $r->booking_id );
			if ( $place ) {
				$ev['location'] = mb_substr( $place, 0, 500 );
			}
			if ( $r->start_time ) {
				$mins = 180;
				if ( $r->end_time ) {
					$mins = ( strtotime( '1970-01-01 ' . $r->end_time ) - strtotime( '1970-01-01 ' . $r->start_time ) ) / 60;
					if ( $mins <= 0 ) {
						$mins += 1440; // Overnight (e.g. 8 PM to 2 AM).
					}
				}
				$ev             += self::timed( $r->event_date, $r->start_time, $mins );
				$ev['reminders'] = self::reminders( array( 1440, (int) $acc->reminder_minutes ) );
			} else {
				$ev             += self::all_day( $r->event_date );
				$ev['reminders'] = self::reminders( array( 900 ) ); // 9 AM the day before.
			}
			$out[ $key ] = $ev;
		}
		return $out;
	}

	/* ---------- Tasks ---------- */

	private static function tasks( $acc, $from, $to, $only = 0 ) {
		global $wpdb;
		$sid  = (int) $acc->studio_id;
		$w    = $wpdb->prepare( "t.studio_id=%d AND t.due_at IS NOT NULL AND t.due_at BETWEEN %s AND %s AND t.status<>'cancelled'", $sid, $from . ' 00:00:00', $to . ' 23:59:59' );
		$w   .= $only ? $wpdb->prepare( ' AND t.id=%d', $only ) : '';
		$rows = $wpdb->get_results( 'SELECT t.*, m.name AS assignee, l.name AS lead_name, l.phone AS lead_phone, b.number AS booking_number, b.title AS booking_title, c.name AS client_name FROM ' . Db::t( 'tasks' ) . ' t LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id=t.assigned_to LEFT JOIN ' . Db::t( 'leads' ) . ' l ON l.id=t.lead_id AND l.studio_id=t.studio_id LEFT JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=t.booking_id AND b.studio_id=t.studio_id LEFT JOIN ' . Db::t( 'clients' ) . " c ON c.id=b.client_id WHERE $w ORDER BY t.id" );
		$cats = Config::task_categories();
		$pri  = Config::priorities();
		$out  = array();
		foreach ( $rows as $t ) {
			$key  = 'task:' . (int) $t->id;
			$done = 'done' === $t->status;
			$who  = $t->client_name ? $t->client_name : $t->lead_name;
			$desc = self::lines(
				array(
					$t->description ? $t->description . "\n" : '',
					'Assigned to: ' . ( $t->assignee ? $t->assignee : 'Unassigned' ),
					'Priority: ' . ( $pri[ $t->priority ] ?? $t->priority ),
					$t->category && isset( $cats[ $t->category ] ) ? 'Category: ' . $cats[ $t->category ] : '',
					$t->lead_name ? 'Lead: ' . $t->lead_name . ( $t->lead_phone ? ' · ' . Format::phone( $t->lead_phone ) : '' ) : '',
					$t->booking_number ? 'Booking: ' . $t->booking_number . ' · ' . $t->booking_title : '',
					'Due: ' . Format::date( $t->due_at ) . ' ' . Format::time( $t->due_at ),
					$done ? 'Status: Done' : '',
					"\nOpen in ShootPilot: " . self::crm_url( 'tasks' ),
				)
			);
			$ev  = self::base( $acc, 'task', $key, ( $done ? '✓ ' : '' ) . 'Task: ' . $t->title . ( $who ? ' – ' . $who : '' ), $desc, 'tasks' );
			$ev += self::timed( substr( $t->due_at, 0, 10 ), substr( $t->due_at, 11, 8 ), 30 );
			$ev['reminders'] = self::reminders( $done ? array() : array( (int) $acc->reminder_minutes ) );
			$out[ $key ]     = $ev;
		}
		return $out;
	}

	/* ---------- Follow-up calls (also the "next call" from a call log) ---------- */

	private static function followups( $acc, $from, $to, $only = 0 ) {
		global $wpdb;
		$sid  = (int) $acc->studio_id;
		$w    = $wpdb->prepare( "f.studio_id=%d AND f.due_at BETWEEN %s AND %s AND f.status IN ('pending','done')", $sid, $from . ' 00:00:00', $to . ' 23:59:59' );
		$w   .= $only ? $wpdb->prepare( ' AND f.id=%d', $only ) : '';
		$rows = $wpdb->get_results( 'SELECT f.*, l.name AS lead_name, l.phone, l.whatsapp, l.event_type, l.event_date, l.city, l.stage, m.name AS assignee FROM ' . Db::t( 'followups' ) . ' f JOIN ' . Db::t( 'leads' ) . ' l ON l.id=f.lead_id AND l.studio_id=f.studio_id LEFT JOIN ' . Db::t( 'members' ) . " m ON m.id=f.assigned_to WHERE $w ORDER BY f.id" );
		$types  = Config::event_types();
		$stages = Config::stages();
		$out    = array();
		foreach ( $rows as $f ) {
			$key  = 'followup:' . (int) $f->id;
			$done = 'done' === $f->status;
			$desc = self::lines(
				array(
					'Call: ' . $f->lead_name . ( $f->phone ? ' · ' . Format::phone( $f->phone ) : '' ),
					$f->phone ? 'Tap to call: tel:' . $f->phone : '',
					'Assigned to: ' . ( $f->assignee ? $f->assignee : 'Unassigned' ),
					'Lead stage: ' . ( $stages[ $f->stage ] ?? $f->stage ),
					$f->event_type && isset( $types[ $f->event_type ] ) ? 'Event: ' . $types[ $f->event_type ] . ( $f->event_date ? ' on ' . Format::date( $f->event_date ) : '' ) . ( $f->city ? ', ' . $f->city : '' ) : '',
					$f->note ? "\nNote: " . $f->note : '',
					$done ? 'Status: Done' : '',
					"\nOpen in ShootPilot: " . self::crm_url( 'leads/' . (int) $f->lead_id ),
				)
			);
			$ev  = self::base( $acc, 'followup', $key, ( $done ? '✓ ' : '' ) . 'Follow-up call: ' . $f->lead_name, $desc, 'leads/' . (int) $f->lead_id );
			$ev += self::timed( substr( $f->due_at, 0, 10 ), substr( $f->due_at, 11, 8 ), 15 );
			$ev['reminders'] = self::reminders( $done ? array() : array( min( 30, (int) $acc->reminder_minutes ), 0 ) );
			$out[ $key ]     = $ev;
		}
		return $out;
	}

	/* ---------- Payment due dates (installments that still have a balance) ---------- */

	private static function payments( $acc, $from, $to, $only = 0 ) {
		global $wpdb;
		$sid = (int) $acc->studio_id;
		$w   = $wpdb->prepare( "i.studio_id=%d AND i.due_date IS NOT NULL AND i.due_date BETWEEN %s AND %s AND b.status<>'cancelled'", $sid, $from, $to );
		if ( $only ) {
			$w .= $wpdb->prepare( ' AND i.booking_id=(SELECT booking_id FROM ' . Db::t( 'installments' ) . ' WHERE id=%d AND studio_id=%d)', $only, $sid );
		}
		$bks = $wpdb->get_results( 'SELECT DISTINCT b.* FROM ' . Db::t( 'installments' ) . ' i JOIN ' . Db::t( 'bookings' ) . " b ON b.id=i.booking_id AND b.studio_id=i.studio_id WHERE $w" );
		if ( ! $bks ) {
			return array();
		}
		$clients = array();
		$cids    = implode( ',', array_map( 'intval', wp_list_pluck( $bks, 'client_id' ) ) );
		foreach ( $wpdb->get_results( 'SELECT id, name, phone FROM ' . Db::t( 'clients' ) . " WHERE studio_id=$sid AND id IN ($cids)" ) as $c ) {
			$clients[ $c->id ] = $c;
		}
		$out = array();
		foreach ( $bks as $b ) {
			// Same allocation as the Payments page: received money pays installments in order.
			$sum = Rest\Bookings::payment_summary( $b );
			$c   = isset( $clients[ $b->client_id ] ) ? $clients[ $b->client_id ] : null;
			foreach ( $sum['installments'] as $in ) {
				if ( ! $in->due_date || $in->due_date < $from || $in->due_date > $to || $in->balance <= 0.009 ) {
					continue;
				}
				if ( $only && (int) $in->id !== $only ) {
					continue;
				}
				$key  = 'payment:' . (int) $in->id;
				$name = $c ? $c->name : $b->title;
				$desc = self::lines(
					array(
						'Amount due: ₹' . Format::inr( $in->balance ) . ( $in->paid > 0 ? ' (₹' . Format::inr( $in->paid ) . ' of ₹' . Format::inr( $in->amount ) . ' already received)' : '' ),
						'Installment: ' . $in->label,
						'Client: ' . $name . ( $c && $c->phone ? ' · ' . Format::phone( $c->phone ) : '' ),
						'Booking: ' . $b->number . ' · ' . $b->title,
						'Booking total: ₹' . Format::inr( $sum['total'] ) . ' · Paid: ₹' . Format::inr( $sum['paid'] ) . ' · Pending: ₹' . Format::inr( $sum['pending'] ),
						'Due date: ' . Format::date( $in->due_date ),
						"\nOpen in ShootPilot: " . self::crm_url( 'bookings/' . (int) $b->id ),
					)
				);
				$ev  = self::base( $acc, 'payment', $key, 'Payment due ₹' . Format::inr( $in->balance ) . ' – ' . $name . ' (' . $in->label . ')', $desc, 'bookings/' . (int) $b->id );
				$ev += self::all_day( $in->due_date );
				$ev['reminders'] = self::reminders( array( 900 ) ); // 9 AM the day before.
				$out[ $key ]     = $ev;
			}
		}
		return $out;
	}
}
