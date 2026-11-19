<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;

defined( 'ABSPATH' ) || exit;

class Services extends Base {

	private function schema() {
		return array(
			'category'    => array( 'type' => 'enum', 'options' => Config::service_categories(), 'required' => true ),
			'name'        => array( 'type' => 'string', 'required' => true, 'label' => 'Service name' ),
			'description' => array( 'type' => 'text' ),
			'price'       => array( 'type' => 'money', 'required' => true ),
			'unit'        => array( 'type' => 'string', 'max' => 40 ),
			'is_active'   => array( 'type' => 'bool', 'default' => 1 ),
			'sort'        => array( 'type' => 'int', 'default' => 0 ),
		);
	}

	public function register() {
		$this->route( '/services', 'GET', array( $this, 'index' ), array( 'quotes.view', 'settings.edit', 'bookings.edit', 'bookings.assign' ) );
		$this->route( '/services', 'POST', array( $this, 'create' ), 'settings.edit' );
		$this->route( '/services/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'settings.edit' );
		$this->route( '/services/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'settings.edit' );
	}

	public function index( $req ) {
		global $wpdb;
		$active = $req->get_param( 'active' ) ? ' AND is_active=1' : '';
		return $this->ok( Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'services' ) . " WHERE studio_id=%d$active ORDER BY category, sort, name", $this->sid() ) ) ) );
	}

	public function create( $req ) {
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$id = Db::insert( 'services', $data );
		return is_wp_error( $id ) ? $id : $this->ok( Db::cast( Db::find( 'services', $id ) ), 201 );
	}

	public function update( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'services', $id ) ) {
			return $this->not_found( 'Service' );
		}
		$data = $this->validate( $req, $this->schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		Db::update( 'services', $id, $data );
		return $this->ok( Db::cast( Db::find( 'services', $id ) ) );
	}

	public function destroy( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'services', $id ) ) {
			return $this->not_found( 'Service' );
		}
		global $wpdb;
		$wpdb->update( Db::t( 'quotation_items' ), array( 'service_id' => null ), array( 'service_id' => $id, 'studio_id' => $this->sid() ) );
		Db::delete( 'services', $id );
		return $this->ok( array( 'deleted' => true ) );
	}
}
