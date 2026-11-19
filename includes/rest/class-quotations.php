<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Pdf;
use PCRM\Tenant;
use PCRM\Validator;
use PCRM\Whatsapp as WA;

defined( 'ABSPATH' ) || exit;

class Quotations extends Base {

	private function schema() {
		return array(
			'lead_id'        => array( 'type' => 'ref', 'table' => 'leads', 'label' => 'Lead' ),
			'client_id'      => array( 'type' => 'ref', 'table' => 'clients', 'label' => 'Client' ),
			'title'          => array( 'type' => 'string', 'required' => true ),
			'customer_name'  => array( 'type' => 'string', 'required' => true, 'label' => 'Customer name' ),
			'customer_phone' => array( 'type' => 'phone', 'label' => 'Customer phone' ),
			'customer_email' => array( 'type' => 'email', 'label' => 'Customer email' ),
			'event_type'     => array( 'type' => 'enum', 'options' => Config::event_types(), 'label' => 'Event type' ),
			'event_date'     => array( 'type' => 'date', 'label' => 'Event date' ),
			'venue'          => array( 'type' => 'string', 'max' => 255 ),
			'valid_until'    => array( 'type' => 'date', 'label' => 'Valid until' ),
			'discount_type'  => array( 'type' => 'enum', 'options' => array( 'flat' => 1, 'percent' => 1 ), 'default' => 'flat' ),
			'discount_value' => array( 'type' => 'money', 'default' => 0, 'label' => 'Discount' ),
			'gst_rate'       => array( 'type' => 'decimal', 'default' => 0, 'maxval' => 28, 'label' => 'GST rate' ),
			'notes'          => array( 'type' => 'text' ),
			'terms'          => array( 'type' => 'text' ),
		);
	}

	private function item_schema() {
		return array(
			'service_id'  => array( 'type' => 'ref', 'table' => 'services', 'label' => 'Service' ),
			'category'    => array( 'type' => 'enum', 'options' => Config::service_categories() ),
			'name'        => array( 'type' => 'string', 'required' => true, 'label' => 'Item name' ),
			'description' => array( 'type' => 'text' ),
			'qty'         => array( 'type' => 'decimal', 'required' => true, 'min' => 0.01, 'label' => 'Quantity' ),
			'rate'        => array( 'type' => 'money', 'required' => true, 'label' => 'Rate' ),
		);
	}

