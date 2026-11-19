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
			'intro'          => array( 'type' => 'text', 'label' => 'Introduction' ),
			'closing'        => array( 'type' => 'text', 'label' => 'Closing message' ),
			'gst_type'       => array( 'type' => 'enum', 'options' => array( 'intra' => 1, 'inter' => 1 ), 'label' => 'GST type' ),
			'show_prices'    => array( 'type' => 'bool' ),
			'package_id'     => array( 'type' => 'ref', 'table' => 'quote_packages', 'label' => 'Package' ),
		);
	}

	private function event_schema() {
		return array(
			'name'       => array( 'type' => 'string', 'required' => true, 'label' => 'Event name' ),
			'event_type' => array( 'type' => 'enum', 'options' => Config::event_types(), 'label' => 'Event type' ),
			'event_date' => array( 'type' => 'date', 'label' => 'Event date' ),
			'start_time' => array( 'type' => 'time', 'label' => 'Start time' ),
			'end_time'   => array( 'type' => 'time', 'label' => 'End time' ),
			'venue'      => array( 'type' => 'string', 'max' => 255, 'label' => 'Venue' ),
			'city'       => array( 'type' => 'string', 'max' => 100, 'label' => 'City' ),
			'notes'      => array( 'type' => 'text' ),
		);
	}

	/**
	 * Validates a list of crew counts per role, e.g. {"candid_photographer": 2}.
	 * Returns the cleaned map or an error message.
	 */
	public static function clean_coverage( $raw ) {
		$roles = Config::quote_coverage_roles();
		$out   = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $role => $n ) {
			if ( ! isset( $roles[ $role ] ) ) {
				return 'Unknown team role in coverage.';
			}
			if ( ! is_numeric( $n ) || (int) $n != $n || $n < 0 || $n > 20 ) { // phpcs:ignore
				return $roles[ $role ] . ': enter a whole number from 0 to 20.';
			}
			if ( (int) $n > 0 ) {
				$out[ $role ] = (int) $n;
			}
		}
		return $out;
	}

	/**
	 * Cleans a list of short lines (deliverables, exclusions). Accepts an array or newline text.
	 */
	public static function clean_lines( $raw, $label, $max = 40 ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $line ) {
			$line = trim( sanitize_text_field( is_scalar( $line ) ? (string) $line : '' ) );
			if ( '' === $line ) {
				continue;
			}
			if ( mb_strlen( $line ) > 255 ) {
				return new \WP_Error( 'validation_failed', "$label: each line must be at most 255 characters.", array( 'status' => 422, 'errors' => array( 'deliverables' === strtolower( $label ) ? 'deliverables' : 'exclusions' => 'Line too long.' ) ) );
			}
			$out[] = $line;
		}
		if ( count( $out ) > $max ) {
			return new \WP_Error( 'validation_failed', "$label: at most $max lines.", array( 'status' => 422 ) );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Validates the payment schedule: up to 6 milestones whose percentages add up to 100.
	 */
	public static function clean_schedule( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		$sum = 0;
		foreach ( array_values( $raw ) as $i => $m ) {
			$m     = is_array( $m ) ? $m : array();
			$label = isset( $m['label'] ) ? trim( sanitize_text_field( (string) $m['label'] ) ) : '';
			$pct   = isset( $m['percent'] ) ? $m['percent'] : '';
			$due   = isset( $m['due'] ) ? trim( sanitize_text_field( (string) $m['due'] ) ) : '';
			if ( '' === $label && ( '' === $pct || null === $pct ) && '' === $due ) {
				continue;
			}
			$n = $i + 1;
			if ( '' === $label ) {
				return new \WP_Error( 'validation_failed', "Payment milestone $n needs a name.", array( 'status' => 422, 'errors' => array( 'payment_schedule' => "Milestone $n needs a name." ) ) );
			}
			if ( ! is_numeric( $pct ) || $pct <= 0 || $pct > 100 ) {
				return new \WP_Error( 'validation_failed', "Payment milestone $n: percentage must be more than 0 and at most 100.", array( 'status' => 422, 'errors' => array( 'payment_schedule' => "Milestone $n: enter a percentage." ) ) );
			}
			if ( mb_strlen( $label ) > 120 || mb_strlen( $due ) > 120 ) {
				return new \WP_Error( 'validation_failed', "Payment milestone $n is too long.", array( 'status' => 422 ) );
			}
			$pct   = round( (float) $pct, 2 );
			$sum  += $pct;
			$out[] = array( 'label' => $label, 'percent' => $pct, 'due' => $due );
		}
		if ( count( $out ) > 6 ) {
			return new \WP_Error( 'validation_failed', 'Use at most 6 payment milestones.', array( 'status' => 422, 'errors' => array( 'payment_schedule' => 'At most 6 milestones.' ) ) );
		}
		if ( $out && abs( $sum - 100 ) > 0.01 ) {
			return new \WP_Error( 'validation_failed', 'Payment milestones must add up to 100% (now ' . ( $sum + 0 ) . '%).', array( 'status' => 422, 'errors' => array( 'payment_schedule' => 'Must add up to 100%.' ) ) );
		}
		return $out;
	}

	/** Amount of each milestone; the last one takes the rounding difference. */
	public static function schedule_amounts( array $schedule, $total ) {
		$left = round( (float) $total, 2 );
		$n    = count( $schedule );
		foreach ( $schedule as $i => &$m ) {
			$amt         = $i === $n - 1 ? $left : round( $total * $m['percent'] / 100 );
			$m['amount'] = round( $amt, 2 );
			$left        = round( $left - $amt, 2 );
		}
		return $schedule;
	}

	/**
	 * Reads deliverables, exclusions and payment schedule from the body (only keys that were sent).
	 */
	private function parse_extras( $req ) {
		$b   = $this->body( $req );
		$out = array();
		if ( array_key_exists( 'deliverables', $b ) ) {
			$v = self::clean_lines( $b['deliverables'], 'Deliverables' );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			$out['deliverables'] = wp_json_encode( $v );
		}
		if ( array_key_exists( 'exclusions', $b ) ) {
			$v = self::clean_lines( $b['exclusions'], 'Not included', 30 );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			$out['exclusions'] = wp_json_encode( $v );
		}
		if ( array_key_exists( 'payment_schedule', $b ) ) {
			$v = self::clean_schedule( $b['payment_schedule'] );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			$out['payment_schedule'] = wp_json_encode( $v );
		}
		return $out;
	}

	private function parse_events( $req ) {
		$b = $this->body( $req );
		if ( ! isset( $b['events'] ) || ! is_array( $b['events'] ) ) {
			return null;
		}
		if ( count( $b['events'] ) > 15 ) {
			return new \WP_Error( 'validation_failed', 'A quotation can have at most 15 events.', array( 'status' => 422 ) );
		}
		$out = array();
		foreach ( array_values( $b['events'] ) as $i => $e ) {
			$e = is_array( $e ) ? $e : array();
			$v = Validator::validate( $e, $this->event_schema() );
			if ( is_wp_error( $v ) ) {
				return new \WP_Error( 'validation_failed', 'Event ' . ( $i + 1 ) . ': ' . $v->get_error_message(), array( 'status' => 422, 'errors' => array( 'events' => 'Event ' . ( $i + 1 ) . ': ' . $v->get_error_message() ) ) );
			}
			if ( $v['start_time'] && $v['end_time'] && $v['end_time'] === $v['start_time'] ) {
				return new \WP_Error( 'validation_failed', 'Event ' . ( $i + 1 ) . ': end time must differ from start time.', array( 'status' => 422 ) );
			}
			$cov = self::clean_coverage( isset( $e['coverage'] ) ? $e['coverage'] : array() );
			if ( is_string( $cov ) ) {
				return new \WP_Error( 'validation_failed', 'Event ' . ( $i + 1 ) . ': ' . $cov, array( 'status' => 422, 'errors' => array( 'events' => 'Event ' . ( $i + 1 ) . ': ' . $cov ) ) );
			}
			$v['coverage'] = wp_json_encode( (object) $cov );
			$v['sort']     = $i;
			$out[]         = $v;
		}
		return $out;
	}

	private function save_events( $qid, array $events ) {
		Db::delete_where( 'quotation_events', array( 'quotation_id' => $qid ) );
		foreach ( $events as $e ) {
			$e['quotation_id'] = $qid;
			Db::insert( 'quotation_events', $e );
		}
	}

	/** Keeps the single event_date / venue columns (used by lists, reminders, WhatsApp) in step with the events. */
	private static function primary_from_events( array $events ) {
		$dates = array_filter( wp_list_pluck( $events, 'event_date' ) );
		sort( $dates );
		$venue = '';
		foreach ( $events as $e ) {
			if ( ! empty( $e['venue'] ) || ! empty( $e['city'] ) ) {
				$venue = trim( ( isset( $e['venue'] ) ? $e['venue'] : '' ) . ( ! empty( $e['city'] ) ? ', ' . $e['city'] : '' ), ', ' );
				break;
			}
		}
		return array( 'event_date' => $dates ? $dates[0] : null, 'venue' => mb_substr( $venue, 0, 255 ) );
	}

	private function item_schema() {
		return array(
			'service_id'  => array( 'type' => 'ref', 'table' => 'services', 'label' => 'Service' ),
			'category'    => array( 'type' => 'enum', 'options' => Config::service_categories() ),
			'name'        => array( 'type' => 'string', 'required' => true, 'label' => 'Item name' ),
			'description' => array( 'type' => 'text' ),
			'qty'         => array( 'type' => 'decimal', 'required' => true, 'min' => 0.01, 'label' => 'Quantity' ),
			'rate'        => array( 'type' => 'money', 'required' => true, 'label' => 'Rate' ),
			'kind'        => array( 'type' => 'enum', 'options' => Config::quote_item_kinds(), 'default' => 'service', 'label' => 'Item type' ),
			'unit'        => array( 'type' => 'string', 'max' => 40, 'label' => 'Unit' ),
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
		$this->route( '/quotations/(?P<id>\d+)/activity', 'GET', array( $this, 'activity' ), 'quotes.view' );
		$this->route( '/quotations/(?P<id>\d+)/new-link', 'POST', array( $this, 'new_link' ), 'quotes.edit' );
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
		if ( count( $items ) > 100 ) {
			return new \WP_Error( 'validation_failed', 'A quotation can have at most 100 lines.', array( 'status' => 422 ) );
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
		$q->items  = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'quotation_items' ) . ' WHERE quotation_id=%d AND studio_id=%d ORDER BY sort, id', $id, $q->studio_id ) ) );
		$q->events = array_map(
			function ( $e ) {
				$c           = json_decode( (string) $e->coverage, true );
				$e->coverage = is_array( $c ) ? $c : array();
				return $e;
			},
			Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'quotation_events' ) . ' WHERE quotation_id=%d AND studio_id=%d ORDER BY sort, id', $id, $q->studio_id ) ) )
		);
		foreach ( array( 'deliverables', 'exclusions', 'payment_schedule' ) as $f ) {
			$v      = json_decode( (string) $q->$f, true );
			$q->$f  = is_array( $v ) ? $v : array();
		}
		$q = Db::cast( $q );
		$q->show_prices      = (int) $q->show_prices;
		$q->payment_schedule = self::schedule_amounts( $q->payment_schedule, $q->total );
		return $q;
	}

	/** Studio defaults for a new quotation's text blocks and schedule. */
	private static function defaults() {
		$lines = function ( $v ) {
			return is_array( $v ) ? $v : array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $v ) ) ) );
		};
		$sched = Tenant::setting( 'quote_payment_schedule' );
		return array(
			'intro'            => (string) Tenant::setting( 'quote_intro' ),
			'closing'          => (string) Tenant::setting( 'quote_closing' ),
			'terms'            => (string) Tenant::setting( 'quote_terms' ),
			'deliverables'     => wp_json_encode( $lines( Tenant::setting( 'quote_deliverables' ) ) ),
			'exclusions'       => wp_json_encode( $lines( Tenant::setting( 'quote_exclusions' ) ) ),
			'payment_schedule' => wp_json_encode( is_array( $sched ) ? $sched : Config::default_payment_schedule() ),
			'gst_type'         => 'inter' === Tenant::setting( 'gst_type' ) ? 'inter' : 'intra',
			'show_prices'      => false === Tenant::setting( 'quote_show_prices' ) ? 0 : 1,
		);
	}

	/** Timeline of a quotation (created, edited, sent, viewed, accepted…). */
	public function activity( $req ) {
		global $wpdb;
		$q = Db::find( 'quotations', (int) $req['id'] );
		if ( ! $q ) {
			return $this->not_found( 'Quotation' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.id, a.action, a.summary, a.created_at, u.display_name AS user_name FROM ' . Db::t( 'audit_logs' ) . " a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE a.studio_id=%d AND a.entity='quotation' AND a.entity_id=%d ORDER BY a.id DESC LIMIT 200", $this->sid(), $q->id ) );
		return $this->ok( Db::cast_all( $rows ) );
	}

	/** Replaces the client link; the old one stops working. */
	public function new_link( $req ) {
		$q = Db::find( 'quotations', (int) $req['id'] );
		if ( ! $q ) {
			return $this->not_found( 'Quotation' );
		}
		Db::update( 'quotations', $q->id, array( 'public_token' => wp_generate_password( 32, false, false ) ) );
		Db::audit( 'quotation', $q->id, 'new_link', 'New client link created. The old link no longer works.' );
		return $this->show( $req );
	}

	public function show( $req ) {
		global $wpdb;
		$q = self::load( (int) $req['id'] );
		if ( ! $q ) {
			return $this->not_found( 'Quotation' );
		}
		$q->booking_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'bookings' ) . ' WHERE studio_id=%d AND quotation_id=%d LIMIT 1', $this->sid(), $q->id ) );
		$q->public_url     = home_url( '/crm/q/' . $q->public_token );
		$q->public_pdf_url = home_url( '/crm/q/' . $q->public_token . '/pdf' );
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
		$events = $this->parse_events( $req );
		if ( is_wp_error( $events ) ) {
			return $events;
		}
		$extras = $this->parse_extras( $req );
		if ( is_wp_error( $extras ) ) {
			return $extras;
		}
		foreach ( self::defaults() as $k => $v ) {
			if ( array_key_exists( $k, $extras ) ) {
				continue;
			}
			if ( ! array_key_exists( $k, $data ) || null === $data[ $k ] ) {
				// Text blocks the user cleared on purpose arrive as "" and stay empty.
				$sent = $this->body( $req );
				if ( in_array( $k, array( 'intro', 'closing', 'terms' ), true ) && array_key_exists( $k, $sent ) ) {
					continue;
				}
				$data[ $k ] = $v;
			}
		}
		$data = array_merge( $data, $extras );
		if ( $events ) {
			$data = array_merge( $data, self::primary_from_events( $events ) );
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
		if ( $events ) {
			$this->save_events( $id, $events );
		}
		Db::audit( 'quotation', $id, 'created', 'Quotation ' . $data['number'] . ' created for ' . self::money( $data['total'] ) );
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
		$events = $this->parse_events( $req );
		if ( is_wp_error( $events ) ) {
			return $events;
		}
		$extras = $this->parse_extras( $req );
		if ( is_wp_error( $extras ) ) {
			return $extras;
		}
		$data = array_merge( $data, $extras );
		if ( null !== $events ) {
			$data = array_merge( $data, self::primary_from_events( $events ) );
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
		$ok = Db::update( 'quotations', $q->id, $data );
		if ( is_wp_error( $ok ) ) {
			Db::rollback();
			return $ok;
		}
		if ( null !== $items ) {
			$this->save_items( $q->id, $items );
		}
		if ( null !== $events ) {
			$this->save_events( $q->id, $events );
		}
		$summary = 'Quotation edited';
		if ( abs( (float) $data['total'] - (float) $q->total ) > 0.009 ) {
			$summary .= ': total ' . self::money( $q->total ) . ' → ' . self::money( $data['total'] );
		}
		Db::audit( 'quotation', $q->id, 'updated', $summary );
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
		$by_client = null !== $studio_id;
		Db::audit( 'quotation', $q->id, 'status_' . $status, ( $by_client ? ( 'viewed' === $status ? 'Client opened the quotation' : 'Client ' . ( 'accepted' === $status ? 'accepted' : 'declined' ) . ' the quotation' ) : 'Marked ' . $labels[ $status ] ), null, $studio_id );
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
		$events = $q->events;
		unset( $data['id'], $data['items'], $data['events'], $data['sent_at'], $data['viewed_at'], $data['responded_at'], $data['created_at'], $data['last_viewed_at'], $data['accepted_name'], $data['client_note'], $data['response_ip_hash'] );
		$data['view_count'] = 0;
		foreach ( array( 'deliverables', 'exclusions' ) as $f ) {
			$data[ $f ] = wp_json_encode( $q->$f );
		}
		$data['payment_schedule'] = wp_json_encode(
			array_map(
				function ( $m ) {
					unset( $m['amount'] );
					return $m;
				},
				$q->payment_schedule
			)
		);
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
		$this->save_events(
			$id,
			array_map(
				function ( $e ) {
					$e = (array) $e;
					unset( $e['id'], $e['quotation_id'], $e['studio_id'] );
					$e['coverage'] = wp_json_encode( (object) $e['coverage'] );
					return $e;
				},
				$events
			)
		);
		Db::audit( 'quotation', $id, 'created', 'Quotation ' . $data['number'] . ' created as a copy of ' . $q->number );
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
		Db::delete_where( 'quotation_events', array( 'quotation_id' => $q->id ) );
		Db::delete( 'quotations', $q->id );
		Db::audit( 'quotation', $q->id, 'deleted', 'Quotation ' . $q->number . ' deleted' );
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
