<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

class Followups extends Base {

	public function register() {
		$this->route( '/followups', 'GET', array( $this, 'index' ), 'leads.view' );
		$this->route( '/followups', 'POST', array( $this, 'create' ), 'leads.edit' );
		$this->route( '/followups/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'leads.edit' );
		$this->route( '/followups/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'leads.edit' );
		$this->route( '/followups/(?P<id>\d+)/complete', 'POST', array( $this, 'complete' ), 'leads.edit' );
	}

	/**
	 * Keeps leads.next_followup_at equal to the earliest pending follow-up.
	 */
	public static function sync_lead( $lead_id ) {
		global $wpdb;
		$next = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(due_at) FROM ' . Db::t( 'followups' ) . " WHERE studio_id=%d AND lead_id=%d AND status='pending'", Tenant::studio_id(), $lead_id ) );
		$wpdb->update( Db::t( 'leads' ), array( 'next_followup_at' => $next ), array( 'id' => (int) $lead_id, 'studio_id' => Tenant::studio_id() ) );
	}

	public function index( $req ) {
		global $wpdb;
		$view  = $req->get_param( 'view' ) ? $req->get_param( 'view' ) : 'today';
		$sid   = $this->sid();
		$today = Db::today();
		$w     = array( $wpdb->prepare( 'f.studio_id=%d', $sid ) );
		switch ( $view ) {
			case 'overdue':
				$w[] = $wpdb->prepare( "f.status='pending' AND f.due_at < %s", $today . ' 00:00:00' );
				$ord = 'f.due_at ASC';
				break;
			case 'upcoming':
				$w[] = $wpdb->prepare( "f.status='pending' AND f.due_at > %s", $today . ' 23:59:59' );
				$ord = 'f.due_at ASC';
				break;
			case 'done':
				$w[] = "f.status='done'";
				$ord = 'f.completed_at DESC';
				break;
			case 'all':
				$ord = 'f.due_at DESC';
				break;
			default:
				$w[] = $wpdb->prepare( "f.status='pending' AND DATE(f.due_at)=%s", $today );
				$ord = 'f.due_at ASC';
		}
		if ( $req->get_param( 'lead_id' ) ) {
			$w[] = $wpdb->prepare( 'f.lead_id=%d', $req->get_param( 'lead_id' ) );
		}
		if ( $req->get_param( 'mine' ) ) {
			$w[] = $wpdb->prepare( 'f.assigned_to=%d', $this->member_id() );
		}
		$rows = $wpdb->get_results( 'SELECT f.*, l.name AS lead_name, l.phone AS lead_phone, l.whatsapp AS lead_whatsapp, l.stage AS lead_stage, l.event_type, l.event_date, m.name AS assigned_name FROM ' . Db::t( 'followups' ) . ' f JOIN ' . Db::t( 'leads' ) . ' l ON l.id=f.lead_id LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id=f.assigned_to WHERE ' . implode( ' AND ', $w ) . " ORDER BY $ord LIMIT 500" );
		return $this->ok( Db::cast_all( $rows ) );
	}

	private function schema() {
		return array(
			'lead_id'     => array( 'type' => 'ref', 'table' => 'leads', 'required' => true, 'label' => 'Lead' ),
			'due_at'      => array( 'type' => 'datetime', 'required' => true, 'label' => 'Follow-up date and time' ),
			'note'        => array( 'type' => 'text' ),
			'assigned_to' => array( 'type' => 'ref', 'table' => 'members', 'label' => 'Assigned to' ),
		);
	}

	public function create( $req ) {
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$lead = Db::find( 'leads', $data['lead_id'] );
		if ( ! $data['assigned_to'] ) {
			$data['assigned_to'] = $lead->assigned_to ? $lead->assigned_to : $this->member_id();
		}
		$data['status']     = 'pending';
		$data['created_by'] = get_current_user_id();
		$id                 = Db::insert( 'followups', $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Db::log( 'followup', 'Follow-up scheduled for ' . mysql2date( 'd-m-Y h:i A', $data['due_at'] ) . ( $data['note'] ? ' — ' . $data['note'] : '' ), array( 'lead_id' => $data['lead_id'] ) );
		self::sync_lead( $data['lead_id'] );
		return $this->ok( Db::cast( Db::find( 'followups', $id ) ), 201 );
	}

	public function update( $req ) {
		$f = Db::find( 'followups', (int) $req['id'] );
		if ( ! $f ) {
			return $this->not_found( 'Follow-up' );
		}
		$schema = $this->schema();
		unset( $schema['lead_id'] );
		$schema['status'] = array( 'type' => 'enum', 'options' => array( 'pending' => 1, 'done' => 1, 'cancelled' => 1 ) );
		$data             = $this->validate( $req, $schema, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['status'] ) && 'done' === $data['status'] && 'done' !== $f->status ) {
			$data['completed_at'] = Db::now();
		}
		Db::update( 'followups', $f->id, $data );
		if ( isset( $data['due_at'] ) && $data['due_at'] !== $f->due_at ) {
			Db::log( 'followup', 'Follow-up rescheduled to ' . mysql2date( 'd-m-Y h:i A', $data['due_at'] ), array( 'lead_id' => (int) $f->lead_id ) );
		}
		self::sync_lead( $f->lead_id );
		return $this->ok( Db::cast( Db::find( 'followups', $f->id ) ) );
	}

	public function complete( $req ) {
		$f = Db::find( 'followups', (int) $req['id'] );
		if ( ! $f ) {
			return $this->not_found( 'Follow-up' );
		}
		$b    = $this->body( $req );
		$note = isset( $b['note'] ) ? sanitize_textarea_field( $b['note'] ) : '';
		Db::update( 'followups', $f->id, array( 'status' => 'done', 'completed_at' => Db::now() ) );
		Db::log( 'followup', 'Follow-up completed' . ( $note ? ' — ' . $note : '' ), array( 'lead_id' => (int) $f->lead_id ) );
		self::sync_lead( $f->lead_id );
		return $this->ok( array( 'ok' => true ) );
	}

	public function destroy( $req ) {
		$f = Db::find( 'followups', (int) $req['id'] );
		if ( ! $f ) {
			return $this->not_found( 'Follow-up' );
		}
		Db::delete( 'followups', $f->id );
		self::sync_lead( $f->lead_id );
		return $this->ok( array( 'deleted' => true ) );
	}
}
