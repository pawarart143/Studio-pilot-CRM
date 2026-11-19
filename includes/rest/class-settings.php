<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Installer;
use PCRM\Seeder;
use PCRM\Studios;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

class Settings extends Base {

	public function register() {
		$this->route( '/settings', 'PUT', array( $this, 'update' ), 'settings.edit' );
		$this->route( '/settings/logo', 'POST', array( $this, 'logo' ), 'settings.edit' );
		$this->route( '/settings/sample-data', 'POST', array( $this, 'sample' ), 'settings.edit' );
		$this->route( '/settings/reset-data', 'POST', array( $this, 'reset_data' ), 'settings.edit' );
	}

	public function update( $req ) {
		global $wpdb;
		$profile = $this->validate(
			$req,
			array(
				'name'    => array( 'type' => 'string', 'required' => true, 'label' => 'Studio name' ),
				'email'   => array( 'type' => 'email' ),
				'phone'   => array( 'type' => 'phone' ),
				'address' => array( 'type' => 'text' ),
				'city'    => array( 'type' => 'string', 'max' => 100 ),
				'state'   => array( 'type' => 'string', 'max' => 100 ),
				'gstin'   => array( 'type' => 'string', 'max' => 20, 'label' => 'GSTIN' ),
			),
			true
		);
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		if ( ! empty( $profile['gstin'] ) && ! preg_match( '/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', strtoupper( $profile['gstin'] ) ) ) {
			return new \WP_Error( 'validation_failed', 'GSTIN format looks wrong (15 characters, e.g. 27ABCDE1234F1Z5).', array( 'status' => 422, 'errors' => array( 'gstin' => 'Invalid GSTIN.' ) ) );
		}
		if ( ! empty( $profile['gstin'] ) ) {
			$profile['gstin'] = strtoupper( $profile['gstin'] );
		}
		if ( $profile ) {
			$profile['updated_at'] = Db::now();
			$wpdb->update( Installer::table( 'studios' ), $profile, array( 'id' => $this->sid() ) );
		}
		$b = $this->body( $req );
		if ( isset( $b['settings'] ) && is_array( $b['settings'] ) ) {
			$in = $b['settings'];
			$s  = \PCRM\Validator::validate(
				$in,
				array(
					'gst_rate'       => array( 'type' => 'decimal', 'maxval' => 28, 'label' => 'GST rate' ),
					'gst_enabled'    => array( 'type' => 'bool' ),
					'quote_prefix'   => array( 'type' => 'string', 'max' => 10, 'label' => 'Quotation prefix' ),
					'booking_prefix' => array( 'type' => 'string', 'max' => 10, 'label' => 'Booking prefix' ),
					'receipt_prefix' => array( 'type' => 'string', 'max' => 10, 'label' => 'Receipt prefix' ),
					'quote_validity' => array( 'type' => 'int', 'label' => 'Quotation validity' ),
					'quote_terms'    => array( 'type' => 'text', 'label' => 'Terms' ),
					'quote_intro'    => array( 'type' => 'text', 'label' => 'Quotation introduction' ),
					'quote_closing'  => array( 'type' => 'text', 'label' => 'Closing message' ),
					'quote_deliverables' => array( 'type' => 'text', 'label' => 'Default deliverables' ),
					'quote_exclusions'   => array( 'type' => 'text', 'label' => 'Not included' ),
					'quote_accent'   => array( 'type' => 'string', 'max' => 7, 'label' => 'Quotation colour' ),
					'quote_show_prices' => array( 'type' => 'bool' ),
					'gst_type'       => array( 'type' => 'enum', 'options' => array( 'intra' => 1, 'inter' => 1 ), 'label' => 'GST type' ),
					'bank_details'   => array( 'type' => 'text', 'label' => 'Bank details' ),
					'upi_id'         => array( 'type' => 'string', 'max' => 100, 'label' => 'UPI ID' ),
					'wa_phone_id'    => array( 'type' => 'string', 'max' => 40, 'label' => 'WhatsApp Phone Number ID' ),
					'wa_token'       => array( 'type' => 'string', 'max' => 600, 'label' => 'WhatsApp access token' ),
					'rzp_key_id'     => array( 'type' => 'string', 'max' => 60, 'label' => 'Razorpay Key ID' ),
					'rzp_key_secret' => array( 'type' => 'string', 'max' => 120, 'label' => 'Razorpay Key Secret' ),
					'rzp_webhook_secret' => array( 'type' => 'string', 'max' => 120, 'label' => 'Razorpay webhook secret' ),
				),
				true
			);
			if ( is_wp_error( $s ) ) {
				return $s;
			}
			foreach ( array( 'quote_prefix', 'booking_prefix', 'receipt_prefix' ) as $k ) {
				if ( isset( $s[ $k ] ) ) {
					$s[ $k ] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $s[ $k ] ) );
					if ( ! $s[ $k ] ) {
						unset( $s[ $k ] );
					}
				}
			}
			foreach ( \PCRM\Config::secret_settings() as $k ) {
				if ( isset( $s[ $k ] ) && strpos( (string) $s[ $k ], '••••' ) === 0 ) {
					unset( $s[ $k ] ); // Masked value sent back unchanged.
				}
			}
			if ( ! empty( $s['rzp_key_id'] ) && ! preg_match( '/^rzp_(test|live)_[A-Za-z0-9]+$/', trim( $s['rzp_key_id'] ) ) ) {
				return new \WP_Error( 'validation_failed', 'Razorpay Key ID should look like rzp_live_XXXX or rzp_test_XXXX.', array( 'status' => 422, 'errors' => array( 'rzp_key_id' => 'Should start with rzp_live_ or rzp_test_.' ) ) );
			}
			foreach ( array( 'rzp_key_id', 'rzp_key_secret', 'rzp_webhook_secret' ) as $k ) {
				if ( isset( $s[ $k ] ) ) {
					$s[ $k ] = trim( $s[ $k ] );
				}
			}
			if ( isset( $s['gst_enabled'] ) ) {
				$s['gst_enabled'] = (bool) $s['gst_enabled'];
			}
			if ( isset( $s['quote_show_prices'] ) ) {
				$s['quote_show_prices'] = (bool) $s['quote_show_prices'];
			}
			if ( ! empty( $s['quote_accent'] ) && ! preg_match( '/^#[0-9A-Fa-f]{6}$/', $s['quote_accent'] ) ) {
				return new \WP_Error( 'validation_failed', 'Quotation colour must be a hex colour like #F3724C.', array( 'status' => 422, 'errors' => array( 'quote_accent' => 'Use a colour like #F3724C.' ) ) );
			}
			foreach ( array( 'quote_deliverables' => 'Default deliverables', 'quote_exclusions' => 'Not included' ) as $k => $label ) {
				if ( isset( $s[ $k ] ) ) {
					$lines = Quotations::clean_lines( $s[ $k ], $label );
					if ( is_wp_error( $lines ) ) {
						return $lines;
					}
					$s[ $k ] = implode( "\n", $lines );
				}
			}
			if ( array_key_exists( 'quote_payment_schedule', $in ) ) {
				$sched = Quotations::clean_schedule( $in['quote_payment_schedule'] );
				if ( is_wp_error( $sched ) ) {
					return $sched;
				}
				$s['quote_payment_schedule'] = $sched;
			}
			Studios::update_settings( $this->sid(), $s );
		}
		Tenant::reset();
		return ( new Auth() )->me();
	}

	public function logo( $req ) {
		global $wpdb;
		$files = $req->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'validation_failed', 'Choose an image.', array( 'status' => 422 ) );
		}
		if ( $files['file']['size'] > 2 * MB_IN_BYTES ) {
			return new \WP_Error( 'validation_failed', 'Logo must be 2 MB or smaller.', array( 'status' => 422 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$_FILES['file'] = $files['file'];
		$aid            = media_handle_upload( 'file', 0, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
		if ( is_wp_error( $aid ) ) {
			return new \WP_Error( 'upload_failed', $aid->get_error_message(), array( 'status' => 422 ) );
		}
		$url = wp_get_attachment_url( $aid );
		$wpdb->update( Installer::table( 'studios' ), array( 'logo_url' => $url ), array( 'id' => $this->sid() ) );
		return $this->ok( array( 'logo_url' => $url ) );
	}

	public function sample() {
		$r = Seeder::sample( $this->sid() );
		return is_wp_error( $r ) ? $r : $this->ok( $r );
	}

	/**
	 * Deletes all business data of the current studio (keeps team, settings, catalogue).
	 */
	public function reset_data( $req ) {
		$b = $this->body( $req );
		if ( ! isset( $b['confirm'] ) || 'DELETE' !== $b['confirm'] ) {
			return new \WP_Error( 'validation_failed', 'Type DELETE to confirm.', array( 'status' => 422 ) );
		}
		if ( 'owner' !== Tenant::member()->role ) {
			return new \WP_Error( 'forbidden', 'Only the studio owner can reset data.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$sid = $this->sid();
		foreach ( array( 'activities', 'calls', 'followups', 'quotation_items', 'quotations', 'event_assignments', 'service_team_assignments', 'booking_services', 'booking_deliverables', 'client_portals', 'payment_activity_logs', 'payment_links', 'booking_events', 'installments', 'payments', 'expenses', 'tasks', 'bookings', 'leads', 'clients' ) as $t ) {
			$wpdb->delete( Db::t( $t ), array( 'studio_id' => $sid ) );
		}
		$docs = $wpdb->get_col( $wpdb->prepare( 'SELECT attachment_id FROM ' . Db::t( 'documents' ) . ' WHERE studio_id=%d', $sid ) );
		foreach ( $docs as $a ) {
			wp_delete_attachment( (int) $a, true );
		}
		$wpdb->delete( Db::t( 'documents' ), array( 'studio_id' => $sid ) );
		return $this->ok( array( 'ok' => true ) );
	}
}