	public function register() {
		$this->route( '/quotations', 'GET', array( $this, 'index' ), 'quotes.view' );
		$this->route( '/quotations', 'POST', array( $this, 'create' ), 'quotes.edit' );
		$this->route( '/quotations/(?P<id>\d+)', 'GET', array( $this, 'show' ), 'quotes.view' );
		$this->route( '/quotations/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'quotes.edit' );
		$this->route( '/quotations/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'quotes.delete' );
		$this->route( '/quotations/(?P<id>\d+)/status', 'POST', array( $this, 'set_status' ), 'quotes.edit' );
		$this->route( '/quotations/(?P<id>\d+)/duplicate', 'POST', array( $this, 'duplicate' ), 'quotes.edit' );
		$this->route( '/quotations/(?P<id>\d+)/pdf', 'GET', array( $this, 'pdf' ), 'quotes.view' );
	}

	public static function totals( array $items, $discount_type, $discount_value, $gst_rate ) {
		$subtotal = 0;
		foreach ( $items as $it ) {
			$subtotal += round( $it['qty'] * $it['rate'], 2 );
		}
		$discount = 'percent' === $discount_type ? round( $subtotal * min( 100, $discount_value ) / 100, 2 ) : min( $subtotal, $discount_value );
		$taxable  = $subtotal - $discount;
		$gst      = round( $taxable * $gst_rate / 100, 2 );
		return array(
			'subtotal'        => round( $subtotal, 2 ),
			'discount_amount' => round( $discount, 2 ),
			'gst_amount'      => $gst,
			'total'           => round( $taxable + $gst, 2 ),
		);
	}

	private function parse_items( $req ) {
		$b     = $this->body( $req );
		$items = isset( $b['items'] ) && is_array( $b['items'] ) ? $b['items'] : null;
		if ( null === $items ) {
			return null;
		}
		if ( ! $items ) {
			return new \WP_Error( 'validation_failed', 'Add at least one service to the quotation.', array( 'status' => 422 ) );
		}
		$out = array();
		foreach ( $items as $i => $it ) {
			$v = Validator::validate( is_array( $it ) ? $it : array(), $this->item_schema() );
			if ( is_wp_error( $v ) ) {
				return new \WP_Error( 'validation_failed', 'Item ' . ( $i + 1 ) . ': ' . $v->get_error_message(), array( 'status' => 422 ) );
			}
			$v['amount'] = round( $v['qty'] * $v['rate'], 2 );
			$v['sort']   = $i;
			$out[]       = $v;
		}
		return $out;
	}

	private function save_items( $qid, array $items ) {
		Db::delete_where( 'quotation_items', array( 'quotation_id' => $qid ) );
		foreach ( $items as $it ) {
			$it['quotation_id'] = $qid;
			Db::insert( 'quotation_items', $it );
		}
	}

	public function index( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$w = array( $wpdb->prepare( 'q.studio_id=%d', $this->sid() ) );
		$s = $req->get_param( 'status' );
		if ( $s && array_key_exists( $s, Config::quotation_statuses() ) ) {
			$w[] = $wpdb->prepare( 'q.status=%s', $s );
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like = $this->like( $q );
			$w[]  = $wpdb->prepare( '(q.number LIKE %s OR q.title LIKE %s OR q.customer_name LIKE %s OR q.customer_phone LIKE %s)', $like, $like, $like, $like );
		}
		foreach ( array( 'lead_id', 'client_id' ) as $f ) {
			if ( $req->get_param( $f ) ) {
				$w[] = $wpdb->prepare( "q.$f=%d", $req->get_param( $f ) );
			}
		}
		$where = implode( ' AND ', $w );
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'quotations' ) . " q WHERE $where" );
		$rows  = $wpdb->get_results( 'SELECT q.*, (SELECT id FROM ' . Db::t( 'bookings' ) . ' b WHERE b.quotation_id=q.id AND b.studio_id=q.studio_id LIMIT 1) AS booking_id FROM ' . Db::t( 'quotations' ) . " q WHERE $where ORDER BY q.created_at DESC LIMIT $per OFFSET $off" );
		$stats = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n, SUM(total) amount FROM ' . Db::t( 'quotations' ) . ' WHERE studio_id=%d GROUP BY status', $this->sid() ), OBJECT_K );
		return $this->ok( array( 'items' => Db::cast_all( $rows ), 'total' => $total, 'page' => $page, 'per_page' => $per, 'stats' => $stats ) );
	}

