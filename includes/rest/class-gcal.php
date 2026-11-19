<?php
namespace PCRM\Rest;

use PCRM\Gcal as G;
use PCRM\Installer;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Google Calendar. Owners and admins only (settings.edit).
 * Tokens never leave the server: responses only say whether Google is connected.
 */
class Gcal extends Base {

	public function register() {
		$this->route( '/gcal', 'GET', array( $this, 'show' ), 'settings.edit' );
		$this->route( '/gcal', 'PUT', array( $this, 'save' ), 'settings.edit' );
		$this->route( '/gcal/connect', 'POST', array( $this, 'connect' ), 'settings.edit' );
		$this->route( '/gcal/calendars', 'GET', array( $this, 'calendars' ), 'settings.edit' );
		$this->route( '/gcal/sync', 'POST', array( $this, 'sync' ), 'settings.edit' );
		$this->route( '/gcal/retry', 'POST', array( $this, 'retry' ), 'settings.edit' );
		$this->route( '/gcal/disconnect', 'POST', array( $this, 'disconnect' ), 'settings.edit' );
	}

	private function view() {
		$sid = $this->sid();
		$acc = G::account( $sid );
		$out = array(
			'configured'   => G::configured(),
			'redirect_uri' => G::redirect_uri(),
			'super_admin'  => Tenant::is_super_admin(),
			'connected'    => (bool) $acc,
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
		if ( $acc ) {
			$out += array(
				'status'           => $acc->status,
				'google_email'     => $acc->google_email,
				'calendar_id'      => $acc->calendar_id,
				'calendar_name'    => $acc->calendar_name,
				'kinds'            => array(
					'bookings'  => (bool) (int) $acc->sync_bookings,
					'tasks'     => (bool) (int) $acc->sync_tasks,
					'followups' => (bool) (int) $acc->sync_followups,
					'payments'  => (bool) (int) $acc->sync_payments,
				),
				'reminder_minutes' => (int) $acc->reminder_minutes,
				'last_error'       => $acc->last_error,
				'last_sync_at'     => $acc->last_sync_at,
				'connected_at'     => $acc->connected_at,
				'stats'            => G::stats( $sid ),
			);
		}
		return $out;
	}

	public function show() {
		return $this->ok( $this->view() );
	}

	public function connect() {
		if ( ! G::configured() ) {
			return new \WP_Error( 'not_configured', 'Google Calendar is not set up on this ShootPilot site yet. The platform admin needs to add a Google OAuth client first.', array( 'status' => 422 ) );
		}
		return $this->ok( array( 'url' => G::auth_url( $this->sid(), get_current_user_id() ) ) );
	}

	public function calendars() {
		$acc = G::account( $this->sid() );
		if ( ! $acc || 'connected' !== $acc->status ) {
			return new \WP_Error( 'not_connected', 'Connect Google Calendar first.', array( 'status' => 422 ) );
		}
		$c = G::calendars( $acc );
		if ( is_wp_error( $c ) ) {
			$msg = 'reconnect' === $c->get_error_code() ? 'Google sign-in expired. Please reconnect.' : 'Could not load your calendars from Google: ' . $c->get_error_message();
			return new \WP_Error( 'google_error', $msg, array( 'status' => 502 ) );
		}
		return $this->ok( $c );
	}

	public function save( $req ) {
		global $wpdb;
		$sid = $this->sid();
		$acc = G::account( $sid );
		if ( ! $acc ) {
			return new \WP_Error( 'not_connected', 'Connect Google Calendar first.', array( 'status' => 422 ) );
		}
		$b   = $this->body( $req );
		$row = array();
		if ( isset( $b['calendar_id'] ) ) {
			$cal = sanitize_text_field( (string) $b['calendar_id'] );
			if ( '' === $cal || strlen( $cal ) > 255 ) {
				return new \WP_Error( 'validation_failed', 'Choose a calendar.', array( 'status' => 422, 'errors' => array( 'calendar_id' => 'Choose a calendar.' ) ) );
			}
			if ( $cal !== $acc->calendar_id ) {
				// Only calendars this Google account can write to.
				$list = 'connected' === $acc->status ? G::calendars( $acc ) : new \WP_Error( 'reconnect' );
				if ( is_wp_error( $list ) ) {
					return new \WP_Error( 'google_error', 'Could not check that calendar with Google. Try again.', array( 'status' => 502 ) );
				}
				$match = null;
				foreach ( $list as $c ) {
					if ( $c['id'] === $cal ) {
						$match = $c;
					}
				}
				if ( ! $match ) {
					return new \WP_Error( 'validation_failed', 'You can only choose a calendar you can add events to.', array( 'status' => 422, 'errors' => array( 'calendar_id' => 'Not a writable calendar.' ) ) );
				}
				$row['calendar_id']   = $cal;
				$row['calendar_name'] = $match['name'];
			}
		}
		if ( isset( $b['kinds'] ) && is_array( $b['kinds'] ) ) {
			foreach ( G::KINDS as $k ) {
				if ( array_key_exists( $k, $b['kinds'] ) ) {
					$row[ 'sync_' . $k ] = $b['kinds'][ $k ] ? 1 : 0;
				}
			}
		}
		if ( isset( $b['reminder_minutes'] ) ) {
			$m = (int) $b['reminder_minutes'];
			if ( ! in_array( $m, array( 0, 10, 15, 30, 60, 120, 1440 ), true ) ) {
				return new \WP_Error( 'validation_failed', 'Pick a reminder time from the list.', array( 'status' => 422, 'errors' => array( 'reminder_minutes' => 'Invalid.' ) ) );
			}
			$row['reminder_minutes'] = $m;
		}
		if ( $row ) {
			$row['updated_at'] = current_time( 'mysql' );
			$wpdb->update( Installer::table( 'gcal_accounts' ), $row, array( 'studio_id' => $sid ) );
			G::mark_dirty( $sid );
		}
		return $this->ok( $this->view() );
	}

	/** Runs a sync right now (also what the "Sync now" button does). */
	public function sync() {
		$r = G::run_studio( $this->sid(), 20 );
		return $this->ok( array( 'result' => $r ) + $this->view() );
	}

	public function retry() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Installer::table( 'gcal_queue' ) . " SET status='pending', attempts=0, next_run_at=0 WHERE studio_id=%d", $this->sid() ) );
		return $this->sync();
	}

	public function disconnect( $req ) {
		$b = $this->body( $req );
		$r = G::disconnect( $this->sid(), ! empty( $b['remove_events'] ) );
		return $this->ok( array( 'result' => $r ) + $this->view() );
	}
}
