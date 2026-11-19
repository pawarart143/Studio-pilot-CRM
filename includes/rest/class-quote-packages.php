<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable quotation packages (v0.6.0): a named set of services with rates,
 * default deliverables and default crew per event day.
 */
class Quote_Packages extends Base {

	private function schema() {
		return array(
			'name'        => array( 'type' => 'string', 'required' => true, 'label' => 'Package name' ),
			'event_type'  => array( 'type' => 'enum', 'options' => Config::event_types(), 'label' => 'Event type' ),
			'description' => array( 'type' => 'text' ),
			'is_active'   => array( 'type' => 'bool', 'default' => 1 ),
			'sort'        => array( 'type' => 'int', 'default' => 0 ),
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
			'unit'        => array( 'type' => 'string', 'max' => 40, 'label' => 'Unit' ),
		);
	}

	public function register() {
		$this->route( '/quote-packages', 'GET', array( $this, 'index' ), array( 'quotes.view', 'settings.edit' ) );
		$this->route( '/quote-packages', 'POST', array( $this, 'create' ), 'settings.edit' );
		$this->route( '/quote-packages/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'settings.edit' );
		$this->route( '/quote-packages/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'settings.edit' );
	}

	public static function load( $id ) {
		global $wpdb;
		$p = Db::cast( Db::find( 'quote_packages', $id ) );
		if ( ! $p ) {
			return null;
		}
		return self::hydrate( $p, Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'quote_package_items' ) . ' WHERE studio_id=%d AND package_id=%d ORDER BY sort, id', $p->studio_id, $p->id ) ) ) );
	}

	private static function hydrate( $p, array $items ) {
		$d               = json_decode( (string) $p->deliverables, true );
		$c               = json_decode( (string) $p->coverage, true );
		$p->deliverables = is_array( $d ) ? $d : array();
		$p->coverage     = is_array( $c ) ? $c : array();
		$p->items        = $items;
		$p->total        = round( array_sum( array_map( function ( $i ) { return $i->qty * $i->rate; }, $items ) ), 2 );
		return $p;
	}

	public function index( $req ) {
		global $wpdb;
		$active = $req->get_param( 'active' ) ? ' AND is_active=1' : '';
		$rows   = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'quote_packages' ) . " WHERE studio_id=%d$active ORDER BY sort, name", $this->sid() ) ) );
		$items  = array();
		if ( $rows ) {
			foreach ( Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'quote_package_items' ) . ' WHERE studio_id=%d ORDER BY sort, id', $this->sid() ) ) ) as $i ) {
				$items[ $i->package_id ][] = $i;
			}
		}
		return $this->ok(
			array_map(
				function ( $p ) use ( $items ) {
					return self::hydrate( $p, isset( $items[ $p->id ] ) ? $items[ $p->id ] : array() );
				},
				$rows
			)
		);
	}

	/** Validates items, deliverables and coverage from the body. */
	private function extras( $req, $require_items ) {
		$b   = $this->body( $req );
		$out = array();
		if ( isset( $b['items'] ) && is_array( $b['items'] ) ) {
			if ( ! $b['items'] ) {
				return new \WP_Error( 'validation_failed', 'Add at least one service to the package.', array( 'status' => 422, 'errors' => array( 'items' => 'Add at least one service.' ) ) );
			}
			if ( count( $b['items'] ) > 50 ) {
				return new \WP_Error( 'validation_failed', 'A package can have at most 50 services.', array( 'status' => 422 ) );
			}
			$out['items'] = array();
			foreach ( array_values( $b['items'] ) as $i => $it ) {
				$v = Validator::validate( is_array( $it ) ? $it : array(), $this->item_schema() );
				if ( is_wp_error( $v ) ) {
					return new \WP_Error( 'validation_failed', 'Item ' . ( $i + 1 ) . ': ' . $v->get_error_message(), array( 'status' => 422, 'errors' => array( 'items' => 'Item ' . ( $i + 1 ) . ': ' . $v->get_error_message() ) ) );
				}
				$v['sort']      = $i;
				$out['items'][] = $v;
			}
		} elseif ( $require_items ) {
			return new \WP_Error( 'validation_failed', 'Add at least one service to the package.', array( 'status' => 422, 'errors' => array( 'items' => 'Add at least one service.' ) ) );
		}
		if ( array_key_exists( 'deliverables', $b ) ) {
			$d = Quotations::clean_lines( $b['deliverables'], 'Deliverables' );
			if ( is_wp_error( $d ) ) {
				return $d;
			}
			$out['deliverables'] = wp_json_encode( $d );
		}
		if ( array_key_exists( 'coverage', $b ) ) {
			$c = Quotations::clean_coverage( $b['coverage'] );
			if ( is_string( $c ) ) {
				return new \WP_Error( 'validation_failed', $c, array( 'status' => 422, 'errors' => array( 'coverage' => $c ) ) );
			}
			$out['coverage'] = wp_json_encode( (object) $c );
		}
		return $out;
	}

	private function save_items( $pid, array $items ) {
		Db::delete_where( 'quote_package_items', array( 'package_id' => $pid ) );
		foreach ( $items as $it ) {
			$it['package_id'] = $pid;
			Db::insert( 'quote_package_items', $it );
		}
	}

	public function create( $req ) {
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$x = $this->extras( $req, true );
		if ( is_wp_error( $x ) ) {
			return $x;
		}
		$items = $x['items'];
		unset( $x['items'] );
		Db::begin();
		$id = Db::insert( 'quote_packages', array_merge( $data, $x ) );
		if ( is_wp_error( $id ) ) {
			Db::rollback();
			return $id;
		}
		$this->save_items( $id, $items );
		Db::audit( 'quote_package', $id, 'created', 'Package ' . $data['name'] . ' created' );
		Db::commit();
		return $this->ok( self::load( $id ), 201 );
	}

	public function update( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'quote_packages', $id ) ) {
			return $this->not_found( 'Package' );
		}
		$data = $this->validate( $req, $this->schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$x = $this->extras( $req, false );
		if ( is_wp_error( $x ) ) {
			return $x;
		}
		$items = isset( $x['items'] ) ? $x['items'] : null;
		unset( $x['items'] );
		Db::begin();
		Db::update( 'quote_packages', $id, array_merge( $data, $x ) );
		if ( null !== $items ) {
			$this->save_items( $id, $items );
		}
		Db::audit( 'quote_package', $id, 'updated', 'Package edited' );
		Db::commit();
		return $this->ok( self::load( $id ) );
	}

	public function destroy( $req ) {
		global $wpdb;
		$p = Db::find( 'quote_packages', (int) $req['id'] );
		if ( ! $p ) {
			return $this->not_found( 'Package' );
		}
		// Quotations keep their own copy of the lines; only the link to the template is cleared.
		$wpdb->update( Db::t( 'quotations' ), array( 'package_id' => null ), array( 'package_id' => $p->id, 'studio_id' => $this->sid() ) );
		Db::delete_where( 'quote_package_items', array( 'package_id' => $p->id ) );
		Db::delete( 'quote_packages', $p->id );
		Db::audit( 'quote_package', $p->id, 'deleted', 'Package ' . $p->name . ' deleted' );
		return $this->ok( array( 'deleted' => true ) );
	}
}
