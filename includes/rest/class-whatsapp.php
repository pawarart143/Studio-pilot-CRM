<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Tenant;
use PCRM\Whatsapp as WA;

defined( 'ABSPATH' ) || exit;

class Whatsapp extends Base {

	public function register() {
		$this->route( '/whatsapp/templates', 'GET', array( $this, 'templates' ), 'member' );
		$this->route( '/whatsapp/templates', 'POST', array( $this, 'create_template' ), 'settings.edit' );
		$this->route( '/whatsapp/templates/(?P<id>\d+)', 'PUT', array( $this, 'update_template' ), 'settings.edit' );
		$this->route( '/whatsapp/templates/(?P<id>\d+)', 'DELETE', array( $this, 'delete_template' ), 'settings.edit' );
		$this->route( '/whatsapp/render', 'POST', array( $this, 'render' ), 'whatsapp.send' );
		$this->route( '/whatsapp/log', 'POST', array( $this, 'log' ), 'whatsapp.send' );
		$this->route( '/whatsapp/send', 'POST', array( $this, 'send' ), 'whatsapp.send' );
		$this->route( '/whatsapp/status', 'GET', array( $this, 'status' ), 'member' );
	}

	public function templates() {
		global $wpdb;
		return $this->ok( Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'wa_templates' ) . ' WHERE studio_id=%d ORDER BY id', $this->sid() ) ) ) );
	}

	private function schema() {
		return array(
			'name'      => array( 'type' => 'string', 'required' => true ),
			'body'      => array( 'type' => 'text', 'required' => true, 'label' => 'Message' ),
			'is_active' => array( 'type' => 'bool', 'default' => 1 ),
		);
	}

	/** WhatsApp share with ready templates is a Professional / Business feature. */
	private function gate() {
		$r = \PCRM\Plans::check( Tenant::studio(), 'whatsapp_templates' );
		return is_wp_error( $r ) ? $r : null;
	}

	public function create_template( $req ) {
		if ( $g = $this->gate() ) { // phpcs:ignore
			return $g;
		}
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['slug'] = sanitize_key( str_replace( ' ', '_', strtolower( $data['name'] ) ) ) . '_' . wp_rand( 100, 999 );
		$id           = Db::insert( 'wa_templates', $data );
		return is_wp_error( $id ) ? $id : $this->ok( Db::cast( Db::find( 'wa_templates', $id ) ), 201 );
	}

	public function update_template( $req ) {
		if ( $g = $this->gate() ) { // phpcs:ignore
			return $g;
		}
		$id = (int) $req['id'];
		if ( ! Db::exists( 'wa_templates', $id ) ) {
			return $this->not_found( 'Template' );
		}
		$data = $this->validate( $req, $this->schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		Db::update( 'wa_templates', $id, $data );
		return $this->ok( Db::cast( Db::find( 'wa_templates', $id ) ) );
	}

	public function delete_template( $req ) {
		$t = Db::find( 'wa_templates', (int) $req['id'] );
		if ( ! $t ) {
			return $this->not_found( 'Template' );
		}
		if ( array_key_exists( $t->slug, WA::default_templates() ) ) {
			return new \WP_Error( 'forbidden', 'Built-in templates can be edited or turned off, but not deleted.', array( 'status' => 422 ) );
		}
		Db::delete( 'wa_templates', $t->id );
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Fills a template for a record and returns the text plus a wa.me link.
	 */
	public function render( $req ) {
		if ( $g = $this->gate() ) { // phpcs:ignore
			return $g;
		}
		$b   = $this->body( $req );
		$ctx = array();
		if ( ! empty( $b['lead_id'] ) ) {
			$ctx['lead'] = Db::find( 'leads', (int) $b['lead_id'] );
		}
		if ( ! empty( $b['client_id'] ) ) {
			$ctx['client'] = Db::find( 'clients', (int) $b['client_id'] );
		}
		if ( ! empty( $b['quotation_id'] ) ) {
			$ctx['quotation'] = Db::find( 'quotations', (int) $b['quotation_id'] );
			if ( $ctx['quotation'] && $ctx['quotation']->lead_id && empty( $ctx['lead'] ) ) {
				$ctx['lead'] = Db::find( 'leads', $ctx['quotation']->lead_id );
			}
		}
		if ( ! empty( $b['booking_id'] ) ) {
			$ctx['booking'] = Bookings::load( (int) $b['booking_id'] );
			if ( $ctx['booking'] ) {
				$ctx['client'] = $ctx['booking']->client;
			}
		}
		if ( ! empty( $b['deliverable_id'] ) ) {
			$ctx['deliverable'] = Db::find( 'booking_deliverables', (int) $b['deliverable_id'] );
			if ( $ctx['deliverable'] && empty( $ctx['booking'] ) ) {
				$ctx['booking'] = Bookings::load( (int) $ctx['deliverable']->booking_id );
				$ctx['client']  = $ctx['booking'] ? $ctx['booking']->client : null;
			}
		}
		if ( ! empty( $b['event_id'] ) ) {
			$ctx['event'] = Db::find( 'booking_events', (int) $b['event_id'] );
			if ( $ctx['event'] && empty( $ctx['booking'] ) ) {
				$ctx['booking'] = Bookings::load( (int) $ctx['event']->booking_id );
				$ctx['client']  = $ctx['booking']->client;
			}
		}
		foreach ( $ctx as $k => $v ) {
			if ( ! $v ) {
				return $this->not_found( ucfirst( $k ) );
			}
		}
		$body = '';
		if ( ! empty( $b['template_id'] ) ) {
			$t    = Db::find( 'wa_templates', (int) $b['template_id'] );
			$body = $t ? $t->body : '';
		} elseif ( ! empty( $b['template'] ) ) {
			$body = WA::template( sanitize_key( $b['template'] ) );
		}
		if ( ! $body ) {
			return new \WP_Error( 'validation_failed', 'Choose a message template.', array( 'status' => 422 ) );
		}
		// A PIN is only known right after it was generated; only portal admins may fill it in.
		if ( ! empty( $b['portal_pin'] ) && Tenant::can( 'portal.manage' ) && preg_match( '/^\d{4,8}$/', (string) $b['portal_pin'] ) ) {
			$ctx['portal_pin'] = (string) $b['portal_pin'];
		} elseif ( ! empty( $ctx['booking'] ) && false !== strpos( $body, '{{portal_pin}}' ) && Tenant::can( 'portal.manage' ) ) {
			// Owners/admins can share the current PIN at any time (kept encrypted).
			$known = \PCRM\Portals::reveal_pin( \PCRM\Portals::for_booking( (int) $ctx['booking']->id ) );
			if ( $known ) {
				$ctx['portal_pin'] = $known;
			}
		}
		$vars  = WA::vars( $ctx );
		$text  = WA::fill( $body, $vars );
		$phone = ! empty( $b['phone'] ) ? \PCRM\Validator::phone( (string) $b['phone'] ) : $vars['_phone'];
		return $this->ok(
			array(
				'text'           => $text,
				'phone'          => $phone,
				'link'           => $phone ? WA::link( $phone, $text ) : null,
				'api_configured' => WA::api_configured(),
			)
		);
	}

	/**
	 * Records a click-to-chat message in the communication history.
	 */
	public function log( $req ) {
		$b    = $this->body( $req );
		$text = isset( $b['text'] ) ? sanitize_textarea_field( $b['text'] ) : '';
		$refs = array();
		foreach ( array( 'lead_id' => 'leads', 'client_id' => 'clients', 'booking_id' => 'bookings' ) as $k => $t ) {
			if ( ! empty( $b[ $k ] ) && Db::exists( $t, (int) $b[ $k ] ) ) {
				$refs[ $k ] = (int) $b[ $k ];
			}
		}
		if ( ! $refs ) {
			return $this->ok( array( 'ok' => true ) );
		}
		if ( ! empty( $b['quotation_id'] ) ) {
			$q = Db::find( 'quotations', (int) $b['quotation_id'] );
			if ( $q && 'draft' === $q->status ) {
				Quotations::change_status( $q, 'sent' );
			}
		}
		Db::log( 'whatsapp', 'WhatsApp message opened: ' . wp_trim_words( $text, 30 ), $refs, array( 'text' => $text ) );
		return $this->ok( array( 'ok' => true ) );
	}

	public function send( $req ) {
		if ( $g = $this->gate() ) { // phpcs:ignore
			return $g;
		}
		$b     = $this->body( $req );
		$phone = isset( $b['phone'] ) ? \PCRM\Validator::phone( (string) $b['phone'] ) : null;
		$text  = isset( $b['text'] ) ? sanitize_textarea_field( $b['text'] ) : '';
		if ( ! $phone || ! $text ) {
			return new \WP_Error( 'validation_failed', 'Phone number and message are required.', array( 'status' => 422 ) );
		}
		$r = WA::send_api( $phone, $text );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$refs = array();
		foreach ( array( 'lead_id' => 'leads', 'client_id' => 'clients', 'booking_id' => 'bookings' ) as $k => $t ) {
			if ( ! empty( $b[ $k ] ) && Db::exists( $t, (int) $b[ $k ] ) ) {
				$refs[ $k ] = (int) $b[ $k ];
			}
		}
		Db::log( 'whatsapp', 'WhatsApp sent via API: ' . wp_trim_words( $text, 30 ), $refs, array( 'text' => $text, 'message_id' => $r ) );
		return $this->ok( array( 'ok' => true, 'message_id' => $r ) );
	}

	public function status() {
		return $this->ok(
			array(
				'api_configured' => WA::api_configured(),
				'templates'      => \PCRM\Plans::has_feature( Tenant::studio(), 'whatsapp_templates' ),
			)
		);
	}
}
