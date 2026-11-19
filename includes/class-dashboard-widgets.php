<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard widget catalogue and per-user layouts.
 *
 * The shipped default layout is exactly the v0.5.0 dashboard. A user can show,
 * hide, reorder and pick a date range for widgets; the layout is stored in that
 * user's meta, keyed by studio, so it is per user and per studio. Nothing here
 * changes the database schema.
 *
 * Every widget lists the permissions it needs. A widget whose permissions the
 * signed-in member does not hold is left out of the catalogue, dropped from any
 * saved layout and refused by the widget data endpoint (HTTP 403).
 */
class Dashboard_Widgets {

	const META_PREFIX = 'pcrm_dashboard_layout_';
	const LAYOUT_VERSION = 1;

	/** Range keys → labels. */
	public static function range_labels() {
		return array(
			'this_month' => 'This month',
			'last_month' => 'Last month',
			'3m'         => 'Last 3 months',
			'6m'         => 'Last 6 months',
			'12m'        => 'Last 12 months',
			'24m'        => 'Last 24 months',
			'this_fy'    => 'This financial year',
			'all'        => 'All time',
			'7d'         => 'Next 7 days',
			'14d'        => 'Next 14 days',
			'30d'        => 'Next 30 days',
			'60d'        => 'Next 60 days',
			'90d'        => 'Next 90 days',
		);
	}

