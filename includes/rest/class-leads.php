<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Plans;
use PCRM\Tenant;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

class Leads extends Base {

	public static function schema() {
		return array(
			'name'           => array( 'type' => 'string', 'required' => true, 'label' => 'Client name' ),
			'phone'          => array( 'type' => 'phone', 'required' => true, 'label' => 'Phone number' ),
			'whatsapp'       => array( 'type' => 'phone', 'label' => 'WhatsApp number' ),
			'email'          => array( 'type' => 'email' ),
			'event_type'     => array( 'type' => 'enum', 'options' => Config::event_types(), 'label' => 'Event type' ),
			'event_date'     => array( 'type' => 'date', 'label' => 'Event date' ),
			'event_end_date' => array( 'type' => 'date', 'label' => 'Event end date' ),
			'event_duration' => array( 'type' => 'string', 'max' => 60, 'label' => 'Event duration' ),
			'venue'          => array( 'type' => 'string', 'max' => 255 ),
			'city'           => array( 'type' => 'string', 'max' => 100 ),
			'source'         => array( 'type' => 'enum', 'options' => Config::sources(), 'label' => 'Lead source' ),
			'assigned_to'    => array( 'type' => 'ref', 'table' => 'members', 'label' => 'Assigned sales person' ),
			'budget'         => array( 'type' => 'money', 'label' => 'Estimated budget' ),
			'stage'          => array( 'type' => 'enum', 'options' => Config::stages(), 'default' => 'new', 'label' => 'Lead status' ),
			'priority'       => array( 'type' => 'enum', 'options' => Config::priorities(), 'default' => 'medium' ),
			'call_status'    => array( 'type' => 'enum', 'options' => Config::call_statuses(), 'default' => 'not_called', 'label' => 'Call status' ),
			'next_followup_at' => array( 'type' => 'datetime', 'label' => 'Next follow-up' ),
			'lost_reason'    => array( 'type' => 'string', 'max' => 255, 'label' => 'Lost reason' ),
			'notes'          => array( 'type' => 'text' ),
		);
	}

