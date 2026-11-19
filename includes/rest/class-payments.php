<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Pdf;
use PCRM\Tenant;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

class Payments extends Base {

	public static function schema() {
		return array(
			'amount'          => array( 'type' => 'money', 'required' => true, 'min' => 1, 'label' => 'Amount' ),
			'paid_on'         => array( 'type' => 'date', 'required' => true, 'label' => 'Payment date' ),
			'method'          => array( 'type' => 'enum', 'options' => Config::payment_methods(), 'required' => true, 'label' => 'Payment method' ),
			'transaction_ref' => array( 'type' => 'string', 'max' => 100, 'label' => 'Transaction ID' ),
			'installment_id'  => array( 'type' => 'ref', 'table' => 'installments', 'label' => 'Installment' ),
			'payment_type'    => array( 'type' => 'enum', 'options' => Config::payment_types(), 'label' => 'Payment type' ),
			'status'          => array( 'type' => 'enum', 'options' => Config::payment_statuses(), 'default' => 'received', 'label' => 'Payment status' ),
			'notes'           => array( 'type' => 'text' ),
		);
	}

	/** SQL condition for payments that count as money received. */
	public static function received_sql( $alias = '' ) {
		return ( $alias ? $alias . '.' : '' ) . "status='received'";
	}

	public function register() {
		$this->route( '/payments', 'GET', array( $this, 'index' ), 'payments.view' );
		$this->route( '/payments', 'POST', array( $this, 'create_any' ), 'payments.edit' );
		$this->route( '/payments/dues', 'GET', array( $this, 'dues' ), 'payments.view' );
		$this->route( '/payments/summary', 'GET', array( $this, 'summary' ), 'payments.view' );
		$this->route( '/payments/activity', 'GET', array( $this, 'activity' ), 'payments.view' );
		$this->route( '/bookings/(?P<id>\d+)/payments', 'POST', array( $this, 'create' ), 'payments.edit' );
		$this->route( '/payments/(?P<id>\d+)', 'GET', array( $this, 'show' ), 'payments.view' );
		$this->route( '/payments/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'payments.edit' );
		$this->route( '/payments/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'payments.edit' );
		$this->route( '/payments/(?P<id>\d+)/receipt', 'GET', array( $this, 'receipt' ), 'payments.view' );
	}

	/**
	 * Second, explicit check for every payment change: owner or admin only.
	 */
	private function admin_only() {
		if ( ! \PCRM\Permissions::is_admin( Tenant::member() ) ) {
			return new \WP_Error( 'forbidden', 'Only the studio owner or an admin can change payment records.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Writes one row to the payment audit log.
	 */
	public static function audit( $action, $payment, $summary, array $changes = array() ) {
		$uid  = get_current_user_id();
		$user = $uid ? get_userdata( $uid ) : null;
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
		return Db::insert(
			'payment_activity_logs',
			array(
				'payment_id' => (int) $payment->id,
				'booking_id' => (int) $payment->booking_id,
				'action'     => $action,
				'summary'    => mb_substr( $summary, 0, 255 ),
				'changes'    => $changes ? wp_json_encode( $changes ) : null,
				'snapshot'   => wp_json_encode( $payment ),
				'user_id'    => $uid ? $uid : null,
				'user_name'  => $user ? $user->display_name : 'System',
				'ip'         => $ip ? substr( $ip, 0, 64 ) : null,
			),
			isset( $payment->studio_id ) ? (int) $payment->studio_id : null
		);
	}

	/**
	 * Picks Advance / Installment / Final when the admin leaves it empty.
	 */
	private static function auto_type( $summary, $amount, $status ) {
		if ( $summary['paid'] <= 0.009 ) {
			return ( 'received' === $status && $amount >= $summary['pending'] - 0.009 && $summary['pending'] > 0 && $summary['total'] > 0 && $amount >= $summary['total'] - 0.009 ) ? 'final' : 'advance';
		}
		return ( 'received' === $status && $amount >= $summary['pending'] - 0.009 ) ? 'final' : 'installment';
	}

	/**
	 * Records a payment against a booking (used by booking create too).
	 */
	public static function record( $booking_id, array $data ) {
		$bk = Db::find( 'bookings', $booking_id );
		if ( ! $bk ) {
			return new \WP_Error( 'not_found', 'Booking not found.', array( 'status' => 404 ) );
		}
		if ( 'cancelled' === $bk->status ) {
			return new \WP_Error( 'validation_failed', 'This booking is cancelled.', array( 'status' => 422 ) );
		}
		if ( ! empty( $data['installment_id'] ) ) {
			$in = Db::find( 'installments', $data['installment_id'] );
			if ( ! $in || (int) $in->booking_id !== (int) $booking_id ) {
				return new \WP_Error( 'validation_failed', 'That installment belongs to another booking.', array( 'status' => 422 ) );
			}
		}
		$status = isset( $data['status'] ) && $data['status'] ? $data['status'] : 'received';
		$sum    = Bookings::payment_summary( $bk );
		if ( in_array( $status, array( 'received', 'pending' ), true ) && $data['amount'] - $sum['pending'] > 0.009 ) {
			return new \WP_Error( 'validation_failed', 'Amount is more than the pending balance of ₹' . \PCRM\Format::inr( $sum['pending'] ) . '.', array( 'status' => 422, 'errors' => array( 'amount' => 'More than pending balance.' ) ) );
		}
		if ( $data['paid_on'] > Db::today() ) {
			return new \WP_Error( 'validation_failed', 'Payment date cannot be in the future.', array( 'status' => 422, 'errors' => array( 'paid_on' => 'Cannot be in the future.' ) ) );
		}
		$data['status']       = $status;
		$data['payment_type'] = ! empty( $data['payment_type'] ) ? $data['payment_type'] : self::auto_type( $sum, $data['amount'], $status );
		$data['booking_id']   = (int) $booking_id;
		$data['receipt_no']   = Db::next_number( 'payments', Tenant::setting( 'receipt_prefix' ) ? Tenant::setting( 'receipt_prefix' ) : 'RC' );
		$data['created_by']   = get_current_user_id() ? get_current_user_id() : null;
		$id                   = Db::insert( 'payments', $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$methods = Config::payment_methods();
		$label   = 'received' === $status ? 'received' : 'recorded as ' . strtolower( Config::payment_statuses()[ $status ] );
		Db::log( 'payment', 'Payment of ₹' . \PCRM\Format::inr( $data['amount'] ) . ' ' . $label . ' via ' . $methods[ $data['method'] ] . ' (receipt ' . $data['receipt_no'] . ')', array( 'booking_id' => (int) $booking_id, 'client_id' => (int) $bk->client_id ), array( 'payment_id' => $id ) );
		self::audit( 'created', Db::cast( Db::find( 'payments', $id ) ), 'Payment ' . $data['receipt_no'] . ' of ₹' . \PCRM\Format::inr( $data['amount'] ) . ' added (' . Config::payment_types()[ $data['payment_type'] ] . ', ' . Config::payment_statuses()[ $status ] . ')' );
		return $id;
	}

	public function create( $req ) {
		$ok = $this->admin_only();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$data = $this->validate( $req, self::schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		Db::begin();
		$id = self::record( (int) $req['id'], $data );
		if ( is_wp_error( $id ) ) {
			Db::rollback();
			return $id;
		}
		Db::commit();
		return $this->ok( self::load( $id ), 201 );
	}

	/**
	 * Add payment from the Payments page, where the admin picks the booking.
	 */
	public function create_any( $req ) {
		$b   = $this->body( $req );
		$bid = isset( $b['booking_id'] ) ? (int) $b['booking_id'] : 0;
		if ( ! $bid || ! Db::exists( 'bookings', $bid ) ) {
			return new \WP_Error( 'validation_failed', 'Choose the booking this payment is for.', array( 'status' => 422, 'errors' => array( 'booking_id' => 'Booking is required.' ) ) );
		}
		$req->set_param( 'id', $bid );
		return $this->create( $req );
	}

	/**
	 * One payment with client, booking, package total and who created/changed it.
	 */
	public static function load( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT p.*, b.number AS booking_number, b.title AS booking_title, b.total_amount AS package_amount, c.name AS client_name, i.label AS installment_label, cu.display_name AS created_by_name, uu.display_name AS updated_by_name
				FROM ' . Db::t( 'payments' ) . ' p
				JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=p.booking_id
				JOIN ' . Db::t( 'clients' ) . ' c ON c.id=b.client_id
				LEFT JOIN ' . Db::t( 'installments' ) . ' i ON i.id=p.installment_id
				LEFT JOIN ' . $wpdb->users . ' cu ON cu.ID=p.created_by
				LEFT JOIN ' . $wpdb->users . ' uu ON uu.ID=p.updated_by
				WHERE p.id=%d AND p.studio_id=%d',
				$id,
				Tenant::studio_id()
			)
		);
		if ( $row ) {
			$row->package_amount = (float) $row->package_amount;
		}
		return Db::cast( $row );
	}

	public function show( $req ) {
		$p = self::load( (int) $req['id'] );
		if ( ! $p ) {
			return $this->not_found( 'Payment' );
		}
		$p->logs = $this->logs_for( array( 'payment_id' => (int) $p->id ) );
		return $this->ok( $p );
	}

	private function logs_for( array $where, $limit = 200, $offset = 0 ) {
		global $wpdb;
		$w = array( $wpdb->prepare( 'l.studio_id=%d', $this->sid() ) );
		if ( ! empty( $where['payment_id'] ) ) {
			$w[] = $wpdb->prepare( 'l.payment_id=%d', $where['payment_id'] );
		}
		if ( ! empty( $where['booking_id'] ) ) {
			$w[] = $wpdb->prepare( 'l.booking_id=%d', $where['booking_id'] );
		}
		if ( ! empty( $where['action'] ) ) {
			$w[] = $wpdb->prepare( 'l.action=%s', $where['action'] );
		}
		$rows = $wpdb->get_results( 'SELECT l.id, l.payment_id, l.booking_id, l.action, l.summary, l.changes, l.user_id, l.user_name, l.created_at, b.number AS booking_number, c.name AS client_name FROM ' . Db::t( 'payment_activity_logs' ) . ' l LEFT JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=l.booking_id LEFT JOIN ' . Db::t( 'clients' ) . ' c ON c.id=b.client_id WHERE ' . implode( ' AND ', $w ) . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset );
		foreach ( $rows as $r ) {
			$r->changes = $r->changes ? json_decode( $r->changes, true ) : null;
		}
		return Db::cast_all( $rows );
	}

	/** Studio-wide payment audit log. */
	public function activity( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$where = array(
			'booking_id' => (int) $req->get_param( 'booking_id' ),
			'action'     => in_array( $req->get_param( 'action' ), array( 'created', 'updated', 'deleted' ), true ) ? $req->get_param( 'action' ) : '',
		);
		$w = $wpdb->prepare( 'studio_id=%d', $this->sid() ) . ( $where['booking_id'] ? $wpdb->prepare( ' AND booking_id=%d', $where['booking_id'] ) : '' ) . ( $where['action'] ? $wpdb->prepare( ' AND action=%s', $where['action'] ) : '' );
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'payment_activity_logs' ) . " WHERE $w" );
		return $this->ok( array( 'items' => $this->logs_for( $where, $per, $off ), 'total' => $total, 'page' => $page, 'per_page' => $per ) );
	}

	/**
	 * Edits a payment (owner/admin only). Every changed field is written to the
	 * audit log with its old and new value.
	 */
	public function update( $req ) {
		$ok = $this->admin_only();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$p = Db::find( 'payments', (int) $req['id'] );
		if ( ! $p ) {
			return $this->not_found( 'Payment' );
		}
		$data = $this->validate( $req, self::schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( array_key_exists( 'status', $data ) && ! $data['status'] ) {
			$data['status'] = 'received';
		}
		if ( array_key_exists( 'payment_type', $data ) && ! $data['payment_type'] ) {
			unset( $data['payment_type'] );
		}
		foreach ( array( 'amount', 'paid_on', 'method' ) as $req_field ) {
			if ( array_key_exists( $req_field, $data ) && ( null === $data[ $req_field ] || '' === $data[ $req_field ] ) ) {
				return new \WP_Error( 'validation_failed', ucfirst( str_replace( '_', ' ', $req_field ) ) . ' is required.', array( 'status' => 422 ) );
			}
		}
		$bk = Db::find( 'bookings', (int) $p->booking_id );
		if ( ! empty( $data['installment_id'] ) ) {
			$in = Db::find( 'installments', $data['installment_id'] );
			if ( ! $in || (int) $in->booking_id !== (int) $p->booking_id ) {
				return new \WP_Error( 'validation_failed', 'That installment belongs to another booking.', array( 'status' => 422 ) );
			}
		}
		if ( isset( $data['paid_on'] ) && $data['paid_on'] > Db::today() ) {
			return new \WP_Error( 'validation_failed', 'Payment date cannot be in the future.', array( 'status' => 422, 'errors' => array( 'paid_on' => 'Cannot be in the future.' ) ) );
		}
		$new_amount = isset( $data['amount'] ) ? (float) $data['amount'] : (float) $p->amount;
		$new_status = isset( $data['status'] ) ? $data['status'] : $p->status;
		if ( in_array( $new_status, array( 'received', 'pending' ), true ) ) {
			$sum       = Bookings::payment_summary( $bk );
			$available = $sum['pending'] + ( 'received' === $p->status ? (float) $p->amount : 0 );
			if ( $new_amount - $available > 0.009 ) {
				return new \WP_Error( 'validation_failed', 'Amount is more than the balance of ₹' . \PCRM\Format::inr( $available ) . ' on this booking.', array( 'status' => 422, 'errors' => array( 'amount' => 'More than the booking balance.' ) ) );
			}
		}
		$labels  = array( 'amount' => 'Amount', 'paid_on' => 'Payment date', 'method' => 'Method', 'transaction_ref' => 'Transaction ID', 'installment_id' => 'Installment', 'payment_type' => 'Payment type', 'status' => 'Status', 'notes' => 'Notes' );
		$changes = array();
		foreach ( $data as $k => $v ) {
			$old = $p->$k;
			$same = in_array( $k, array( 'amount' ), true ) ? abs( (float) $old - (float) $v ) < 0.005 : ( (string) $old === (string) $v );
			if ( ! $same ) {
				$changes[ $k ] = array( 'label' => $labels[ $k ], 'from' => self::readable( $k, $old ), 'to' => self::readable( $k, $v ) );
			}
		}
		if ( ! $changes ) {
			return $this->ok( self::load( $p->id ) );
		}
		$data['updated_by'] = get_current_user_id();
		Db::begin();
		$r = Db::update( 'payments', $p->id, $data );
		if ( is_wp_error( $r ) ) {
			Db::rollback();
			return $r;
		}
		$fresh = Db::cast( Db::find( 'payments', $p->id ) );
		$parts = array();
		foreach ( $changes as $c ) {
			$parts[] = $c['label'] . ' ' . ( '' === $c['from'] ? '—' : $c['from'] ) . ' → ' . ( '' === $c['to'] ? '—' : $c['to'] );
		}
		self::audit( 'updated', $fresh, 'Payment ' . $p->receipt_no . ' edited: ' . implode( '; ', $parts ), $changes );
		Db::log( 'payment', 'Payment ' . $p->receipt_no . ' edited: ' . implode( '; ', $parts ), array( 'booking_id' => (int) $p->booking_id, 'client_id' => (int) $bk->client_id ), array( 'payment_id' => (int) $p->id ) );
		Db::commit();
		return $this->ok( self::load( $p->id ) );
	}

	private static function readable( $field, $v ) {
		if ( null === $v || '' === $v ) {
			return '';
		}
		switch ( $field ) {
			case 'amount':
				return '₹' . \PCRM\Format::inr( $v );
			case 'paid_on':
				return \PCRM\Format::date( $v );
			case 'method':
				$m = Config::payment_methods();
				return isset( $m[ $v ] ) ? $m[ $v ] : $v;
			case 'payment_type':
				$m = Config::payment_types();
				return isset( $m[ $v ] ) ? $m[ $v ] : $v;
			case 'status':
				$m = Config::payment_statuses();
				return isset( $m[ $v ] ) ? $m[ $v ] : $v;
			case 'installment_id':
				$i = Db::find( 'installments', (int) $v );
				return $i ? $i->label : '#' . $v;
			case 'notes':
				return wp_trim_words( (string) $v, 12 );
		}
		return (string) $v;
	}

	public function index( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$w = array( $wpdb->prepare( 'p.studio_id=%d', $this->sid() ) );
		$from = Validator::date( (string) $req->get_param( 'from' ) );
		$to   = Validator::date( (string) $req->get_param( 'to' ) );
		if ( $from ) {
			$w[] = $wpdb->prepare( 'p.paid_on >= %s', $from );
		}
		if ( $to ) {
			$w[] = $wpdb->prepare( 'p.paid_on <= %s', $to );
		}
		if ( $req->get_param( 'method' ) && array_key_exists( $req->get_param( 'method' ), Config::payment_methods() ) ) {
			$w[] = $wpdb->prepare( 'p.method=%s', $req->get_param( 'method' ) );
		}
		if ( $req->get_param( 'booking_id' ) ) {
			$w[] = $wpdb->prepare( 'p.booking_id=%d', $req->get_param( 'booking_id' ) );
		}
		if ( $req->get_param( 'status' ) && array_key_exists( $req->get_param( 'status' ), Config::payment_statuses() ) ) {
			$w[] = $wpdb->prepare( 'p.status=%s', $req->get_param( 'status' ) );
		}
		if ( $req->get_param( 'payment_type' ) && array_key_exists( $req->get_param( 'payment_type' ), Config::payment_types() ) ) {
			$w[] = $wpdb->prepare( 'p.payment_type=%s', $req->get_param( 'payment_type' ) );
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like = $this->like( $q );
			$w[]  = $wpdb->prepare( '(p.receipt_no LIKE %s OR p.transaction_ref LIKE %s OR c.name LIKE %s OR b.number LIKE %s)', $like, $like, $like, $like );
		}
		$where = implode( ' AND ', $w );
		$join  = ' JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=p.booking_id JOIN ' . Db::t( 'clients' ) . ' c ON c.id=b.client_id';
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'payments' ) . " p $join WHERE $where" );
		$sum   = (float) $wpdb->get_var( 'SELECT COALESCE(SUM(p.amount),0) FROM ' . Db::t( 'payments' ) . " p $join WHERE $where AND p.status='received'" );
		$rows  = $wpdb->get_results( 'SELECT p.*, b.number AS booking_number, b.title AS booking_title, b.total_amount AS package_amount, c.name AS client_name, i.label AS installment_label, cu.display_name AS created_by_name, uu.display_name AS updated_by_name FROM ' . Db::t( 'payments' ) . " p $join LEFT JOIN " . Db::t( 'installments' ) . " i ON i.id=p.installment_id LEFT JOIN {$wpdb->users} cu ON cu.ID=p.created_by LEFT JOIN {$wpdb->users} uu ON uu.ID=p.updated_by WHERE $where ORDER BY p.paid_on DESC, p.id DESC LIMIT $per OFFSET $off" );
		foreach ( $rows as $r ) {
			$r->package_amount = (float) $r->package_amount;
		}
		return $this->ok( array( 'items' => Db::cast_all( $rows ), 'total' => $total, 'sum' => $sum, 'page' => $page, 'per_page' => $per ) );
	}

	/**
	 * All bookings with their payment status (for the dues tracker).
	 */
	public static function all_summaries( $studio_id = null ) {
		global $wpdb;
		$sid  = Db::sid( $studio_id );
		$bks  = $wpdb->get_results( $wpdb->prepare( 'SELECT b.id, b.studio_id, b.number, b.title, b.status, b.total_amount, b.client_id, c.name AS client_name, c.phone AS client_phone, c.whatsapp AS client_whatsapp, (SELECT MIN(event_date) FROM ' . Db::t( 'booking_events' ) . ' e WHERE e.booking_id=b.id AND e.studio_id=b.studio_id) AS first_date FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'clients' ) . " c ON c.id=b.client_id WHERE b.studio_id=%d AND b.status<>'cancelled'", $sid ) );
		$paid = $wpdb->get_results( $wpdb->prepare( 'SELECT booking_id, SUM(amount) s FROM ' . Db::t( 'payments' ) . " WHERE studio_id=%d AND status='received' GROUP BY booking_id", $sid ), OBJECT_K );
		$ins  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'installments' ) . ' WHERE studio_id=%d ORDER BY sort, due_date, id', $sid ) );
		$by   = array();
		foreach ( Db::cast_all( $ins ) as $i ) {
			$by[ $i->booking_id ][] = $i;
		}
		$out = array();
		foreach ( $bks as $b ) {
			$s            = Bookings::payment_summary( $b, isset( $by[ $b->id ] ) ? $by[ $b->id ] : array(), isset( $paid[ $b->id ] ) ? (float) $paid[ $b->id ]->s : 0 );
			$b            = Db::cast( $b );
			$b->paid      = $s['paid'];
			$b->pending   = $s['pending'];
			$b->payment_status = $s['status'];
			$b->next_due  = $s['next_due'];
			$b->installments = $s['installments'];
			$out[]        = $b;
		}
		return $out;
	}

	public function dues( $req ) {
		$status = $req->get_param( 'status' );
		$q      = strtolower( trim( (string) $req->get_param( 'q' ) ) );
		$rows   = array_values(
			array_filter(
				self::all_summaries(),
				function ( $b ) use ( $status, $q ) {
					if ( $status && 'all' !== $status && $b->payment_status !== $status ) {
						return false;
					}
					if ( ! $status && 'paid' === $b->payment_status ) {
						return false;
					}
					if ( $q && strpos( strtolower( $b->client_name . ' ' . $b->number . ' ' . $b->title ), $q ) === false ) {
						return false;
					}
					return true;
				}
			)
		);
		usort(
			$rows,
			function ( $a, $b ) {
				$da = $a->next_due && $a->next_due['due_date'] ? $a->next_due['due_date'] : '9999';
				$db = $b->next_due && $b->next_due['due_date'] ? $b->next_due['due_date'] : '9999';
				return strcmp( $da, $db );
			}
		);
		return $this->ok( $rows );
	}

	public function summary() {
		global $wpdb;
		$sid   = $this->sid();
		$all   = self::all_summaries();
		$month = Db::date( 'Y-m-01' );
		$res   = array(
			'collected_month' => (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'payments' ) . " WHERE studio_id=%d AND status='received' AND paid_on >= %s", $sid, $month ) ),
			'collected_total' => (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'payments' ) . " WHERE studio_id=%d AND status='received'", $sid ) ),
			'unconfirmed'     => (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'payments' ) . " WHERE studio_id=%d AND status='pending'", $sid ) ),
			'pending'         => 0,
			'overdue'         => 0,
			'overdue_count'   => 0,
			'expenses_month'  => (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'expenses' ) . ' WHERE studio_id=%d AND spent_on >= %s', $sid, $month ) ),
			'counts'          => array( 'pending' => 0, 'partial' => 0, 'paid' => 0, 'overdue' => 0 ),
		);
		foreach ( $all as $b ) {
			$res['pending'] += $b->pending;
			$res['counts'][ $b->payment_status ]++;
			if ( 'overdue' === $b->payment_status ) {
				$res['overdue_count']++;
				foreach ( $b->installments as $i ) {
					if ( 'overdue' === $i->status ) {
						$res['overdue'] += $i->balance;
					}
				}
			}
		}
		return $this->ok( $res );
	}

	public function destroy( $req ) {
		$ok = $this->admin_only();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$p = Db::find( 'payments', (int) $req['id'] );
		if ( ! $p ) {
			return $this->not_found( 'Payment' );
		}
		global $wpdb;
		Db::begin();
		// A Razorpay link that recorded this payment must not point at a missing row.
		$wpdb->update( Db::t( 'payment_links' ), array( 'payment_id' => null ), array( 'payment_id' => (int) $p->id, 'studio_id' => $this->sid() ) );
		Db::delete( 'payments', $p->id );
		self::audit( 'deleted', Db::cast( $p ), 'Payment ' . $p->receipt_no . ' of ₹' . \PCRM\Format::inr( $p->amount ) . ' (' . \PCRM\Format::date( $p->paid_on ) . ') deleted' );
		Db::log( 'payment', 'Payment ' . $p->receipt_no . ' of ₹' . \PCRM\Format::inr( $p->amount ) . ' was deleted', array( 'booking_id' => (int) $p->booking_id ) );
		Db::commit();
		return $this->ok( array( 'deleted' => true ) );
	}

	public function receipt( $req ) {
		$p = Db::find( 'payments', (int) $req['id'] );
		if ( ! $p ) {
			return $this->not_found( 'Payment' );
		}
		if ( 'received' !== $p->status ) {
			return new \WP_Error( 'validation_failed', 'A receipt is available only for payments marked as received.', array( 'status' => 422 ) );
		}
		$booking = Bookings::load( (int) $p->booking_id );
		Pdf::receipt( Db::cast( $p ), $booking, Tenant::studio(), ! empty( $req->get_param( 'download' ) ) );
		exit;
	}
}
