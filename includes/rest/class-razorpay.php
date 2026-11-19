<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Razorpay Payment Links for collecting client payments.
 * Each studio uses its own Razorpay account (Key ID + Key Secret in Settings > Online payments).
 * A paid link is recorded as a payment, either by the webhook or by "Check status".
 */
class Razorpay extends Base {

	const API = 'https://api.razorpay.com/v1/';

	public function register() {
		$this->route( '/bookings/(?P<id>\d+)/payment-links', 'GET', array( $this, 'index' ), 'payments.view' );
		$this->route( '/bookings/(?P<id>\d+)/payment-links', 'POST', array( $this, 'create' ), 'payments.edit' );
		$this->route( '/payment-links/(?P<id>\d+)/sync', 'POST', array( $this, 'sync' ), 'payments.view' );
		$this->route( '/payment-links/(?P<id>\d+)/cancel', 'POST', array( $this, 'cancel' ), 'payments.edit' );
		$this->route( '/razorpay/webhook/(?P<studio>\d+)', 'POST', array( $this, 'webhook' ), 'public' );
	}

	private static function keys() {
		$id     = Tenant::setting( 'rzp_key_id' );
		$secret = Tenant::setting( 'rzp_key_secret' );
		return $id && $secret ? array( $id, $secret ) : null;
	}

