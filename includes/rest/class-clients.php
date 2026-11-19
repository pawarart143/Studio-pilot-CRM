<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

class Clients extends Base {

	public static function schema() {
		return array(
			'name'     => array( 'type' => 'string', 'required' => true, 'label' => 'Client name' ),
			'phone'    => array( 'type' => 'phone', 'required' => true, 'label' => 'Phone number' ),
			'whatsapp' => array( 'type' => 'phone', 'label' => 'WhatsApp number' ),
			'email'    => array( 'type' => 'email' ),
			'city'     => array( 'type' => 'string', 'max' => 100 ),
			'address'  => array( 'type' => 'text' ),
			'notes'    => array( 'type' => 'text' ),
		);
	}

	public function register() {
		$this->route( '/clients', 'GET', array( $this, 'index' ), 'clients.view' );
		$this->route( '/clients', 'POST', array( $this, 'create' ), 'clients.edit' );
		$this->route( '/clients/(?P<id>\d+)', 'GET', array( $this, 'show' ), 'clients.view' );
		$this->route( '/clients/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'clients.edit' );
		$this->route( '/clients/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'clients.delete' );
		$this->route( '/clients/(?P<id>\d+)/notes', 'POST', array( $this, 'add_note' ), 'clients.edit' );
	}

	/**
	 * Finds the client for a lead (by link or phone) or creates one.
	 */
	public static function ensure_from_lead( $lead ) {
		global $wpdb;
		if ( $lead->client_id && Db::exists( 'clients', $lead->client_id ) ) {
			return (int) $lead->client_id;
		}
		$cid = $lead->phone ? $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'clients' ) . ' WHERE studio_id=%d AND phone=%s LIMIT 1', Tenant::studio_id(), $lead->phone ) ) : null;
		if ( ! $cid ) {
			$cid = Db::insert(
				'clients',
				array(
					'name'       => $lead->name,
					'phone'      => $lead->phone,
					'whatsapp'   => $lead->whatsapp ? $lead->whatsapp : $lead->phone,
					'email'      => $lead->email,
					'city'       => $lead->city,
					'created_by' => get_current_user_id(),
				)
			);
			if ( is_wp_error( $cid ) ) {
				return $cid;
			}
			Db::log( 'created', 'Client profile created from lead', array( 'client_id' => $cid, 'lead_id' => (int) $lead->id ) );
		}
		Db::update( 'leads', $lead->id, array( 'client_id' => (int) $cid ) );
		return (int) $cid;
	}

	public function index( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$w = array( $wpdb->prepare( 'c.studio_id=%d', $this->sid() ) );
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like = $this->like( $q );
			$w[]  = $wpdb->prepare( '(c.name LIKE %s OR c.phone LIKE %s OR c.email LIKE %s OR c.city LIKE %s)', $like, $like, $like, $like );
		}
		if ( $req->get_param( 'city' ) ) {
			$w[] = $wpdb->prepare( 'c.city=%s', $req->get_param( 'city' ) );
		}
		$where = implode( ' AND ', $w );
		$b     = Db::t( 'bookings' );
		$p     = Db::t( 'payments' );
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'clients' ) . " c WHERE $where" );
		$rows  = $wpdb->get_results(
			'SELECT c.*,
				(SELECT COUNT(*) FROM ' . $b . " bk WHERE bk.client_id=c.id AND bk.studio_id=c.studio_id AND bk.status<>'cancelled') AS bookings_count,
				(SELECT COALESCE(SUM(bk.total_amount),0) FROM " . $b . " bk WHERE bk.client_id=c.id AND bk.studio_id=c.studio_id AND bk.status<>'cancelled') AS total_amount,
				(SELECT COALESCE(SUM(py.amount),0) FROM " . $p . ' py JOIN ' . $b . " bk ON bk.id=py.booking_id WHERE bk.client_id=c.id AND py.studio_id=c.studio_id AND py.status='received') AS paid
			FROM " . Db::t( 'clients' ) . " c WHERE $where ORDER BY c.created_at DESC LIMIT $per OFFSET $off"
		);
		foreach ( $rows as $r ) {
			$r->bookings_count = (int) $r->bookings_count;
			$r->pending        = (float) $r->total_amount - (float) $r->paid;
		}
		if ( ! Tenant::can( 'payments.view' ) ) {
			foreach ( $rows as $r ) {
				unset( $r->total_amount, $r->paid );
			}
		}
		return $this->ok( array( 'items' => Db::cast_all( $rows ), 'total' => $total, 'page' => $page, 'per_page' => $per ) );
	}

	public function create( $req ) {
		$data = $this->validate( $req, self::schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		global $wpdb;
		$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'clients' ) . ' WHERE studio_id=%d AND phone=%s', $this->sid(), $data['phone'] ) );
		if ( $dup ) {
			return new \WP_Error( 'duplicate', 'A client with this phone number already exists.', array( 'status' => 409, 'client_id' => (int) $dup ) );
		}
		if ( ! $data['whatsapp'] ) {
			$data['whatsapp'] = $data['phone'];
		}
		$data['created_by'] = get_current_user_id();
		$id                 = Db::insert( 'clients', $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Db::log( 'created', 'Client profile created', array( 'client_id' => $id ) );
		return $this->ok( Db::cast( Db::find( 'clients', $id ) ), 201 );
	}

	public function show( $req ) {
		global $wpdb;
		$id     = (int) $req['id'];
		$client = Db::find( 'clients', $id );
		if ( ! $client ) {
			return $this->not_found( 'Client' );
		}
		$sid = $this->sid();
		$client->enquiries  = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, event_type, event_date, stage, source, budget, created_at FROM ' . Db::t( 'leads' ) . ' WHERE studio_id=%d AND (client_id=%d OR (client_id IS NULL AND phone=%s)) ORDER BY created_at DESC', $sid, $id, (string) $client->phone ) ) );
		$client->bookings   = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT b.*, (SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'payments' ) . ' p WHERE p.booking_id=b.id AND p.studio_id=b.studio_id AND p.status=\'received\') AS paid FROM ' . Db::t( 'bookings' ) . ' b WHERE b.studio_id=%d AND b.client_id=%d ORDER BY b.created_at DESC', $sid, $id ) ) );
		$lead_ids           = wp_list_pluck( $client->enquiries, 'id' );
		$client->quotations = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT id, number, title, status, total, created_at FROM ' . Db::t( 'quotations' ) . ' WHERE studio_id=%d AND (client_id=%d OR lead_id IN (' . $this->ids_in( $lead_ids ) . ')) ORDER BY created_at DESC', $sid, $id ) ) );
		$booking_ids        = wp_list_pluck( $client->bookings, 'id' );
		$bin                = $this->ids_in( $booking_ids );
		$client->payments   = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT p.*, b.number AS booking_number FROM ' . Db::t( 'payments' ) . ' p JOIN ' . Db::t( 'bookings' ) . " b ON b.id=p.booking_id WHERE p.studio_id=%d AND p.booking_id IN ($bin) ORDER BY p.paid_on DESC", $sid ) ) );
		$client->events     = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT e.*, b.number AS booking_number FROM ' . Db::t( 'booking_events' ) . ' e JOIN ' . Db::t( 'bookings' ) . " b ON b.id=e.booking_id WHERE e.studio_id=%d AND e.booking_id IN ($bin) ORDER BY e.event_date ASC", $sid ) ) );
		$lin                = $this->ids_in( $lead_ids );
		$acts               = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, u.display_name AS user_name FROM ' . Db::t( 'activities' ) . ' a LEFT JOIN ' . $wpdb->users . " u ON u.ID=a.user_id WHERE a.studio_id=%d AND (a.client_id=%d OR a.lead_id IN ($lin) OR a.booking_id IN ($bin)) ORDER BY a.created_at DESC, a.id DESC LIMIT 300", $sid, $id ) );
		foreach ( $acts as $a ) {
			$a->meta = $a->meta ? json_decode( $a->meta, true ) : null;
		}
		$client->activities = Db::cast_all( $acts );
		$client->documents  = Documents::list_for( array( 'client_id' => $id, 'booking_ids' => $booking_ids ) );
		$total              = array_sum( wp_list_pluck( array_filter( $client->bookings, function ( $b ) {
			return 'cancelled' !== $b->status;
		} ), 'total_amount' ) );
		$paid               = array_sum( wp_list_pluck( array_filter( $client->payments, function ( $p ) {
			return 'received' === $p->status;
		} ), 'amount' ) );
		$client->summary    = array( 'total' => $total, 'paid' => $paid, 'pending' => max( 0, $total - $paid ) );
		if ( ! Tenant::can( 'payments.view' ) ) {
			// Creative roles can open a client profile but must not see money.
			unset( $client->summary, $client->payments );
			foreach ( $client->bookings as $bk ) {
				unset( $bk->total_amount, $bk->paid );
			}
			foreach ( $client->enquiries as $en ) {
				unset( $en->budget );
			}
			$client->quotations = Tenant::can( 'quotes.view' ) ? $client->quotations : array();
			$client->activities = array_values(
				array_filter(
					$client->activities,
					function ( $a ) {
						return ! in_array( $a->type, array( 'payment', 'expense', 'quotation' ), true ) && false === strpos( (string) $a->message, '₹' );
					}
				)
			);
		}
		return $this->ok( Db::cast( $client ) );
	}

	public function update( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'clients', $id ) ) {
			return $this->not_found( 'Client' );
		}
		$data = $this->validate( $req, self::schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! empty( $data['phone'] ) ) {
			global $wpdb;
			$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'clients' ) . ' WHERE studio_id=%d AND phone=%s AND id<>%d', $this->sid(), $data['phone'], $id ) );
			if ( $dup ) {
				return new \WP_Error( 'duplicate', 'Another client already has this phone number.', array( 'status' => 409 ) );
			}
		}
		$r = Db::update( 'clients', $id, $data );
		return is_wp_error( $r ) ? $r : $this->ok( Db::cast( Db::find( 'clients', $id ) ) );
	}

	public function add_note( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'clients', $id ) ) {
			return $this->not_found( 'Client' );
		}
		$data = $this->validate( $req, array( 'message' => array( 'type' => 'text', 'required' => true, 'label' => 'Note' ) ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$type = 'note';
		$b    = $this->body( $req );
		if ( ! empty( $b['type'] ) && in_array( $b['type'], array( 'note', 'call', 'whatsapp', 'email', 'meeting' ), true ) ) {
			$type = $b['type'];
		}
		return $this->ok( array( 'id' => Db::log( $type, $data['message'], array( 'client_id' => $id ) ) ), 201 );
	}

	public function destroy( $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		if ( ! Db::exists( 'clients', $id ) ) {
			return $this->not_found( 'Client' );
		}
		$has = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . ' WHERE studio_id=%d AND client_id=%d', $this->sid(), $id ) );
		if ( $has ) {
			return new \WP_Error( 'has_bookings', 'This client has bookings. Delete or cancel the bookings first.', array( 'status' => 409 ) );
		}
		$wpdb->update( Db::t( 'leads' ), array( 'client_id' => null ), array( 'client_id' => $id, 'studio_id' => $this->sid() ) );
		$wpdb->update( Db::t( 'quotations' ), array( 'client_id' => null ), array( 'client_id' => $id, 'studio_id' => $this->sid() ) );
		Db::delete_where( 'activities', array( 'client_id' => $id ) );
		Documents::delete_for( array( 'client_id' => $id ) );
		Db::delete( 'clients', $id );
		return $this->ok( array( 'deleted' => true ) );
	}
}