	public function register() {
		$this->route( '/leads', 'GET', array( $this, 'index' ), 'leads.view' );
		$this->route( '/leads', 'POST', array( $this, 'create' ), 'leads.edit' );
		$this->route( '/leads/export', 'GET', array( $this, 'export' ), 'leads.view' );
		$this->route( '/leads/import', 'POST', array( $this, 'import' ), 'leads.import' );
		$this->route( '/leads/duplicates', 'GET', array( $this, 'duplicates' ), 'leads.view' );
		$this->route( '/leads/bulk', 'POST', array( $this, 'bulk' ), 'leads.edit' );
		$this->route( '/leads/pipeline', 'GET', array( $this, 'pipeline' ), 'leads.view' );
		$this->route( '/leads/(?P<id>\d+)', 'GET', array( $this, 'show' ), 'leads.view' );
		$this->route( '/leads/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'leads.edit' );
		$this->route( '/leads/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'leads.delete' );
		$this->route( '/leads/(?P<id>\d+)/move', 'POST', array( $this, 'move' ), 'leads.edit' );
		$this->route( '/leads/(?P<id>\d+)/notes', 'POST', array( $this, 'add_note' ), 'leads.edit' );
	}

	/**
	 * Builds WHERE clause from list filters.
	 */
	private function where( $req ) {
		global $wpdb;
		$w = array( $wpdb->prepare( 'l.studio_id=%d', $this->sid() ) );
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like  = $this->like( $q );
			$digit = preg_replace( '/\D/', '', $q );
			$extra = strlen( $digit ) >= 4 ? $wpdb->prepare( ' OR l.phone LIKE %s OR l.whatsapp LIKE %s', '%' . $digit . '%', '%' . $digit . '%' ) : '';
			$w[]   = $wpdb->prepare( '(l.name LIKE %s OR l.email LIKE %s OR l.city LIKE %s OR l.venue LIKE %s OR l.phone LIKE %s' . $extra . ')', $like, $like, $like, $like, $like );
		}
		$enums = array(
			'stage'       => Config::stages(),
			'source'      => Config::sources(),
			'event_type'  => Config::event_types(),
			'priority'    => Config::priorities(),
			'call_status' => Config::call_statuses(),
		);
		foreach ( $enums as $f => $opts ) {
			$v = $req->get_param( $f );
			if ( $v ) {
				$vals = array_values( array_intersect( explode( ',', $v ), array_keys( $opts ) ) );
				if ( $vals ) {
					$w[] = "l.$f IN ('" . implode( "','", array_map( 'esc_sql', $vals ) ) . "')";
				}
			}
		}
		$a = $req->get_param( 'assigned_to' );
		if ( 'none' === $a ) {
			$w[] = 'l.assigned_to IS NULL';
		} elseif ( $a ) {
			$w[] = $wpdb->prepare( 'l.assigned_to=%d', $a );
		}
		foreach ( array( 'event_from' => array( 'l.event_date', '>=' ), 'event_to' => array( 'l.event_date', '<=' ), 'created_from' => array( 'DATE(l.created_at)', '>=' ), 'created_to' => array( 'DATE(l.created_at)', '<=' ) ) as $p => $def ) {
			$d = Validator::date( (string) $req->get_param( $p ) );
			if ( $d ) {
				$w[] = $wpdb->prepare( "{$def[0]} {$def[1]} %s", $d );
			}
		}
		$f = $req->get_param( 'followup' );
		$today = Db::today();
		if ( 'today' === $f ) {
			$w[] = $wpdb->prepare( 'DATE(l.next_followup_at)=%s', $today );
		} elseif ( 'overdue' === $f ) {
			$w[] = $wpdb->prepare( "l.next_followup_at < %s AND l.stage NOT IN ('booked','lost')", Db::now() );
		} elseif ( 'upcoming' === $f ) {
			$w[] = $wpdb->prepare( 'l.next_followup_at >= %s', Db::now() );
		}
		if ( $req->get_param( 'open' ) ) {
			$w[] = "l.stage NOT IN ('booked','lost')";
		}
		return implode( ' AND ', $w );
	}

	private function select() {
		return 'SELECT l.*, m.name AS assigned_name FROM ' . Db::t( 'leads' ) . ' l LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id = l.assigned_to';
	}

	public function index( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$where   = $this->where( $req );
		$sorts   = array( 'created_at', 'name', 'event_date', 'budget', 'next_followup_at', 'stage', 'priority', 'updated_at' );
		$sort    = in_array( $req->get_param( 'sort' ), $sorts, true ) ? $req->get_param( 'sort' ) : 'created_at';
		$dir     = 'asc' === strtolower( (string) $req->get_param( 'dir' ) ) ? 'ASC' : 'DESC';
		$total   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'leads' ) . " l WHERE $where" );
		$items   = $wpdb->get_results( $this->select() . " WHERE $where ORDER BY l.$sort $dir, l.id DESC LIMIT $per OFFSET $off" );
		return $this->ok(
			array(
				'items'    => Db::cast_all( $items ),
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per,
			)
		);
	}

	public function pipeline( $req ) {
		global $wpdb;
		$where = $this->where( $req );
		$rows  = $wpdb->get_results( $this->select() . " WHERE $where ORDER BY l.position ASC, l.updated_at DESC LIMIT 2000" );
		$cols  = array();
		foreach ( Config::stages() as $k => $label ) {
			$cols[ $k ] = array();
		}
		foreach ( Db::cast_all( $rows ) as $r ) {
			$cols[ $r->stage ][] = $r;
		}
		return $this->ok( $cols );
	}

	public static function find_duplicates( $phone, $whatsapp, $email, $exclude = 0 ) {
		global $wpdb;
		$conds = array();
		foreach ( array_filter( array( $phone, $whatsapp ) ) as $p ) {
			$conds[] = $wpdb->prepare( '(phone=%s OR whatsapp=%s)', $p, $p );
		}
		if ( $email ) {
			$conds[] = $wpdb->prepare( 'email=%s', $email );
		}
		if ( ! $conds ) {
			return array();
		}
		return Db::cast_all(
			$wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, name, phone, email, stage, event_date, created_at FROM ' . Db::t( 'leads' ) . ' WHERE studio_id=%d AND id<>%d AND (' . implode( ' OR ', $conds ) . ') ORDER BY id DESC LIMIT 5',
					Tenant::studio_id(),
					(int) $exclude
				)
			)
		);
	}

	public function duplicates( $req ) {
		$phone = $req->get_param( 'phone' ) ? Validator::phone( (string) $req->get_param( 'phone' ) ) : null;
		$email = $req->get_param( 'email' ) ? sanitize_email( $req->get_param( 'email' ) ) : null;
		return $this->ok( self::find_duplicates( $phone, null, $email, (int) $req->get_param( 'exclude' ) ) );
	}

	private function check_dates( $data, $existing = null ) {
		$start = array_key_exists( 'event_date', $data ) ? $data['event_date'] : ( $existing ? $existing->event_date : null );
		$end   = array_key_exists( 'event_end_date', $data ) ? $data['event_end_date'] : ( $existing ? $existing->event_end_date : null );
		if ( $start && $end && $end < $start ) {
			return new \WP_Error( 'validation_failed', 'Event end date cannot be before the event date.', array( 'status' => 422, 'errors' => array( 'event_end_date' => 'Must be on or after the event date.' ) ) );
		}
		return true;
	}

	public function create( $req ) {
		$data = $this->validate( $req, self::schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$chk = $this->check_dates( $data );
		if ( is_wp_error( $chk ) ) {
			return $chk;
		}
		$limit = Plans::check( Tenant::studio(), 'leads' );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$b = $this->body( $req );
		if ( empty( $b['force'] ) ) {
			$dups = self::find_duplicates( $data['phone'], $data['whatsapp'], $data['email'] );
			if ( $dups ) {
				return new \WP_Error( 'duplicate', 'A lead with this phone or email already exists.', array( 'status' => 409, 'duplicates' => $dups ) );
			}
		}
		if ( empty( $data['whatsapp'] ) ) {
			$data['whatsapp'] = $data['phone'];
		}
		$data['created_by'] = get_current_user_id();
		$id                 = self::insert_lead( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return $this->ok( Db::cast( Db::find( 'leads', $id ) ), 201 );
	}

	/**
	 * Inserts a lead, logs it and creates the follow-up if one was given.
	 */
	public static function insert_lead( array $data, $studio_id = null ) {
		$id = Db::insert( 'leads', $data, $studio_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$src = ! empty( $data['source'] ) ? Config::sources()[ $data['source'] ] : 'manual entry';
		Db::log( 'created', 'Lead created from ' . $src, array( 'lead_id' => $id ), array(), $studio_id );
		if ( ! empty( $data['next_followup_at'] ) ) {
			Db::insert(
				'followups',
				array(
					'lead_id'     => $id,
					'assigned_to' => isset( $data['assigned_to'] ) ? $data['assigned_to'] : null,
					'due_at'      => $data['next_followup_at'],
					'note'        => 'First follow-up',
					'status'      => 'pending',
					'created_by'  => get_current_user_id(),
				),
				$studio_id
			);
		}
		return $id;
	}

	public function show( $req ) {
		global $wpdb;
		$id   = (int) $req['id'];
		$lead = $wpdb->get_row( $wpdb->prepare( $this->select() . ' WHERE l.id=%d AND l.studio_id=%d', $id, $this->sid() ) );
		if ( ! $lead ) {
			return $this->not_found( 'Lead' );
		}
		$sid  = $this->sid();
		$acts = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, u.display_name AS user_name FROM ' . Db::t( 'activities' ) . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID=a.user_id WHERE a.studio_id=%d AND a.lead_id=%d ORDER BY a.created_at DESC, a.id DESC LIMIT 200', $sid, $id ) );
		$calls = $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, m.name AS member_name FROM ' . Db::t( 'calls' ) . ' c LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id=c.member_id WHERE c.studio_id=%d AND c.lead_id=%d ORDER BY c.called_at DESC', $sid, $id ) );
		$fups  = $wpdb->get_results( $wpdb->prepare( 'SELECT f.*, m.name AS assigned_name FROM ' . Db::t( 'followups' ) . ' f LEFT JOIN ' . Db::t( 'members' ) . ' m ON m.id=f.assigned_to WHERE f.studio_id=%d AND f.lead_id=%d ORDER BY f.due_at DESC', $sid, $id ) );
		$quotes = $wpdb->get_results( $wpdb->prepare( 'SELECT id, number, title, status, total, created_at FROM ' . Db::t( 'quotations' ) . ' WHERE studio_id=%d AND lead_id=%d ORDER BY id DESC', $sid, $id ) );
		$bookings = $wpdb->get_results( $wpdb->prepare( 'SELECT id, number, title, status, total_amount FROM ' . Db::t( 'bookings' ) . ' WHERE studio_id=%d AND lead_id=%d ORDER BY id DESC', $sid, $id ) );
		foreach ( $acts as $a ) {
			$a->meta = $a->meta ? json_decode( $a->meta, true ) : null;
		}
		$lead             = Db::cast( $lead );
		$lead->activities = Db::cast_all( $acts );
		$lead->calls      = Db::cast_all( $calls );
		$lead->followups  = Db::cast_all( $fups );
		$lead->quotations = Db::cast_all( $quotes );
		$lead->bookings   = Db::cast_all( $bookings );
		$lead->duplicates = self::find_duplicates( $lead->phone, $lead->whatsapp, $lead->email, $lead->id );
		return $this->ok( $lead );
	}

	public function update( $req ) {
		$id       = (int) $req['id'];
		$existing = Db::find( 'leads', $id );
		if ( ! $existing ) {
			return $this->not_found( 'Lead' );
		}
		$data = $this->validate( $req, self::schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$chk = $this->check_dates( $data, $existing );
		if ( is_wp_error( $chk ) ) {
			return $chk;
		}
		$r = self::apply_update( $existing, $data );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return $this->ok( Db::cast( Db::find( 'leads', $id ) ) );
	}

	/**
	 * Updates a lead and writes timeline entries for meaningful changes.
	 */
	public static function apply_update( $existing, array $data ) {
		$r = Db::update( 'leads', $existing->id, $data );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$refs = array( 'lead_id' => (int) $existing->id );
		if ( isset( $data['stage'] ) && $data['stage'] !== $existing->stage ) {
			$st  = Config::stages();
			$msg = 'Stage changed from ' . $st[ $existing->stage ] . ' to ' . $st[ $data['stage'] ];
			if ( 'lost' === $data['stage'] && ! empty( $data['lost_reason'] ) ) {
				$msg .= ' (reason: ' . $data['lost_reason'] . ')';
			}
			Db::log( 'stage', $msg, $refs, array( 'from' => $existing->stage, 'to' => $data['stage'] ) );
		}
		if ( array_key_exists( 'assigned_to', $data ) && (int) $data['assigned_to'] !== (int) $existing->assigned_to ) {
			$m = $data['assigned_to'] ? Db::find( 'members', $data['assigned_to'] ) : null;
			Db::log( 'assigned', $m ? 'Assigned to ' . $m->name : 'Unassigned', $refs );
		}
		if ( isset( $data['priority'] ) && $data['priority'] !== $existing->priority ) {
			Db::log( 'updated', 'Priority set to ' . Config::priorities()[ $data['priority'] ], $refs );
		}
		if ( array_key_exists( 'next_followup_at', $data ) && $data['next_followup_at'] && $data['next_followup_at'] !== $existing->next_followup_at ) {
			global $wpdb;
			$pending = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'followups' ) . " WHERE studio_id=%d AND lead_id=%d AND status='pending' ORDER BY due_at LIMIT 1", Tenant::studio_id(), $existing->id ) );
			if ( $pending ) {
				Db::update( 'followups', $pending, array( 'due_at' => $data['next_followup_at'] ) );
			} else {
				Db::insert( 'followups', array( 'lead_id' => $existing->id, 'assigned_to' => $existing->assigned_to, 'due_at' => $data['next_followup_at'], 'status' => 'pending', 'created_by' => get_current_user_id() ) );
			}
			Db::log( 'followup', 'Follow-up scheduled for ' . mysql2date( 'd-m-Y h:i A', $data['next_followup_at'] ), $refs );
		}
		return true;
	}

	public function move( $req ) {
		global $wpdb;
		$id       = (int) $req['id'];
		$existing = Db::find( 'leads', $id );
		if ( ! $existing ) {
			return $this->not_found( 'Lead' );
		}
		$b     = $this->body( $req );
		$stage = isset( $b['stage'] ) ? (string) $b['stage'] : '';
		if ( ! array_key_exists( $stage, Config::stages() ) ) {
			return new \WP_Error( 'validation_failed', 'Invalid stage.', array( 'status' => 422 ) );
		}
		$data = array( 'stage' => $stage );
		if ( 'lost' === $stage && ! empty( $b['lost_reason'] ) ) {
			$data['lost_reason'] = sanitize_text_field( $b['lost_reason'] );
		}
		self::apply_update( $existing, $data );
		if ( ! empty( $b['order'] ) && is_array( $b['order'] ) ) {
			foreach ( array_values( $b['order'] ) as $pos => $lid ) {
				$wpdb->update( Db::t( 'leads' ), array( 'position' => $pos ), array( 'id' => (int) $lid, 'studio_id' => $this->sid(), 'stage' => $stage ) );
			}
		}
		return $this->ok( Db::cast( Db::find( 'leads', $id ) ) );
	}

	public function add_note( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'leads', $id ) ) {
			return $this->not_found( 'Lead' );
		}
		$data = $this->validate( $req, array( 'message' => array( 'type' => 'text', 'required' => true, 'label' => 'Note' ) ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$aid = Db::log( 'note', $data['message'], array( 'lead_id' => $id ) );
		Db::update( 'leads', $id, array() );
		return $this->ok( array( 'id' => $aid ), 201 );
	}

	public static function delete_lead( $id ) {
		$sid = Tenant::studio_id();
		Db::delete_where( 'activities', array( 'lead_id' => $id ) );
		Db::delete_where( 'calls', array( 'lead_id' => $id ) );
		Db::delete_where( 'followups', array( 'lead_id' => $id ) );
		global $wpdb;
		$wpdb->update( Db::t( 'tasks' ), array( 'lead_id' => null ), array( 'lead_id' => $id, 'studio_id' => $sid ) );
		$wpdb->update( Db::t( 'quotations' ), array( 'lead_id' => null ), array( 'lead_id' => $id, 'studio_id' => $sid ) );
		$wpdb->update( Db::t( 'bookings' ), array( 'lead_id' => null ), array( 'lead_id' => $id, 'studio_id' => $sid ) );
		return Db::delete( 'leads', $id );
	}

	public function destroy( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'leads', $id ) ) {
			return $this->not_found( 'Lead' );
		}
		Db::begin();
		self::delete_lead( $id );
		Db::commit();
		return $this->ok( array( 'deleted' => true ) );
	}

	public function bulk( $req ) {
		$b      = $this->body( $req );
		$ids    = isset( $b['ids'] ) && is_array( $b['ids'] ) ? array_map( 'intval', $b['ids'] ) : array();
		$action = isset( $b['action'] ) ? $b['action'] : '';
		if ( ! $ids ) {
			return new \WP_Error( 'validation_failed', 'Select at least one lead.', array( 'status' => 422 ) );
		}
		$n = 0;
		if ( 'delete' === $action ) {
			if ( ! Tenant::can( 'leads.delete' ) ) {
				return new \WP_Error( 'forbidden', 'You do not have permission to delete leads.', array( 'status' => 403 ) );
			}
			Db::begin();
			foreach ( $ids as $id ) {
				if ( Db::exists( 'leads', $id ) ) {
					self::delete_lead( $id );
					$n++;
				}
			}
			Db::commit();
		} elseif ( in_array( $action, array( 'stage', 'assign', 'priority' ), true ) ) {
			$field = array( 'stage' => 'stage', 'assign' => 'assigned_to', 'priority' => 'priority' )[ $action ];
			$data  = Validator::validate( array( $field => isset( $b['value'] ) ? $b['value'] : null ), array( $field => self::schema()[ $field ] ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( $ids as $id ) {
				$l = Db::find( 'leads', $id );
				if ( $l ) {
					self::apply_update( $l, $data );
					$n++;
				}
			}
		} else {
			return new \WP_Error( 'validation_failed', 'Unknown bulk action.', array( 'status' => 422 ) );
		}
		return $this->ok( array( 'updated' => $n ) );
	}

	/**
	 * Maps a free-text label ("Facebook Ads", "fb") to an option key.
	 */
	private static function match_option( $value, array $options ) {
		$v = strtolower( trim( (string) $value ) );
		if ( '' === $v ) {
			return null;
		}
		foreach ( $options as $k => $label ) {
			if ( $v === $k || $v === strtolower( $label ) ) {
				return $k;
			}
		}
		foreach ( $options as $k => $label ) {
			if ( strpos( strtolower( $label ), $v ) !== false || strpos( $v, strtolower( strtok( $label, ' ' ) ) ) !== false ) {
				return $k;
			}
		}
		return null;
	}

	public function import( $req ) {
		$b    = $this->body( $req );
		$rows = isset( $b['rows'] ) && is_array( $b['rows'] ) ? $b['rows'] : array();
		if ( ! $rows ) {
			return new \WP_Error( 'validation_failed', 'The file has no rows.', array( 'status' => 422 ) );
		}
		if ( count( $rows ) > 5000 ) {
			return new \WP_Error( 'validation_failed', 'Please import at most 5,000 rows at a time.', array( 'status' => 422 ) );
		}
		$skip_dups = ! isset( $b['skip_duplicates'] ) || $b['skip_duplicates'];
		$schema    = self::schema();
		unset( $schema['assigned_to'] );
		$created = 0;
		$skipped = 0;
		$errors  = array();
		$studio  = Tenant::studio();
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row['source']     = self::match_option( isset( $row['source'] ) ? $row['source'] : '', Config::sources() );
			$row['event_type'] = self::match_option( isset( $row['event_type'] ) ? $row['event_type'] : '', Config::event_types() );
			$row['stage']      = self::match_option( isset( $row['stage'] ) ? $row['stage'] : '', Config::stages() );
			$row['priority']   = self::match_option( isset( $row['priority'] ) ? $row['priority'] : '', Config::priorities() );
			if ( isset( $row['budget'] ) ) {
				$row['budget'] = preg_replace( '/[^\d.]/', '', (string) $row['budget'] );
			}
			$data = Validator::validate( $row, $schema );
			if ( is_wp_error( $data ) ) {
				$errors[] = array( 'row' => $i + 2, 'message' => $data->get_error_message() );
				continue;
			}
			if ( $skip_dups && self::find_duplicates( $data['phone'], $data['whatsapp'], $data['email'] ) ) {
				$skipped++;
				continue;
			}
			$limit = Plans::check( $studio, 'leads' );
			if ( is_wp_error( $limit ) ) {
				$errors[] = array( 'row' => $i + 2, 'message' => $limit->get_error_message() );
				break;
			}
			if ( empty( $data['whatsapp'] ) ) {
				$data['whatsapp'] = $data['phone'];
			}
			$data['created_by'] = get_current_user_id();
			$id                 = self::insert_lead( $data );
			if ( is_wp_error( $id ) ) {
				$errors[] = array( 'row' => $i + 2, 'message' => $id->get_error_message() );
			} else {
				$created++;
			}
		}
		return $this->ok(
			array(
				'created' => $created,
				'skipped' => $skipped,
				'errors'  => array_slice( $errors, 0, 100 ),
			)
		);
	}

	public function export( $req ) {
		global $wpdb;
		$where = $this->where( $req );
		$rows  = $wpdb->get_results( $this->select() . " WHERE $where ORDER BY l.created_at DESC LIMIT 20000" );
		$cfg   = Config::all();
		$fmt   = function ( $d, $time = false ) {
			return $d ? mysql2date( $time ? 'd-m-Y h:i A' : 'd-m-Y', $d ) : '';
		};
		$out = fopen( 'php://temp', 'w+' );
		fputcsv( $out, array( 'Client Name', 'Phone', 'WhatsApp', 'Email', 'Event Type', 'Event Date', 'Event End Date', 'Event Duration', 'Venue', 'City', 'Lead Source', 'Assigned To', 'Estimated Budget', 'Lead Status', 'Priority', 'Call Status', 'Last Call', 'Next Follow-up', 'Notes', 'Created Date' ) );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r->name, $r->phone, $r->whatsapp, $r->email,
					$r->event_type ? $cfg['event_types'][ $r->event_type ] : '',
					$fmt( $r->event_date ), $fmt( $r->event_end_date ), $r->event_duration, $r->venue, $r->city,
					$r->source ? $cfg['sources'][ $r->source ] : '',
					$r->assigned_name, $r->budget,
					$cfg['stages'][ $r->stage ], $cfg['priorities'][ $r->priority ], $cfg['call_statuses'][ $r->call_status ],
					$fmt( $r->last_call_at, true ), $fmt( $r->next_followup_at, true ), $r->notes, $fmt( $r->created_at, true ),
				)
			);
		}
		rewind( $out );
		$csv = "\xEF\xBB\xBF" . stream_get_contents( $out );
		fclose( $out );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="leads-' . Db::date( 'd-m-Y' ) . '.csv"' );
		echo $csv; // phpcs:ignore
		exit;
	}
}