	public static function load( $id, $studio_id = null ) {
		global $wpdb;
		$q = Db::find( 'quotations', $id, $studio_id );
		if ( ! $q ) {
			return null;
		}
		$q->items = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'quotation_items' ) . ' WHERE quotation_id=%d AND studio_id=%d ORDER BY sort, id', $id, $q->studio_id ) ) );
		return Db::cast( $q );
	}

	public function show( $req ) {
		global $wpdb;
		$q = self::load( (int) $req['id'] );
		if ( ! $q ) {
			return $this->not_found( 'Quotation' );
		}
		$q->booking_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'bookings' ) . ' WHERE studio_id=%d AND quotation_id=%d LIMIT 1', $this->sid(), $q->id ) );
		$q->public_url = home_url( '/crm/q/' . $q->public_token );
		$q->whatsapp   = WA::render_for_quotation( $q );
		return $this->ok( $q );
	}

	public function create( $req ) {
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$items = $this->parse_items( $req );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		if ( ! $items ) {
			return new \WP_Error( 'validation_failed', 'Add at least one service to the quotation.', array( 'status' => 422 ) );
		}
		if ( $data['lead_id'] && ! $data['client_id'] ) {
			$lead = Db::find( 'leads', $data['lead_id'] );
			if ( $lead->client_id ) {
				$data['client_id'] = (int) $lead->client_id;
			}
		}
		if ( ! $data['valid_until'] ) {
			$data['valid_until'] = date( 'Y-m-d', strtotime( Db::today() . ' +' . (int) Tenant::setting( 'quote_validity' ) . ' days' ) );
		}
		if ( null === $data['terms'] ) {
			$data['terms'] = Tenant::setting( 'quote_terms' );
		}
		$data                 = array_merge( $data, self::totals( $items, $data['discount_type'], $data['discount_value'], $data['gst_rate'] ) );
		$data['number']       = Db::next_number( 'quotations', Tenant::setting( 'quote_prefix' ) ? Tenant::setting( 'quote_prefix' ) : 'QT' );
		$data['status']       = 'draft';
		$data['public_token'] = wp_generate_password( 32, false, false );
		$data['created_by']   = get_current_user_id();
		Db::begin();
		$id = Db::insert( 'quotations', $data );
		if ( is_wp_error( $id ) ) {
			Db::rollback();
			return $id;
		}
		$this->save_items( $id, $items );
		Db::log( 'quotation', 'Quotation ' . $data['number'] . ' created for ' . self::money( $data['total'] ), array( 'lead_id' => $data['lead_id'], 'client_id' => $data['client_id'] ), array( 'quotation_id' => $id ) );
		Db::commit();
		return $this->ok( self::load( $id ), 201 );
	}

	public static function money( $n ) {
		return '₹' . \PCRM\Format::inr( $n );
	}

	public function update( $req ) {
		$q = self::load( (int) $req['id'] );
		if ( ! $q ) {
			return $this->not_found( 'Quotation' );
		}
		$data = $this->validate( $req, $this->schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$items = $this->parse_items( $req );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$use_items = null !== $items ? $items : array_map(
			function ( $i ) {
				return (array) $i;
			},
			$q->items
		);
		$dt   = isset( $data['discount_type'] ) ? $data['discount_type'] : $q->discount_type;
		$dv   = array_key_exists( 'discount_value', $data ) ? (float) $data['discount_value'] : $q->discount_value;
		$gr   = array_key_exists( 'gst_rate', $data ) ? (float) $data['gst_rate'] : $q->gst_rate;
		$data = array_merge( $data, self::totals( $use_items, $dt, $dv, $gr ) );
		Db::begin();
		Db::update( 'quotations', $q->id, $data );
		if ( null !== $items ) {
			$this->save_items( $q->id, $items );
		}
		Db::commit();
		return $this->ok( self::load( $q->id ) );
	}

	public static function change_status( $q, $status, $studio_id = null ) {
		$labels = Config::quotation_statuses();
		$data   = array( 'status' => $status );
		if ( 'sent' === $status && ! $q->sent_at ) {
			$data['sent_at'] = Db::now();
		}
		if ( 'viewed' === $status && ! $q->viewed_at ) {
			$data['viewed_at'] = Db::now();
		}
		if ( in_array( $status, array( 'accepted', 'rejected' ), true ) ) {
			$data['responded_at'] = Db::now();
		}
		Db::update( 'quotations', $q->id, $data, $studio_id );
		Db::log( 'quotation', 'Quotation ' . $q->number . ' marked ' . $labels[ $status ], array( 'lead_id' => $q->lead_id, 'client_id' => $q->client_id ), array( 'quotation_id' => (int) $q->id ), $studio_id );
		if ( $q->lead_id ) {
			$lead = Db::find( 'leads', $q->lead_id, $studio_id );
			if ( $lead ) {
				$early = array( 'new', 'first_call_pending', 'contacted', 'interested', 'followup_required' );
				if ( 'sent' === $status && in_array( $lead->stage, $early, true ) ) {
					null === $studio_id ? Leads::apply_update( $lead, array( 'stage' => 'quotation_sent' ) ) : Db::update( 'leads', $lead->id, array( 'stage' => 'quotation_sent' ), $studio_id );
				}
				if ( 'accepted' === $status && in_array( $lead->stage, array_merge( $early, array( 'quotation_sent' ) ), true ) ) {
					null === $studio_id ? Leads::apply_update( $lead, array( 'stage' => 'negotiation' ) ) : Db::update( 'leads', $lead->id, array( 'stage' => 'negotiation' ), $studio_id );
				}
			}
		}
	}

	public function set_status( $req ) {
		$q = Db::find( 'quotations', (int) $req['id'] );
		if ( ! $q ) {
			return $this->not_found( 'Quotation' );
		}
		$b      = $this->body( $req );
		$status = isset( $b['status'] ) ? (string) $b['status'] : '';
		if ( ! array_key_exists( $status, Config::quotation_statuses() ) ) {
			return new \WP_Error( 'validation_failed', 'Invalid status.', array( 'status' => 422 ) );
		}
		if ( $status !== $q->status ) {
			self::change_status( $q, $status );
		}
		return $this->ok( self::load( $q->id ) );
	}

	public function duplicate( $req ) {
		$q = self::load( (int) $req['id'] );
		if ( ! $q ) {
			return $this->not_found( 'Quotation' );
		}
		$data = (array) $q;
		unset( $data['id'], $data['items'], $data['sent_at'], $data['viewed_at'], $data['responded_at'], $data['created_at'] );
		$data['number']       = Db::next_number( 'quotations', Tenant::setting( 'quote_prefix' ) ? Tenant::setting( 'quote_prefix' ) : 'QT' );
		$data['status']       = 'draft';
		$data['title']        = $q->title . ' (copy)';
		$data['public_token'] = wp_generate_password( 32, false, false );
		$data['valid_until']  = date( 'Y-m-d', strtotime( Db::today() . ' +' . (int) Tenant::setting( 'quote_validity' ) . ' days' ) );
		$data['created_by']   = get_current_user_id();
		$id                   = Db::insert( 'quotations', $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$this->save_items(
			$id,
			array_map(
				function ( $i ) {
					$i = (array) $i;
					unset( $i['id'], $i['quotation_id'], $i['studio_id'] );
					return $i;
				},
				$q->items
			)
		);
		return $this->ok( self::load( $id ), 201 );
	}

	public function destroy( $req ) {
		global $wpdb;
		$q = Db::find( 'quotations', (int) $req['id'] );
		if ( ! $q ) {
			return $this->not_found( 'Quotation' );
		}
		$wpdb->update( Db::t( 'bookings' ), array( 'quotation_id' => null ), array( 'quotation_id' => $q->id, 'studio_id' => $this->sid() ) );
		Db::delete_where( 'quotation_items', array( 'quotation_id' => $q->id ) );
		Db::delete( 'quotations', $q->id );
		return $this->ok( array( 'deleted' => true ) );
	}

	public function pdf( $req ) {
		$q = self::load( (int) $req['id'] );
		if ( ! $q ) {
			return $this->not_found( 'Quotation' );
		}
		Pdf::quotation( $q, Tenant::studio(), ! empty( $req->get_param( 'download' ) ) );
		exit;
	}

	/**
	 * Daily: mark quotations past their validity as expired.
	 */
	public static function expire_old() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'quotations' ) . " SET status='expired', updated_at=%s WHERE status IN ('draft','sent','viewed') AND valid_until IS NOT NULL AND valid_until < %s", Db::now(), Db::today() ) );
	}
}
