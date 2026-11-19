<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

class Calls extends Base {

	public function register() {
		$this->route( '/leads/(?P<id>\d+)/calls', 'POST', array( $this, 'log_call' ), 'leads.edit' );
		$this->route( '/calls', 'GET', array( $this, 'history' ), 'leads.view' );
		$this->route( '/calling/list', 'GET', array( $this, 'calling_list' ), 'leads.view' );
		$this->route( '/calling/summary', 'GET', array( $this, 'summary' ), 'leads.view' );
	}

	public function log_call( $req ) {
		$id   = (int) $req['id'];
		$lead = Db::find( 'leads', $id );
		if ( ! $lead ) {
			return $this->not_found( 'Lead' );
		}
		$data = $this->validate(
			$req,
			array(
				'status'       => array( 'type' => 'enum', 'options' => Config::call_statuses(), 'required' => true, 'label' => 'Call status' ),
				'notes'        => array( 'type' => 'text', 'label' => 'Calling notes' ),
				'called_at'    => array( 'type' => 'datetime', 'label' => 'Call date' ),
				'next_call_at' => array( 'type' => 'datetime', 'label' => 'Next call date' ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$called = $data['called_at'] ? $data['called_at'] : Db::now();
		if ( $data['next_call_at'] && $data['next_call_at'] < $called ) {
			return new \WP_Error( 'validation_failed', 'Next call date must be after this call.', array( 'status' => 422, 'errors' => array( 'next_call_at' => 'Must be after this call.' ) ) );
		}
		Db::begin();
		$cid = Db::insert(
			'calls',
			array(
				'lead_id'      => $id,
				'member_id'    => $this->member_id(),
				'status'       => $data['status'],
				'notes'        => $data['notes'],
				'called_at'    => $called,
				'next_call_at' => $data['next_call_at'],
			)
		);
		if ( is_wp_error( $cid ) ) {
			Db::rollback();
			return $cid;
		}

		// Close follow-ups that were due by now: this call handled them.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'followups' ) . " SET status='done', completed_at=%s WHERE studio_id=%d AND lead_id=%d AND status='pending' AND due_at <= %s", Db::now(), $this->sid(), $id, date( 'Y-m-d 23:59:59', strtotime( $called ) ) ) );

		$update = array(
			'call_status'  => $data['status'],
			'last_call_at' => $called,
		);
		// Move early-stage leads forward automatically.
		if ( in_array( $lead->stage, array( 'new', 'first_call_pending' ), true ) ) {
			if ( in_array( $data['status'], array( 'connected', 'call_back', 'followup' ), true ) ) {
				$update['stage'] = 'contacted';
			} elseif ( 'interested' === $data['status'] ) {
				$update['stage'] = 'interested';
			} elseif ( 'new' === $lead->stage ) {
				$update['stage'] = 'first_call_pending';
			}
		} elseif ( 'contacted' === $lead->stage && 'interested' === $data['status'] ) {
			$update['stage'] = 'interested';
		}
		if ( in_array( $data['status'], array( 'followup', 'call_back' ), true ) && ! in_array( $lead->stage, array( 'quotation_sent', 'negotiation', 'booked', 'lost' ), true ) && $data['next_call_at'] ) {
			$update['stage'] = 'followup_required';
		}

		$statuses = Config::call_statuses();
		$msg      = 'Call logged: ' . $statuses[ $data['status'] ] . ( $data['notes'] ? ' — ' . $data['notes'] : '' );
		Db::log( 'call', $msg, array( 'lead_id' => $id ), array( 'status' => $data['status'] ) );

		$update['next_followup_at'] = $data['next_call_at'];
		Leads::apply_update( $lead, $update );
		if ( ! $data['next_call_at'] ) {
			Followups::sync_lead( $id );
		}
		Db::commit();
		return $this->ok( Db::cast( Db::find( 'calls', $cid ) ), 201 );
	}

	public function history( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$w = array( $wpdb->prepare( 'c.studio_id=%d', $this->sid() ) );
		if ( $req->get_param( 'lead_id' ) ) {
			$w[] = $wpdb->prepare( 'c.lead_id=%d', $req->get_param( 'lead_id' ) );
		}
		if ( $req->get_param( 'member_id' ) ) {
			$w[] = $wpdb->prepare( 'c.member_id=%d', $req->get_param( 'member_id' ) );
		}
		$d = \PCRM\Validator::date( (string) $req->get_param( 'date' ) );
		if ( $d ) {
			$w[] = $wpdb->prepare( 'DATE(c.called_at)=%s', $d );
		}
		$where = implode( ' AND ', $w );
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'calls' ) . " c WHERE $where" );
		$rows  = $wpdb->get_results( 'SELECT c.*, l.name AS lead_name, l.phone AS lead_phone, m.name AS member_name FROM ' . Db::t( 'calls' ) . ' c JOIN ' . Db::t( 'leads' ) . ' l ON l.id=c.lead_id LEFT JOIN ' . Db::t( 'members' ) . " m ON m.id=c.member_id WHERE $where ORDER BY c.called_at DESC LIMIT $per OFFSET $off" );
		return $this->ok( array( 'items' => Db::cast_all( $rows ), 'total' => $total, 'page' => $page, 'per_page' => $per ) );
	}

	/**
	 * Today's calling list: due today, overdue, never called, call-backs.
	 */
	public function calling_list( $req ) {
		global $wpdb;
		$view  = $req->get_param( 'view' ) ? $req->get_param( 'view' ) : 'today';
		$sid   = $this->sid();
		$today = Db::today();
		$base  = 'SELECT l.*, m.name AS assigned_name FROM ' . Db::t( 'leads' ) . ' l LEFT JOIN ' . Db::t( 'members' ) . " m ON m.id=l.assigned_to WHERE l.studio_id=%d AND l.stage NOT IN ('booked','lost')";
		$mine  = $req->get_param( 'mine' ) ? $wpdb->prepare( ' AND l.assigned_to=%d', $this->member_id() ) : '';
		switch ( $view ) {
			case 'overdue':
				$sql = $wpdb->prepare( $base . $mine . ' AND l.next_followup_at < %s ORDER BY l.next_followup_at ASC', $sid, $today . ' 00:00:00' );
				break;
			case 'not_called':
				$sql = $wpdb->prepare( $base . $mine . " AND l.call_status='not_called' ORDER BY l.created_at DESC", $sid );
				break;
			case 'callbacks':
				$sql = $wpdb->prepare( $base . $mine . " AND l.call_status IN ('call_back','not_answered','attempted','switched_off') ORDER BY l.next_followup_at IS NULL, l.next_followup_at ASC", $sid );
				break;
			case 'upcoming':
				$sql = $wpdb->prepare( $base . $mine . ' AND l.next_followup_at > %s ORDER BY l.next_followup_at ASC', $sid, $today . ' 23:59:59' );
				break;
			default:
				$sql = $wpdb->prepare( $base . $mine . ' AND DATE(l.next_followup_at)=%s ORDER BY l.next_followup_at ASC', $sid, $today );
		}
		return $this->ok( Db::cast_all( $wpdb->get_results( $sql . ' LIMIT 500' ) ) );
	}

	public function summary() {
		global $wpdb;
		$sid   = $this->sid();
		$today = Db::today();
		$l     = Db::t( 'leads' );
		$open  = " AND stage NOT IN ('booked','lost')";
		return $this->ok(
			array(
				'today'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE studio_id=%d AND DATE(next_followup_at)=%s$open", $sid, $today ) ),
				'overdue'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE studio_id=%d AND next_followup_at < %s$open", $sid, $today . ' 00:00:00' ) ),
				'not_called'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE studio_id=%d AND call_status='not_called'$open", $sid ) ),
				'callbacks'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE studio_id=%d AND call_status IN ('call_back','not_answered','attempted','switched_off')$open", $sid ) ),
				'calls_today' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'calls' ) . ' WHERE studio_id=%d AND DATE(called_at)=%s', $sid, $today ) ),
				'connected_today' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'calls' ) . " WHERE studio_id=%d AND DATE(called_at)=%s AND status IN ('connected','interested','call_back','followup')", $sid, $today ) ),
			)
		);
	}
}