	/**
	 * The full catalogue, in default order. `default` widgets make up the shipped
	 * dashboard; `source => dashboard` widgets read from GET /dashboard when they
	 * use their default range (so the default dashboard makes the same single
	 * request it always did).
	 *
	 * kind: stat (a small KPI card, grouped into a strip) or card (a panel).
	 * strip: which KPI strip a stat sits in (pipeline = 5 columns, business = 3).
	 * span: 1 or 2 columns of the 3-column card grid on wide screens.
	 */
	public static function catalogue() {
		$period  = array( 'this_month', 'last_month', '3m', '12m', 'this_fy', 'all' );
		$months  = array( '6m', '12m', '24m' );
		$forward = array( '7d', '14d', '30d', '60d', '90d' );

		$w = array(
			// ---- Shipped default (v0.5.0 dashboard, same order) ----
			array( 'id' => 'stat_total_leads', 'title' => 'Total Leads', 'description' => 'All leads, with how many were added this month.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_new_leads', 'title' => 'New Leads', 'description' => 'Leads not contacted yet.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_contacted', 'title' => 'Contacted', 'description' => 'Contacted leads and first calls pending.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_followups_pending', 'title' => 'Follow-up Pending', 'description' => 'Follow-ups due today or overdue.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_interested', 'title' => 'Interested', 'description' => 'Interested leads and those needing follow-up.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_quotation_sent', 'title' => 'Quotation Sent', 'description' => 'Leads at the quotation stage.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_negotiation', 'title' => 'Negotiation', 'description' => 'Leads in negotiation.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_booked', 'title' => 'Booking Confirmed', 'description' => 'Leads that became bookings.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_lost', 'title' => 'Lost Leads', 'description' => 'Leads marked lost.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_followups_today', 'title' => "Today's Follow-ups", 'description' => 'Follow-ups scheduled for today.', 'kind' => 'stat', 'strip' => 'pipeline', 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_revenue', 'title' => 'Total Revenue (collected)', 'description' => 'All payments received, and this month.', 'kind' => 'stat', 'strip' => 'business', 'perms' => array( 'payments.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_pending_payments', 'title' => 'Pending Payments', 'description' => 'Amount still due from clients, and overdue bookings.', 'kind' => 'stat', 'strip' => 'business', 'perms' => array( 'payments.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'stat_upcoming_events', 'title' => 'Upcoming Events', 'description' => 'Event days coming up.', 'kind' => 'stat', 'strip' => 'business', 'perms' => array( 'calendar.view' ), 'default' => true, 'source' => 'dashboard', 'ranges' => array( '7d', '14d', '30d', '60d', '90d' ), 'default_range' => '30d' ),
			array( 'id' => 'chart_revenue', 'title' => 'Monthly revenue', 'description' => 'Payments collected vs. new bookings value, by month.', 'kind' => 'card', 'span' => 2, 'perms' => array( 'payments.view' ), 'default' => true, 'source' => 'dashboard', 'ranges' => $months, 'default_range' => '12m' ),
			array( 'id' => 'chart_sources', 'title' => 'Lead sources', 'description' => 'Enquiries by channel.', 'kind' => 'card', 'span' => 1, 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard', 'ranges' => $period, 'default_range' => 'all' ),
			array( 'id' => 'chart_conversion', 'title' => 'Lead conversion', 'description' => 'Leads received each month and how many became bookings.', 'kind' => 'card', 'span' => 2, 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard', 'ranges' => $months, 'default_range' => '12m' ),
			array( 'id' => 'list_followups', 'title' => "Today's follow-ups", 'description' => 'Follow-ups due today and overdue, with call and WhatsApp buttons.', 'kind' => 'card', 'span' => 1, 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'list_upcoming_events', 'title' => 'Upcoming weddings & events', 'description' => 'Event days with venue and team.', 'kind' => 'card', 'span' => 2, 'perms' => array( 'calendar.view' ), 'default' => true, 'source' => 'dashboard', 'ranges' => array( '7d', '14d', '30d', '60d', '90d' ), 'default_range' => '60d' ),
			array( 'id' => 'list_tasks', 'title' => "Today's tasks", 'description' => 'Tasks due today and overdue.', 'kind' => 'card', 'span' => 1, 'perms' => array( 'tasks.view' ), 'default' => true, 'source' => 'dashboard' ),
			array( 'id' => 'list_activity', 'title' => 'Recent activity', 'description' => 'Latest updates across leads, bookings and payments.', 'kind' => 'card', 'span' => 1, 'perms' => array( 'leads.view' ), 'default' => true, 'source' => 'dashboard' ),

			// ---- Optional widgets (off by default) ----
			array( 'id' => 'stat_conversion_rate', 'title' => 'Conversion rate', 'description' => 'Share of leads received in the period that became bookings.', 'kind' => 'stat', 'strip' => 'insights', 'perms' => array( 'leads.view' ), 'ranges' => $period, 'default_range' => '3m' ),
			array( 'id' => 'stat_bookings_confirmed', 'title' => 'Bookings confirmed', 'description' => 'Bookings confirmed in the period (value shown if you can see payments).', 'kind' => 'stat', 'strip' => 'insights', 'perms' => array( 'bookings.view_all' ), 'ranges' => $period, 'default_range' => 'this_month' ),
			array( 'id' => 'stat_collected', 'title' => 'Payments received', 'description' => 'Client payments received in the period.', 'kind' => 'stat', 'strip' => 'insights', 'perms' => array( 'payments.view' ), 'ranges' => $period, 'default_range' => 'this_month' ),
			array( 'id' => 'stat_expenses', 'title' => 'Expenses', 'description' => 'Studio expenses and crew payouts in the period.', 'kind' => 'stat', 'strip' => 'insights', 'perms' => array( 'payments.view', 'expenses.view' ), 'ranges' => $period, 'default_range' => 'this_month' ),
			array( 'id' => 'stat_profit', 'title' => 'Net (collected − expenses)', 'description' => 'Payments received minus expenses in the period.', 'kind' => 'stat', 'strip' => 'insights', 'perms' => array( 'payments.view', 'expenses.view' ), 'ranges' => $period, 'default_range' => 'this_month' ),
			array( 'id' => 'stat_deliverables_open', 'title' => 'Deliverables in progress', 'description' => 'Deliverables not yet delivered, and how many are overdue.', 'kind' => 'stat', 'strip' => 'insights', 'perms' => array( 'deliverables.view' ) ),
			array( 'id' => 'stat_tasks_open', 'title' => 'Open tasks', 'description' => 'Tasks not done yet, and how many are overdue.', 'kind' => 'stat', 'strip' => 'insights', 'perms' => array( 'tasks.view' ) ),
			array( 'id' => 'chart_leads_by_stage', 'title' => 'Leads by stage', 'description' => 'Where leads received in the period are in your pipeline.', 'kind' => 'card', 'span' => 1, 'perms' => array( 'leads.view' ), 'ranges' => $period, 'default_range' => 'all' ),
			array( 'id' => 'chart_cashflow', 'title' => 'Income vs expenses', 'description' => 'Payments received vs. expenses (incl. crew), by month.', 'kind' => 'card', 'span' => 2, 'perms' => array( 'payments.view', 'expenses.view' ), 'ranges' => $months, 'default_range' => '12m' ),
			array( 'id' => 'list_outstanding', 'title' => 'Outstanding payments', 'description' => 'Bookings with the largest amounts still due.', 'kind' => 'card', 'span' => 1, 'perms' => array( 'payments.view' ) ),
			array( 'id' => 'list_deliverables', 'title' => 'Deliverables in progress', 'description' => 'Open deliverables, soonest expected date first.', 'kind' => 'card', 'span' => 1, 'perms' => array( 'deliverables.view' ) ),
			array( 'id' => 'list_crew_schedule', 'title' => 'Crew schedule', 'description' => 'Freelance crew booked for upcoming shoots.', 'kind' => 'card', 'span' => 1, 'perms' => array( 'crew.view' ), 'ranges' => array( '7d', '14d', '30d' ), 'default_range' => '7d' ),
			array( 'id' => 'chart_task_load', 'title' => 'Task load by person', 'description' => 'Open and overdue tasks for each team member.', 'kind' => 'card', 'span' => 1, 'perms' => array( 'tasks.view_all' ) ),
		);

		return array_map(
			function ( $x ) {
				return array_merge(
					array( 'default' => false, 'source' => 'widget', 'ranges' => array(), 'default_range' => null, 'span' => 1, 'strip' => null ),
					$x
				);
			},
			$w
		);
	}

	/** Can the current member use this widget? All listed permissions are required. */
	public static function allowed( array $w ) {
		foreach ( $w['perms'] as $p ) {
			if ( ! Tenant::can( $p ) ) {
				return false;
			}
		}
		return true;
	}

	/** Catalogue limited to what the current member may see, keyed by id. */
	public static function available() {
		$out = array();
		foreach ( self::catalogue() as $w ) {
			if ( self::allowed( $w ) ) {
				$out[ $w['id'] ] = $w;
			}
		}
		return $out;
	}

	public static function find( $id ) {
		foreach ( self::catalogue() as $w ) {
			if ( $w['id'] === $id ) {
				return $w;
			}
		}
		return null;
	}

	/** The shipped default layout for the current member. */
	public static function default_layout() {
		$items = array();
		foreach ( self::available() as $w ) {
			$items[] = array( 'id' => $w['id'], 'visible' => (bool) $w['default'], 'range' => $w['default_range'] );
		}
		return $items;
	}

	private static function meta_key() {
		return self::META_PREFIX . (int) Tenant::studio_id();
	}

	/**
	 * Cleans a list of layout items against what the current member may see.
	 * Unknown or forbidden widgets are dropped, duplicates removed, bad ranges
	 * replaced by the default, and widgets missing from the list are appended
	 * hidden (so widgets added in later versions never appear uninvited).
	 */
	public static function normalize( $items ) {
		$avail = self::available();
		$seen  = array();
		$out   = array();
		foreach ( (array) $items as $it ) {
			if ( ! is_array( $it ) || empty( $it['id'] ) || ! is_string( $it['id'] ) ) {
				continue;
			}
			$id = $it['id'];
			if ( ! isset( $avail[ $id ] ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			$w     = $avail[ $id ];
			$range = isset( $it['range'] ) && is_string( $it['range'] ) && in_array( $it['range'], $w['ranges'], true ) ? $it['range'] : $w['default_range'];
			$out[] = array( 'id' => $id, 'visible' => ! empty( $it['visible'] ), 'range' => $range );
			$seen[ $id ] = true;
		}
		foreach ( $avail as $id => $w ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$out[] = array( 'id' => $id, 'visible' => false, 'range' => $w['default_range'] );
			}
		}
		return $out;
	}

	/** Returns array( items, is_default ). */
	public static function layout() {
		$raw = get_user_meta( get_current_user_id(), self::meta_key(), true );
		$raw = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $raw ) || empty( $raw['items'] ) ) {
			return array( self::default_layout(), true );
		}
		return array( self::normalize( $raw['items'] ), false );
	}

	public static function save( $items ) {
		$clean = self::normalize( $items );
		update_user_meta(
			get_current_user_id(),
			self::meta_key(),
			wp_json_encode( array( 'v' => self::LAYOUT_VERSION, 'items' => $clean, 'updated_at' => Db::now() ) )
		);
		return $clean;
	}

	public static function reset() {
		delete_user_meta( get_current_user_id(), self::meta_key() );
		return self::default_layout();
	}

	/** Public shape of the catalogue for the SPA (no permission names leaked). */
	public static function public_catalogue() {
		$labels = self::range_labels();
		return array_values(
			array_map(
				function ( $w ) use ( $labels ) {
					$r = array();
					foreach ( $w['ranges'] as $k ) {
						$r[] = array( 'key' => $k, 'label' => $labels[ $k ] );
					}
					return array(
						'id'            => $w['id'],
						'title'         => $w['title'],
						'description'   => $w['description'],
						'kind'          => $w['kind'],
						'strip'         => $w['strip'],
						'span'          => (int) $w['span'],
						'default'       => (bool) $w['default'],
						'source'        => $w['source'],
						'ranges'        => $r,
						'default_range' => $w['default_range'],
					);
				},
				self::available()
			)
		);
	}

	/* ---------- Ranges ---------- */

	/** Past period → array( from Y-m-d|null, to Y-m-d|null ). */
	public static function period( $key ) {
		$today = Db::today();
		$ts    = strtotime( $today );
		switch ( $key ) {
			case 'this_month':
				return array( date( 'Y-m-01', $ts ), $today );
			case 'last_month':
				$first = strtotime( '-1 month', strtotime( date( 'Y-m-01', $ts ) ) );
				return array( date( 'Y-m-01', $first ), date( 'Y-m-t', $first ) );
			case '3m':
			case '6m':
			case '12m':
			case '24m':
				$n = (int) $key;
				return array( date( 'Y-m-d', strtotime( '+1 day', strtotime( "-$n months", $ts ) ) ), $today );
			case 'this_fy':
				// Indian financial year: 1 April to 31 March.
				$y = (int) date( 'n', $ts ) >= 4 ? (int) date( 'Y', $ts ) : (int) date( 'Y', $ts ) - 1;
				return array( $y . '-04-01', $today );
			default:
				return array( null, null );
		}
	}

	/** Month-series range → number of months. */
	public static function months( $key ) {
		$n = (int) $key;
		return in_array( $n, array( 6, 12, 24 ), true ) ? $n : 12;
	}

	/** Forward range → number of days. */
	public static function days( $key ) {
		$n = (int) $key;
		return in_array( $n, array( 7, 14, 30, 60, 90 ), true ) ? $n : 30;
	}
}