	/**
	 * Calls the Razorpay API. Returns the decoded body or WP_Error.
	 */
	private static function api( $method, $path, $body = null ) {
		$keys = self::keys();
		if ( ! $keys ) {
			return new \WP_Error( 'not_configured', 'Razorpay is not connected. Add your Key ID and Key Secret in Settings → Online payments.', array( 'status' => 422 ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $keys[0] . ':' . $keys[1] ), // phpcs:ignore
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'gateway_error', 'Could not reach Razorpay: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 300 ) {
			$msg = isset( $data['error']['description'] ) ? $data['error']['description'] : 'HTTP ' . $code;
			if ( 401 === $code ) {
				$msg = 'Razorpay rejected the API keys. Check the Key ID and Key Secret in Settings → Online payments.';
			}
			return new \WP_Error( 'gateway_error', 'Razorpay: ' . $msg, array( 'status' => 502 ) );
		}
		return is_array( $data ) ? $data : array();
	}

	private static function row( $id ) {
		$r = Db::find( 'payment_links', $id );
		return $r ? self::cast( $r ) : null;
	}

	private static function cast( $r ) {
		$r->id             = (int) $r->id;
		$r->booking_id     = (int) $r->booking_id;
		$r->installment_id = $r->installment_id ? (int) $r->installment_id : null;
		$r->amount         = (float) $r->amount;
		$r->payment_id     = $r->payment_id ? (int) $r->payment_id : null;
		return $r;
	}

	public function index( $req ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'payment_links' ) . ' WHERE studio_id=%d AND booking_id=%d ORDER BY id DESC', $this->sid(), (int) $req['id'] ) );
		return $this->ok( array_map( array( __CLASS__, 'cast' ), $rows ) );
	}

	public function create( $req ) {
		$bk = Db::find( 'bookings', (int) $req['id'] );
		if ( ! $bk ) {
			return $this->not_found( 'Booking' );
		}
		if ( 'cancelled' === $bk->status ) {
			return new \WP_Error( 'validation_failed', 'This booking is cancelled.', array( 'status' => 422 ) );
		}
		$data = $this->validate(
			$req,
			array(
				'amount'         => array( 'type' => 'money', 'required' => true, 'min' => 1, 'label' => 'Amount' ),
				'installment_id' => array( 'type' => 'ref', 'table' => 'installments', 'label' => 'Installment' ),
				'description'    => array( 'type' => 'string', 'max' => 200 ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! empty( $data['installment_id'] ) ) {
			$in = Db::find( 'installments', $data['installment_id'] );
			if ( ! $in || (int) $in->booking_id !== (int) $bk->id ) {
				return new \WP_Error( 'validation_failed', 'That installment belongs to another booking.', array( 'status' => 422 ) );
			}
		}
		$sum = Bookings::payment_summary( $bk );
		if ( $data['amount'] - $sum['pending'] > 0.009 ) {
			return new \WP_Error( 'validation_failed', 'Amount is more than the pending balance of ₹' . \PCRM\Format::inr( $sum['pending'] ) . '.', array( 'status' => 422, 'errors' => array( 'amount' => 'More than pending balance.' ) ) );
		}
		$client = Db::find( 'clients', $bk->client_id );
		$studio = Tenant::studio();
		$phone  = $client ? preg_replace( '/\D/', '', (string) $client->phone ) : '';
		if ( 10 === strlen( $phone ) ) {
			$phone = '91' . $phone;
		}
		$customer = array( 'name' => $client ? $client->name : $bk->title );
		if ( $phone ) {
			$customer['contact'] = '+' . $phone;
		}
		if ( $client && is_email( $client->email ) ) {
			$customer['email'] = $client->email;
		}
		$now = Db::now();
		$lid = Db::insert(
			'payment_links',
			array(
				'booking_id'     => (int) $bk->id,
				'installment_id' => ! empty( $data['installment_id'] ) ? (int) $data['installment_id'] : null,
				'amount'         => $data['amount'],
				'gateway_id'     => 'pending',
				'short_url'      => '',
				'status'         => 'creating',
				'created_by'     => get_current_user_id(),
				'created_at'     => $now,
			)
		);
		if ( is_wp_error( $lid ) ) {
			return $lid;
		}
		$desc = ! empty( $data['description'] ) ? $data['description'] : sprintf( '%s – %s', $studio->name, $bk->title );
		$res  = self::api(
			'POST',
			'payment_links',
			array(
				'amount'          => (int) round( $data['amount'] * 100 ),
				'currency'        => 'INR',
				'accept_partial'  => false,
				'description'     => mb_substr( $desc, 0, 200 ),
				'customer'        => $customer,
				'notify'          => array( 'sms' => false, 'email' => false ),
				'reminder_enable' => true,
				'reference_id'    => 'sp' . $studio->id . '-' . $lid . '-' . wp_generate_password( 6, false ),
				'notes'           => array( 'studio_id' => (string) $studio->id, 'booking_id' => (string) $bk->id, 'link_id' => (string) $lid ),
				'callback_url'    => home_url( '/crm/' ),
				'callback_method' => 'get',
			)
		);
		if ( is_wp_error( $res ) || empty( $res['id'] ) || empty( $res['short_url'] ) ) {
			global $wpdb;
			$wpdb->delete( Db::t( 'payment_links' ), array( 'id' => $lid, 'studio_id' => $this->sid() ) );
			return is_wp_error( $res ) ? $res : new \WP_Error( 'gateway_error', 'Razorpay did not return a payment link.', array( 'status' => 502 ) );
		}
		Db::update( 'payment_links', $lid, array( 'gateway_id' => $res['id'], 'short_url' => esc_url_raw( $res['short_url'] ), 'status' => 'created' ) );
		Db::log( 'payment', 'Razorpay payment link for ₹' . \PCRM\Format::inr( $data['amount'] ) . ' created', array( 'booking_id' => (int) $bk->id, 'client_id' => (int) $bk->client_id ) );
		return $this->ok( self::row( $lid ), 201 );
	}

	/**
	 * Applies a Razorpay payment-link entity to our row; records the payment once when paid.
	 */
	public static function apply( $link, array $entity, $payment_ref = null ) {
		$status = isset( $entity['status'] ) ? sanitize_key( $entity['status'] ) : $link->status;
		if ( 'paid' === $link->status && $link->payment_id ) {
			return self::row( $link->id );
		}
		if ( 'paid' !== $status ) {
			if ( $status !== $link->status ) {
				Db::update( 'payment_links', $link->id, array( 'status' => $status ) );
			}
			return self::row( $link->id );
		}
		if ( ! $payment_ref && ! empty( $entity['payments'] ) && is_array( $entity['payments'] ) ) {
			foreach ( $entity['payments'] as $p ) {
				if ( isset( $p['status'] ) && 'captured' === $p['status'] ) {
					$payment_ref = $p['payment_id'];
				}
			}
		}
		// Claim the row first so a webhook and "Check status" running together record it only once.
		global $wpdb;
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'payment_links' ) . " SET status='recording' WHERE id=%d AND studio_id=%d AND status NOT IN ('paid','recording')", $link->id, $link->studio_id ) );
		if ( ! $claimed ) {
			return self::row( $link->id );
		}
		$amount = isset( $entity['amount_paid'] ) ? round( $entity['amount_paid'] / 100, 2 ) : $link->amount;
		$pid    = Payments::record(
			$link->booking_id,
			array(
				'amount'          => $amount,
				'paid_on'         => Db::today(),
				'method'          => 'razorpay',
				'transaction_ref' => $payment_ref ? sanitize_text_field( $payment_ref ) : $link->gateway_id,
				'installment_id'  => $link->installment_id,
				'notes'           => 'Paid online via Razorpay link ' . $link->gateway_id,
			)
		);
		$upd = array(
			'status'             => 'paid',
			'gateway_payment_id' => $payment_ref ? sanitize_text_field( $payment_ref ) : null,
		);
		if ( is_wp_error( $pid ) ) {
			// Paid on Razorpay but could not be recorded (e.g. booking already fully paid by hand).
			$upd['note'] = mb_substr( 'Paid on Razorpay, not recorded: ' . $pid->get_error_message(), 0, 250 );
		} else {
			$upd['payment_id'] = (int) $pid;
		}
		Db::update( 'payment_links', $link->id, $upd );
		return self::row( $link->id );
	}

	public function sync( $req ) {
		$link = self::row( (int) $req['id'] );
		if ( ! $link ) {
			return $this->not_found( 'Payment link' );
		}
		if ( 'paid' === $link->status || 'creating' === $link->status ) {
			return $this->ok( $link );
		}
		$res = self::api( 'GET', 'payment_links/' . rawurlencode( $link->gateway_id ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $this->ok( self::apply( $link, $res ) );
	}

	public function cancel( $req ) {
		$link = self::row( (int) $req['id'] );
		if ( ! $link ) {
			return $this->not_found( 'Payment link' );
		}
		if ( 'paid' === $link->status ) {
			return new \WP_Error( 'validation_failed', 'This link is already paid.', array( 'status' => 422 ) );
		}
		$res = self::api( 'POST', 'payment_links/' . rawurlencode( $link->gateway_id ) . '/cancel', new \stdClass() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $this->ok( self::apply( $link, $res ) );
	}

	/**
	 * Razorpay webhook (event payment_link.paid / cancelled / expired).
	 * URL: /wp-json/pcrm/v1/razorpay/webhook/{studio_id}. Signed with the studio's webhook secret.
	 */
	public function webhook( $req ) {
		$sid = (int) $req['studio'];
		if ( ! Tenant::act_as_studio( $sid ) ) {
			return new \WP_Error( 'not_found', 'Unknown studio.', array( 'status' => 404 ) );
		}
		$secret = Tenant::setting( 'rzp_webhook_secret' );
		$sig    = (string) $req->get_header( 'x_razorpay_signature' );
		$raw    = (string) $req->get_body();
		if ( ! $secret || ! $sig || ! hash_equals( hash_hmac( 'sha256', $raw, $secret ), $sig ) ) {
			return new \WP_Error( 'bad_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}
		$ev     = json_decode( $raw, true );
		$entity = isset( $ev['payload']['payment_link']['entity'] ) ? $ev['payload']['payment_link']['entity'] : null;
		if ( ! $entity || empty( $entity['id'] ) ) {
			return $this->ok( array( 'ignored' => true ) );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'payment_links' ) . ' WHERE studio_id=%d AND gateway_id=%s', $sid, $entity['id'] ) );
		if ( ! $row ) {
			return $this->ok( array( 'ignored' => true ) );
		}
		$pay_ref = isset( $ev['payload']['payment']['entity']['id'] ) ? $ev['payload']['payment']['entity']['id'] : null;
		self::apply( self::cast( $row ), $entity, $pay_ref );
		return $this->ok( array( 'ok' => true ) );
	}
}
