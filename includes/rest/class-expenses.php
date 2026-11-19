<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

class Expenses extends Base {

	private function schema() {
		return array(
			'category'    => array( 'type' => 'enum', 'options' => Config::expense_categories(), 'required' => true ),
			'description' => array( 'type' => 'string', 'max' => 255 ),
			'amount'      => array( 'type' => 'money', 'required' => true, 'min' => 1 ),
			'spent_on'    => array( 'type' => 'date', 'required' => true, 'label' => 'Date' ),
			'method'      => array( 'type' => 'enum', 'options' => Config::payment_methods(), 'label' => 'Payment method' ),
			'booking_id'  => array( 'type' => 'ref', 'table' => 'bookings', 'label' => 'Booking' ),
		);
	}

	public function register() {
		$this->route( '/expenses', 'GET', array( $this, 'index' ), 'expenses.view' );
		$this->route( '/expenses', 'POST', array( $this, 'create' ), 'expenses.edit' );
		$this->route( '/expenses/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'expenses.edit' );
		$this->route( '/expenses/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'expenses.edit' );
	}

	public function index( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$w    = array( $wpdb->prepare( 'x.studio_id=%d', $this->sid() ) );
		$from = Validator::date( (string) $req->get_param( 'from' ) );
		$to   = Validator::date( (string) $req->get_param( 'to' ) );
		if ( $from ) {
			$w[] = $wpdb->prepare( 'x.spent_on >= %s', $from );
		}
		if ( $to ) {
			$w[] = $wpdb->prepare( 'x.spent_on <= %s', $to );
		}
		if ( $req->get_param( 'category' ) && array_key_exists( $req->get_param( 'category' ), Config::expense_categories() ) ) {
			$w[] = $wpdb->prepare( 'x.category=%s', $req->get_param( 'category' ) );
		}
		if ( $req->get_param( 'booking_id' ) ) {
			$w[] = $wpdb->prepare( 'x.booking_id=%d', $req->get_param( 'booking_id' ) );
		}
		$where = implode( ' AND ', $w );
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'expenses' ) . " x WHERE $where" );
		$sum   = (float) $wpdb->get_var( 'SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'expenses' ) . " x WHERE $where" );
		$rows  = $wpdb->get_results( 'SELECT x.*, b.number AS booking_number, b.title AS booking_title FROM ' . Db::t( 'expenses' ) . ' x LEFT JOIN ' . Db::t( 'bookings' ) . " b ON b.id=x.booking_id WHERE $where ORDER BY x.spent_on DESC, x.id DESC LIMIT $per OFFSET $off" );
		$cats  = $wpdb->get_results( 'SELECT category, SUM(amount) amount FROM ' . Db::t( 'expenses' ) . " x WHERE $where GROUP BY category ORDER BY amount DESC" );
		return $this->ok( array( 'items' => Db::cast_all( $rows ), 'total' => $total, 'sum' => $sum, 'by_category' => Db::cast_all( $cats ), 'page' => $page, 'per_page' => $per ) );
	}

	public function create( $req ) {
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['created_by'] = get_current_user_id();
		$id                 = Db::insert( 'expenses', $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( $data['booking_id'] ) {
			Db::log( 'expense', 'Expense of ₹' . \PCRM\Format::inr( $data['amount'] ) . ' added (' . Config::expense_categories()[ $data['category'] ] . ')', array( 'booking_id' => $data['booking_id'] ) );
		}
		return $this->ok( Db::cast( Db::find( 'expenses', $id ) ), 201 );
	}

	public function update( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'expenses', $id ) ) {
			return $this->not_found( 'Expense' );
		}
		$data = $this->validate( $req, $this->schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		Db::update( 'expenses', $id, $data );
		return $this->ok( Db::cast( Db::find( 'expenses', $id ) ) );
	}

	public function destroy( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'expenses', $id ) ) {
			return $this->not_found( 'Expense' );
		}
		Db::delete( 'expenses', $id );
		return $this->ok( array( 'deleted' => true ) );
	}
}
