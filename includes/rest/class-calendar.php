<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Tenant;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

class Calendar extends Base {

	public function register() {
		$this->route( '/calendar', 'GET', array( $this, 'index' ), 'calendar.view' );
	}

	public static function events( $from, $to, array $opts = array() ) {
		global $wpdb;
		$sid = Tenant::studio_id();
		$w   = array( $wpdb->prepare( 'e.studio_id=%d AND e.event_date BETWEEN %s AND %s', $sid, $from, $to ) );
		$work = Bookings::work_sql();
		if ( ! empty( $opts['member_id'] ) ) {
			$w[] = $wpdb->prepare( 'e.id IN (SELECT booking_event_id FROM ' . $work . ' w1 WHERE w1.member_id=%d)', $opts['member_id'] );
		}
		if ( ! Tenant::can( 'bookings.view_all' ) ) {
			$m   = Tenant::member();
			$w[] = $wpdb->prepare( 'e.booking_id IN (' . Bookings::member_booking_ids_sql( $m ? $m->id : 0 ) . ') AND e.id IN (SELECT booking_event_id FROM ' . $work . ' w2 WHERE w2.member_id=%d)', $m ? $m->id : 0 );
		}
		if ( ! empty( $opts['event_type'] ) ) {
			$w[] = $wpdb->prepare( 'COALESCE(e.event_type, b.event_type)=%s', $opts['event_type'] );
		}
		if ( empty( $opts['include_cancelled'] ) ) {
			$w[] = "b.status<>'cancelled'";
		}
		$rows = Db::cast_all( $wpdb->get_results( 'SELECT e.*, COALESCE(e.event_type, b.event_type) AS type, b.number AS booking_number, b.title AS booking_title, b.status AS booking_status, c.name AS client_name, c.phone AS client_phone FROM ' . Db::t( 'booking_events' ) . ' e JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=e.booking_id JOIN ' . Db::t( 'clients' ) . ' c ON c.id=b.client_id WHERE ' . implode( ' AND ', $w ) . ' ORDER BY e.event_date, e.start_time, e.id' ) );
		if ( $rows ) {
			$ids  = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
			$team = Db::cast_all( $wpdb->get_results( 'SELECT a.booking_event_id, a.member_id, a.role, m.name, m.color FROM ' . Db::t( 'event_assignments' ) . ' a JOIN ' . Db::t( 'members' ) . " m ON m.id=a.member_id WHERE a.studio_id=$sid AND a.booking_event_id IN ($ids)" ) );
			$by   = array();
			foreach ( $team as $t ) {
				$by[ $t->booking_event_id ][] = $t;
			}
			// On-site service teams cover every event of their booking.
			$bids = implode( ',', array_unique( array_map( 'intval', wp_list_pluck( $rows, 'booking_id' ) ) ) );
			$svc  = Db::cast_all( $wpdb->get_results( 'SELECT s.booking_id, s.id AS service_id, s.name AS service_name, s.on_site, sta.member_id, m.name, m.color FROM ' . Db::t( 'booking_services' ) . ' s JOIN ' . Db::t( 'service_team_assignments' ) . ' sta ON sta.booking_service_id=s.id JOIN ' . Db::t( 'members' ) . " m ON m.id=sta.member_id WHERE s.studio_id=$sid AND s.booking_id IN ($bids) ORDER BY s.sort, s.id, m.name" ) );
			$sb   = array();
			foreach ( $svc as $x ) {
				$sb[ $x->booking_id ][ $x->service_id ]['name']   = $x->service_name;
				$sb[ $x->booking_id ][ $x->service_id ]['on_site'] = (bool) $x->on_site;
				$sb[ $x->booking_id ][ $x->service_id ]['team'][]  = array( 'member_id' => $x->member_id, 'name' => $x->name, 'color' => $x->color );
			}
			foreach ( $rows as $r ) {
				$r->team     = isset( $by[ $r->id ] ) ? $by[ $r->id ] : array();
				$r->services = isset( $sb[ $r->booking_id ] ) ? array_values( $sb[ $r->booking_id ] ) : array();
				// Everyone working this event, for avatars and filters.
				$crew = array();
				foreach ( $r->team as $t ) {
					$crew[ $t->member_id ] = array( 'member_id' => $t->member_id, 'name' => $t->name, 'color' => $t->color );
				}
				foreach ( $r->services as $s ) {
					if ( $s['on_site'] ) {
						foreach ( $s['team'] as $t ) {
							$crew[ $t['member_id'] ] = $t;
						}
					}
				}
				$r->crew = array_values( $crew );
			}
		}
		return $rows;
	}

	public function index( $req ) {
		global $wpdb;
		$from = Validator::date( (string) $req->get_param( 'from' ) );
		$to   = Validator::date( (string) $req->get_param( 'to' ) );
		if ( ! $from ) {
			$from = Db::date( 'Y-m-01' );
		}
		if ( ! $to || $to < $from ) {
			$to = date( 'Y-m-t', strtotime( $from ) );
		}
		if ( ( strtotime( $to ) - strtotime( $from ) ) > 400 * DAY_IN_SECONDS ) {
			return new \WP_Error( 'validation_failed', 'Date range is too large.', array( 'status' => 422 ) );
		}
		$type   = $req->get_param( 'event_type' );
		$events = self::events(
			$from,
			$to,
			array(
				'member_id'  => (int) $req->get_param( 'member_id' ),
				'event_type' => $type && array_key_exists( $type, Config::event_types() ) ? $type : null,
			)
		);
		$tentative = array();
		if ( $req->get_param( 'include_leads' ) && Tenant::can( 'leads.view' ) ) {
			$tentative = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, event_type, event_date, event_end_date, venue, city, stage FROM ' . Db::t( 'leads' ) . " WHERE studio_id=%d AND stage IN ('interested','followup_required','quotation_sent','negotiation') AND event_date BETWEEN %s AND %s ORDER BY event_date", $this->sid(), $from, $to ) ) );
		}
		return $this->ok( array( 'events' => $events, 'tentative' => $tentative, 'from' => $from, 'to' => $to ) );
	}
}
