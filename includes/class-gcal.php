<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Google Calendar sync (one way: ShootPilot → Google).
 *
 * Each studio connects its own Google account in Settings → Google Calendar.
 * Bookings (every event date and venue), tasks, follow-up calls and payment
 * due dates are pushed to the calendar the studio chooses.
 *
 * How it works:
 * - Every successful change in the CRM API marks the studio as "dirty" and
 *   wakes a background run (WP-Cron). A run compares what the calendar should
 *   show (Gcal_Events::desired) with what ShootPilot already sent
 *   (pcrm_gcal_links) and queues one job per record that differs.
 * - The worker sends the jobs to Google. Each record has a fixed Google event
 *   id, so a retry can never create a second copy, and an edit updates the
 *   same event.
 * - Failures never touch the CRM record. Network errors, 429 and 5xx are
 *   retried with back-off; an expired or revoked token puts the studio in
 *   "reconnect" state and keeps the queue until it reconnects.
 * - ShootPilot only edits or deletes events it created itself (tracked in
 *   pcrm_gcal_links and tagged in extendedProperties). Other events in the
 *   calendar are never touched.
 *
 * The schema has its own version option (pcrm_gcal_db_version), separate from
 * PCRM_DB_VERSION, so it merges cleanly with other feature branches.
 */
class Gcal {

	const DB_VERSION   = '1';
	const SCOPES       = 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendarlist.readonly';
	const KINDS        = array( 'bookings', 'tasks', 'followups', 'payments' );
	const MAX_ATTEMPTS = 8;
	const BATCH        = 40;

	/** Set when a CRM write happened during this request. */
	private static $dirty = array();

	public static function init() {
		self::maybe_install();
		if ( isset( $_GET['pcrm_gcal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			add_action( 'init', array( __CLASS__, 'handle_oauth' ), 20 );
		}
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'pcrm_gcal_tick', array( __CLASS__, 'tick' ) );
		add_action( 'pcrm_gcal_run', array( __CLASS__, 'run_studio' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'after_rest' ), 20, 3 );
		add_action( 'shutdown', array( __CLASS__, 'schedule_dirty' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		if ( ! wp_next_scheduled( 'pcrm_gcal_tick' ) ) {
			wp_schedule_event( time() + 120, 'pcrm_five_minutes', 'pcrm_gcal_tick' );
		}
	}

	public static function register_rest() {
		( new Rest\Gcal() )->register();
	}

	public static function cron_schedules( $s ) {
		$s['pcrm_five_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 minutes (ShootPilot)' );
		return $s;
	}

	/* ------------------------------------------------------------------ */
	/* Schema                                                              */
	/* ------------------------------------------------------------------ */

	public static function maybe_install() {
		if ( get_option( 'pcrm_gcal_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c   = $wpdb->get_charset_collate();
		$a   = Installer::table( 'gcal_accounts' );
		$l   = Installer::table( 'gcal_links' );
		$q   = Installer::table( 'gcal_queue' );
		dbDelta(
			"CREATE TABLE $a (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			google_email VARCHAR(190) NULL,
			refresh_token TEXT NULL,
			access_token TEXT NULL,
			access_expires INT UNSIGNED NOT NULL DEFAULT 0,
			calendar_id VARCHAR(255) NOT NULL DEFAULT 'primary',
			calendar_name VARCHAR(255) NULL,
			sync_bookings TINYINT(1) NOT NULL DEFAULT 1,
			sync_tasks TINYINT(1) NOT NULL DEFAULT 1,
			sync_followups TINYINT(1) NOT NULL DEFAULT 1,
			sync_payments TINYINT(1) NOT NULL DEFAULT 1,
			reminder_minutes INT NOT NULL DEFAULT 60,
			status VARCHAR(20) NOT NULL DEFAULT 'connected',
			last_error TEXT NULL,
			last_sync_at DATETIME NULL,
			connected_by BIGINT UNSIGNED NULL,
			connected_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY studio_id (studio_id)
		) $c;"
		);
		dbDelta(
			"CREATE TABLE $l (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			entity_key VARCHAR(60) NOT NULL,
			kind VARCHAR(20) NOT NULL,
			calendar_id VARCHAR(255) NOT NULL,
			google_event_id VARCHAR(120) NOT NULL,
			hash CHAR(32) NOT NULL,
			html_link VARCHAR(500) NULL,
			synced_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY studio_key (studio_id,entity_key)
		) $c;"
		);
		dbDelta(
			"CREATE TABLE $q (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			entity_key VARCHAR(60) NOT NULL,
			attempts INT NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			next_run_at INT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY studio_key (studio_id,entity_key),
			KEY studio_due (studio_id,status,next_run_at)
		) $c;"
		);
		update_option( 'pcrm_gcal_db_version', self::DB_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* OAuth client (set by the super admin, shared by all studios)        */
	/* ------------------------------------------------------------------ */

	/**
	 * Client ID / secret: wp-config constants first, then the calendar-specific
	 * options, then the "Google sign-in" client the super admin already set up.
	 */
	public static function client() {
		$id     = defined( 'PCRM_GCAL_CLIENT_ID' ) ? PCRM_GCAL_CLIENT_ID : get_option( 'pcrm_gcal_client_id', '' );
		$secret = defined( 'PCRM_GCAL_CLIENT_SECRET' ) ? PCRM_GCAL_CLIENT_SECRET : get_option( 'pcrm_gcal_client_secret', '' );
		if ( ! $id || ! $secret ) {
			$id     = get_option( 'pcrm_google_client_id', '' );
			$secret = get_option( 'pcrm_google_client_secret', '' );
		}
		return array( trim( (string) $id ), trim( (string) $secret ) );
	}

	public static function configured() {
		list( $id, $secret ) = self::client();
		return $id && $secret;
	}

	public static function redirect_uri() {
		return add_query_arg( 'pcrm_gcal', 'callback', home_url( '/' ) );
	}

	/** Google endpoints. Filterable so tests can point them at a mock server. */
	public static function endpoint( $name ) {
		$e = apply_filters(
			'pcrm_gcal_endpoints',
			array(
				'auth'     => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token'    => 'https://oauth2.googleapis.com/token',
				'revoke'   => 'https://oauth2.googleapis.com/revoke',
				'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
				'api'      => 'https://www.googleapis.com/calendar/v3',
			)
		);
		return $e[ $name ];
	}

	/**
	 * Builds the Google consent URL for the current member's studio. The state
	 * is random, kept server side for 15 minutes and bound to this user+studio.
	 */
	public static function auth_url( $studio_id, $user_id ) {
		list( $id ) = self::client();
		$state      = wp_generate_password( 40, false );
		set_transient( 'pcrm_gcal_state_' . $state, array( 'studio_id' => (int) $studio_id, 'user_id' => (int) $user_id ), 15 * MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				'client_id'              => rawurlencode( $id ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPES ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			self::endpoint( 'auth' )
		);
	}

	private static function back( $result ) {
		wp_safe_redirect( home_url( '/crm/settings?tab=calendar&gcal=' . rawurlencode( $result ) ) );
		exit;
	}

	public static function handle_oauth() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( 'callback' !== sanitize_key( wp_unslash( $_GET['pcrm_gcal'] ) ) ) {
			return;
		}
		Plugin::no_cache();
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable
		$st = $state ? get_transient( 'pcrm_gcal_state_' . $state ) : false;
		if ( $state ) {
			delete_transient( 'pcrm_gcal_state_' . $state );
		}
		if ( ! $st || ! is_user_logged_in() || (int) $st['user_id'] !== get_current_user_id() ) {
			self::back( 'state' );
		}
		if ( $error ) {
			self::back( 'cancelled' );
		}
		$res = self::complete_connect( (int) $st['studio_id'], (int) $st['user_id'], $code );
		self::back( is_wp_error( $res ) ? $res->get_error_code() : 'connected' );
	}

	/**
	 * Exchanges the code for tokens and saves the account. Public for tests.
	 */
	public static function complete_connect( $studio_id, $user_id, $code ) {
		global $wpdb;
		// The user must still be an owner/admin of that studio.
		$role = $wpdb->get_var( $wpdb->prepare( 'SELECT role FROM ' . Installer::table( 'members' ) . " WHERE studio_id=%d AND user_id=%d AND status='active'", $studio_id, $user_id ) );
		if ( ! $role || ! in_array( 'settings.edit', Permissions::for_role( $role ), true ) ) {
			return new \WP_Error( 'forbidden' );
		}
		if ( ! $code || ! self::configured() ) {
			return new \WP_Error( 'not_configured' );
		}
		list( $cid, $secret ) = self::client();
		$res = wp_remote_post(
			self::endpoint( 'token' ),
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $cid,
					'client_secret' => $secret,
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		$tok = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $tok['access_token'] ) ) {
			error_log( '[pcrm] Google Calendar token exchange failed: ' . ( is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_body( $res ) ) ); // phpcs:ignore
			return new \WP_Error( 'token' );
		}
		$granted = isset( $tok['scope'] ) ? explode( ' ', $tok['scope'] ) : array();
		if ( $granted && ! in_array( 'https://www.googleapis.com/auth/calendar.events', $granted, true ) && ! in_array( 'https://www.googleapis.com/auth/calendar', $granted, true ) ) {
			return new \WP_Error( 'scope' );
		}
		$existing = self::account( $studio_id );
		$refresh  = ! empty( $tok['refresh_token'] ) ? $tok['refresh_token'] : ( $existing ? self::decrypt( $existing->refresh_token, $studio_id ) : null );
		if ( ! $refresh ) {
			return new \WP_Error( 'no_refresh' );
		}
		$email = '';
		$info  = wp_remote_get( self::endpoint( 'userinfo' ), array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $tok['access_token'] ) ) );
		if ( ! is_wp_error( $info ) && 200 === wp_remote_retrieve_response_code( $info ) ) {
			$u     = json_decode( wp_remote_retrieve_body( $info ), true );
			$email = isset( $u['email'] ) ? sanitize_email( $u['email'] ) : '';
		}
		$row = array(
			'google_email'   => $email,
			'refresh_token'  => self::encrypt( $refresh, $studio_id ),
			'access_token'   => self::encrypt( $tok['access_token'], $studio_id ),
			'access_expires' => time() + (int) ( $tok['expires_in'] ?? 3600 ) - 60,
			'status'         => 'connected',
			'last_error'     => null,
			'connected_by'   => $user_id,
			'connected_at'   => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);
		if ( $existing ) {
			// A different Google account means a different calendar: start over.
			if ( $existing->google_email && $email && strcasecmp( $existing->google_email, $email ) ) {
				$row['calendar_id']   = 'primary';
				$row['calendar_name'] = null;
				$wpdb->delete( Installer::table( 'gcal_links' ), array( 'studio_id' => $studio_id ) );
			}
			$wpdb->update( Installer::table( 'gcal_accounts' ), $row, array( 'studio_id' => $studio_id ) );
			// Failed jobs get a fresh start after reconnecting.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Installer::table( 'gcal_queue' ) . " SET status='pending', attempts=0, next_run_at=0 WHERE studio_id=%d", $studio_id ) );
		} else {
			$row['studio_id']     = $studio_id;
			$row['calendar_id']   = 'primary';
			$row['calendar_name'] = $email ? $email : null;
			$wpdb->insert( Installer::table( 'gcal_accounts' ), $row );
		}
		Db::audit( 'settings', $studio_id, 'gcal_connected', 'Google Calendar connected' . ( $email ? ' (' . $email . ')' : '' ), null, $studio_id );
		self::mark_dirty( $studio_id );
		self::schedule_dirty();
		return true;
	}

	/**
	 * Disconnects the studio. With $remove_events, ShootPilot first deletes the
	 * events it added (only those) while the token still works.
	 */
	public static function disconnect( $studio_id, $remove_events = false ) {
		global $wpdb;
		$acc     = self::account( $studio_id );
		$removed = 0;
		$left    = 0;
		if ( ! $acc ) {
			return array( 'removed' => 0, 'left' => 0 );
		}
		if ( $remove_events && 'connected' === $acc->status ) {
			$links = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'gcal_links' ) . ' WHERE studio_id=%d', $studio_id ) );
			$until = time() + 25;
			foreach ( $links as $lk ) {
				if ( time() > $until ) {
					++$left;
					continue;
				}
				$r = self::api( $acc, 'DELETE', '/calendars/' . rawurlencode( $lk->calendar_id ) . '/events/' . rawurlencode( $lk->google_event_id ) );
				if ( ! is_wp_error( $r ) || in_array( $r->get_error_code(), array( 'http_404', 'http_410' ), true ) ) {
					++$removed;
				} else {
					++$left;
				}
			}
		}
		$token = self::decrypt( $acc->refresh_token, $studio_id );
		if ( $token ) {
			wp_remote_post( self::endpoint( 'revoke' ), array( 'timeout' => 10, 'body' => array( 'token' => $token ) ) );
		}
		$wpdb->delete( Installer::table( 'gcal_accounts' ), array( 'studio_id' => $studio_id ) );
		$wpdb->delete( Installer::table( 'gcal_links' ), array( 'studio_id' => $studio_id ) );
		$wpdb->delete( Installer::table( 'gcal_queue' ), array( 'studio_id' => $studio_id ) );
		Db::audit( 'settings', $studio_id, 'gcal_disconnected', 'Google Calendar disconnected' . ( $remove_events ? " ($removed events removed)" : '' ), null, $studio_id );
		return array( 'removed' => $removed, 'left' => $left );
	}

	/* ------------------------------------------------------------------ */
	/* Accounts and tokens                                                 */
	/* ------------------------------------------------------------------ */

	public static function account( $studio_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'gcal_accounts' ) . ' WHERE studio_id=%d', $studio_id ) );
		return $row ? $row : null;
	}

	public static function kinds( $acc ) {
		$out = array();
		foreach ( self::KINDS as $k ) {
			if ( (int) $acc->{'sync_' . $k} ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	private static function key( $studio_id ) {
		return hash_hmac( 'sha256', 'pcrm-gcal-token|' . (int) $studio_id, wp_salt( 'secure_auth' ), true );
	}

	/** Tokens are stored encrypted (libsodium secretbox), never in plain text. */
	public static function encrypt( $plain, $studio_id ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, self::key( $studio_id ) ) );
	}

	public static function decrypt( $enc, $studio_id ) {
		if ( ! $enc || 0 !== strpos( $enc, 'v1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $enc, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$p = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key( $studio_id ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $p ? null : $p;
	}

	private static function set_status( $acc, $status, $error = null ) {
		global $wpdb;
		$wpdb->update( Installer::table( 'gcal_accounts' ), array( 'status' => $status, 'last_error' => $error, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $acc->id ) );
		$acc->status     = $status;
		$acc->last_error = $error;
	}

	/**
	 * A valid access token, refreshed when needed. WP_Error('reconnect') when
	 * Google says the refresh token is no longer valid.
	 */
	public static function access_token( $acc, $force = false ) {
		global $wpdb;
		if ( ! $force && $acc->access_token && (int) $acc->access_expires > time() ) {
			$t = self::decrypt( $acc->access_token, $acc->studio_id );
			if ( $t ) {
				return $t;
			}
		}
		$refresh = self::decrypt( $acc->refresh_token, $acc->studio_id );
		if ( ! $refresh ) {
			self::set_status( $acc, 'reconnect', 'The saved Google sign-in can no longer be read (the site security keys may have changed). Please reconnect.' );
			return new \WP_Error( 'reconnect' );
		}
		list( $cid, $secret ) = self::client();
		$res = wp_remote_post(
			self::endpoint( 'token' ),
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => $cid,
					'client_secret' => $secret,
					'refresh_token' => $refresh,
					'grant_type'    => 'refresh_token',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'network', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$tok  = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 === $code && ! empty( $tok['access_token'] ) ) {
			$acc->access_token   = self::encrypt( $tok['access_token'], $acc->studio_id );
			$acc->access_expires = time() + (int) ( $tok['expires_in'] ?? 3600 ) - 60;
			$wpdb->update( Installer::table( 'gcal_accounts' ), array( 'access_token' => $acc->access_token, 'access_expires' => $acc->access_expires ), array( 'id' => $acc->id ) );
			return $tok['access_token'];
		}
		$err = isset( $tok['error'] ) ? (string) $tok['error'] : 'http_' . $code;
		if ( in_array( $err, array( 'invalid_grant', 'unauthorized_client', 'invalid_client' ), true ) ) {
			$msg = 'invalid_client' === $err ? 'The Google OAuth client was changed or removed by the platform admin.' : 'Google sign-in expired or access was removed.';
			self::set_status( $acc, 'reconnect', $msg . ' Reconnect Google Calendar to resume syncing.' );
			return new \WP_Error( 'reconnect' );
		}
		return new \WP_Error( $code >= 500 || 429 === $code ? 'http_' . $code : 'token_' . $err, 'Google token refresh failed (' . $err . ').' );
	}

	/**
	 * Calls the Calendar API. Returns the decoded body or WP_Error with codes
	 * http_<status>, network or reconnect.
	 */
	public static function api( $acc, $method, $path, $body = null, $query = array() ) {
		$token = self::access_token( $acc );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = self::endpoint( 'api' ) . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		for ( $try = 0; $try < 2; $try++ ) {
			$args = array(
				'method'  => $method,
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			);
			if ( null !== $body ) {
				$args['body'] = wp_json_encode( $body );
			}
			$res = wp_remote_request( $url, $args );
			if ( is_wp_error( $res ) ) {
				return new \WP_Error( 'network', $res->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 401 === $code && 0 === $try ) {
				$token = self::access_token( $acc, true );
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				continue;
			}
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( $code >= 200 && $code < 300 ) {
				return is_array( $data ) ? $data : array();
			}
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			// 403 rate limits are retryable like 429.
			$reason = isset( $data['error']['errors'][0]['reason'] ) ? $data['error']['errors'][0]['reason'] : '';
			if ( 403 === $code && in_array( $reason, array( 'rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded' ), true ) ) {
				$code = 429;
			}
			if ( 401 === $code ) {
				self::set_status( $acc, 'reconnect', 'Google rejected the saved sign-in. Reconnect Google Calendar to resume syncing.' );
				return new \WP_Error( 'reconnect', $msg );
			}
			return new \WP_Error( 'http_' . $code, $msg );
		}
		return new \WP_Error( 'reconnect' );
	}

	/** Calendars the account can add events to. */
	public static function calendars( $acc ) {
		$r = self::api( $acc, 'GET', '/users/me/calendarList', null, array( 'minAccessRole' => 'writer', 'maxResults' => 250 ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = array();
		foreach ( (array) ( $r['items'] ?? array() ) as $c ) {
			$out[] = array(
				'id'      => (string) $c['id'],
				'name'    => (string) ( $c['summaryOverride'] ?? ( $c['summary'] ?? $c['id'] ) ),
				'primary' => ! empty( $c['primary'] ),
				'color'   => (string) ( $c['backgroundColor'] ?? '' ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $a['primary'] === $b['primary'] ? strcasecmp( $a['name'], $b['name'] ) : ( $a['primary'] ? -1 : 1 );
			}
		);
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Triggers                                                            */
	/* ------------------------------------------------------------------ */

	public static function mark_dirty( $studio_id ) {
		if ( $studio_id ) {
			self::$dirty[ (int) $studio_id ] = true;
		}
	}

	/**
	 * Any successful write to the CRM API may change what the calendar should
	 * show. We don't care which module it was: the next run works it out.
	 */
	public static function after_rest( $res, $server, $req ) {
		if ( 'GET' === $req->get_method() || 0 !== strpos( $req->get_route(), '/' . Plugin::REST_NS . '/' ) || 0 === strpos( $req->get_route(), '/' . Plugin::REST_NS . '/gcal' ) ) {
			return $res;
		}
		if ( $res instanceof \WP_REST_Response && $res->get_status() < 400 ) {
			$sid = Tenant::studio_id();
			if ( ! $sid && preg_match( '#/razorpay/webhook/(\d+)#', $req->get_route(), $m ) ) {
				$sid = (int) $m[1];
			}
			self::mark_dirty( $sid );
		}
		return $res;
	}

	/** At the end of the request, wake a background run for dirty studios. */
	public static function schedule_dirty() {
		if ( ! self::$dirty ) {
			return;
		}
		global $wpdb;
		$ids         = array_keys( self::$dirty );
		self::$dirty = array();
		$in          = implode( ',', array_map( 'intval', $ids ) );
		$connected   = $wpdb->get_col( 'SELECT studio_id FROM ' . Installer::table( 'gcal_accounts' ) . " WHERE studio_id IN ($in) AND status='connected'" );
		$spawn       = false;
		// On PHP-FPM / LiteSpeed the response can be sent first and the sync
		// done right after, so the change reaches Google within seconds.
		$finish = function_exists( 'fastcgi_finish_request' ) ? 'fastcgi_finish_request' : ( function_exists( 'litespeed_finish_request' ) ? 'litespeed_finish_request' : null );
		if ( $finish && $connected && 'shutdown' === current_action() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && apply_filters( 'pcrm_gcal_sync_after_response', true ) ) {
			while ( ob_get_level() > 0 ) {
				@ob_end_flush(); // phpcs:ignore
			}
			flush();
			call_user_func( $finish );
			ignore_user_abort( true );
			foreach ( $connected as $sid ) {
				self::run_studio( (int) $sid, 15 );
			}
			return;
		}
		foreach ( $connected as $sid ) {
			update_option( 'pcrm_gcal_dirty_' . (int) $sid, time(), false );
			if ( ! wp_next_scheduled( 'pcrm_gcal_run', array( (int) $sid ) ) ) {
				wp_schedule_single_event( time(), 'pcrm_gcal_run', array( (int) $sid ) );
				$spawn = true;
			}
		}
		if ( $spawn && ! wp_doing_cron() && function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}

	/** Every 5 minutes: all connected studios (catches retries and edits made outside the API). */
	public static function tick() {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT studio_id FROM ' . Installer::table( 'gcal_accounts' ) . " WHERE status='connected' ORDER BY last_sync_at IS NOT NULL, last_sync_at ASC LIMIT 50" );
		$end = time() + 50;
		foreach ( $ids as $sid ) {
			if ( time() > $end ) {
				break;
			}
			self::run_studio( (int) $sid );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sync                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Reconcile + work the queue for one studio. Safe to call any time: a
	 * MySQL lock makes sure two runs for the same studio never overlap.
	 */
	public static function run_studio( $studio_id, $budget = 40 ) {
		global $wpdb;
		$studio_id = (int) $studio_id;
		$acc       = self::account( $studio_id );
		if ( ! $acc || 'connected' !== $acc->status ) {
			return array( 'ok' => false, 'reason' => $acc ? $acc->status : 'not_connected' );
		}
		$lock = 'pcrm_gcal_' . $studio_id;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return array( 'ok' => false, 'reason' => 'busy' );
		}
		$prev_user = get_current_user_id();
		try {
			Tenant::reset();
			if ( ! Tenant::act_as_studio( $studio_id ) ) {
				return array( 'ok' => false, 'reason' => 'no_owner' );
			}
			delete_option( 'pcrm_gcal_dirty_' . $studio_id );
			$queued = self::reconcile( $acc );
			$done   = self::work( $acc, $budget );
			$wpdb->update( Installer::table( 'gcal_accounts' ), array( 'last_sync_at' => current_time( 'mysql' ) ), array( 'id' => $acc->id ) );
			// More jobs ready now (e.g. the first sync of a busy studio): keep going in the background.
			$ready = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'gcal_queue' ) . " WHERE studio_id=%d AND status='pending' AND next_run_at<=%d", $studio_id, time() ) );
			if ( $ready && 'connected' === $acc->status && ! wp_next_scheduled( 'pcrm_gcal_run', array( $studio_id ) ) ) {
				wp_schedule_single_event( time() + 5, 'pcrm_gcal_run', array( $studio_id ) );
			}
			return array_merge( array( 'ok' => true, 'queued' => $queued ), $done );
		} catch ( \Throwable $e ) {
			error_log( '[pcrm] Google Calendar sync: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore
			return array( 'ok' => false, 'reason' => 'error' );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			Tenant::reset();
			wp_set_current_user( $prev_user );
		}
	}

	/**
	 * Compares the desired calendar with what was sent and queues the
	 * differences. Returns how many jobs were added.
	 */
	public static function reconcile( $acc ) {
		global $wpdb;
		$sid     = (int) $acc->studio_id;
		$desired = Gcal_Events::desired( $acc );
		$links   = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT entity_key, hash, calendar_id FROM ' . Installer::table( 'gcal_links' ) . ' WHERE studio_id=%d', $sid ) ) as $l ) {
			$links[ $l->entity_key ] = $l;
		}
		$keys = array();
		foreach ( $desired as $key => $ev ) {
			$l = isset( $links[ $key ] ) ? $links[ $key ] : null;
			if ( ! $l || $l->hash !== self::hash( $ev, $acc ) || $l->calendar_id !== $acc->calendar_id ) {
				$keys[] = $key;
			}
		}
		foreach ( $links as $key => $l ) {
			if ( ! isset( $desired[ $key ] ) ) {
				// Gone from the sync window, or deleted / cancelled / turned off.
				// The worker re-checks the single record before deleting anything.
				$keys[] = $key;
			}
		}
		$n = 0;
		foreach ( $keys as $key ) {
			$n += self::enqueue( $sid, $key );
		}
		return $n;
	}

	public static function enqueue( $sid, $key ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		// New job, or wake an existing one (keeps the attempt count of a failing job).
		$r = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Installer::table( 'gcal_queue' ) . " (studio_id, entity_key, attempts, status, next_run_at, created_at, updated_at) VALUES (%d, %s, 0, 'pending', 0, %s, %s) ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)", $sid, $key, $now, $now ) );
		return 1 === (int) $r ? 1 : 0;
	}

	public static function hash( $ev, $acc ) {
		return md5( wp_json_encode( $ev ) . '|' . $acc->calendar_id );
	}

	/** Fixed Google event id per record (base32hex), so retries never duplicate. */
	public static function event_id( $sid, $key ) {
		return 'sp' . md5( home_url() . '|' . (int) $sid . '|' . $key );
	}

	private static function backoff( $attempts ) {
		$steps = array( 60, 300, 900, 1800, 3600, 3 * 3600, 6 * 3600, 12 * 3600 );
		return $steps[ min( $attempts, count( $steps ) ) - 1 ];
	}

	/** Sends due jobs to Google. */
	public static function work( $acc, $budget = 40 ) {
		global $wpdb;
		$sid   = (int) $acc->studio_id;
		$qt    = Installer::table( 'gcal_queue' );
		$jobs  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $qt WHERE studio_id=%d AND status='pending' AND next_run_at<=%d ORDER BY id LIMIT %d", $sid, time(), self::BATCH ) );
		$end   = time() + $budget;
		$stats = array( 'sent' => 0, 'failed' => 0, 'retry' => 0, 'remaining' => 0 );
		foreach ( $jobs as $job ) {
			if ( time() > $end ) {
				break;
			}
			$r = self::process( $acc, $job->entity_key );
			if ( true === $r ) {
				$wpdb->delete( $qt, array( 'id' => $job->id ) );
				++$stats['sent'];
				continue;
			}
			if ( 'reconnect' === $r->get_error_code() ) {
				$wpdb->update( $qt, array( 'last_error' => 'Waiting for Google to be reconnected.', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $job->id ) );
				break;
			}
			$attempts  = (int) $job->attempts + 1;
			$code      = $r->get_error_code();
			$transient = in_array( $code, array( 'network', 'http_429', 'http_500', 'http_502', 'http_503', 'http_504', 'http_409', 'http_412' ), true ) || 0 === strpos( $code, 'token_' );
			$failed    = $attempts >= self::MAX_ATTEMPTS || ( ! $transient && $attempts >= 3 );
			$wpdb->update(
				$qt,
				array(
					'attempts'    => $attempts,
					'status'      => $failed ? 'failed' : 'pending',
					'next_run_at' => time() + self::backoff( $attempts ),
					'last_error'  => substr( $r->get_error_message() ? $r->get_error_message() : $code, 0, 500 ),
					'updated_at'  => current_time( 'mysql' ),
				),
				array( 'id' => $job->id )
			);
			++$stats[ $failed ? 'failed' : 'retry' ];
		}
		$stats['remaining'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $qt WHERE studio_id=%d AND status='pending'", $sid ) );
		return $stats;
	}

	/**
	 * Brings one record's Google event in line. true on success.
	 */
	public static function process( $acc, $key ) {
		global $wpdb;
		$sid  = (int) $acc->studio_id;
		$lt   = Installer::table( 'gcal_links' );
		$link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $lt WHERE studio_id=%d AND entity_key=%s", $sid, $key ) );
		$ev   = Gcal_Events::one( $acc, $key );

		if ( null === $ev ) {
			if ( ! $link ) {
				return true;
			}
			if ( Gcal_Events::outside_window( $acc, $key ) ) {
				// Old record that left the sync window: keep the Google event, stop tracking it.
				$wpdb->delete( $lt, array( 'id' => $link->id ) );
				return true;
			}
			$r = self::api( $acc, 'DELETE', '/calendars/' . rawurlencode( $link->calendar_id ) . '/events/' . rawurlencode( $link->google_event_id ) );
			if ( is_wp_error( $r ) && ! in_array( $r->get_error_code(), array( 'http_404', 'http_410' ), true ) ) {
				return $r;
			}
			$wpdb->delete( $lt, array( 'id' => $link->id ) );
			return true;
		}

		$hash = self::hash( $ev, $acc );
		if ( $link && $link->hash === $hash && $link->calendar_id === $acc->calendar_id ) {
			return true;
		}
		$eid  = $link ? $link->google_event_id : self::event_id( $sid, $key );
		$body = $ev;
		$body['status'] = 'confirmed';
		$base = '/calendars/' . rawurlencode( $acc->calendar_id ) . '/events';

		if ( $link && $link->calendar_id !== $acc->calendar_id ) {
			// The studio picked another calendar: remove ours from the old one.
			$d = self::api( $acc, 'DELETE', '/calendars/' . rawurlencode( $link->calendar_id ) . '/events/' . rawurlencode( $link->google_event_id ) );
			if ( is_wp_error( $d ) && ! in_array( $d->get_error_code(), array( 'http_404', 'http_410' ), true ) ) {
				return $d;
			}
		}
		if ( $link && $link->calendar_id === $acc->calendar_id ) {
			$r = self::api( $acc, 'PUT', $base . '/' . rawurlencode( $eid ), $body );
			if ( is_wp_error( $r ) && in_array( $r->get_error_code(), array( 'http_404', 'http_410' ), true ) ) {
				$r = null; // Someone deleted it in Google: create it again.
			}
		} else {
			$r = null;
		}
		if ( null === $r ) {
			$body['id'] = $eid;
			$r          = self::api( $acc, 'POST', $base, $body );
			if ( is_wp_error( $r ) && 'http_409' === $r->get_error_code() ) {
				// The id exists already (an earlier try that timed out, or a deleted copy): update it.
				unset( $body['id'] );
				$r = self::api( $acc, 'PUT', $base . '/' . rawurlencode( $eid ), $body );
			}
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$row = array(
			'studio_id'       => $sid,
			'entity_key'      => $key,
			'kind'            => strstr( $key, ':', true ),
			'calendar_id'     => $acc->calendar_id,
			'google_event_id' => isset( $r['id'] ) ? (string) $r['id'] : $eid,
			'hash'            => $hash,
			'html_link'       => isset( $r['htmlLink'] ) ? substr( (string) $r['htmlLink'], 0, 500 ) : null,
			'synced_at'       => current_time( 'mysql' ),
		);
		if ( $link ) {
			$wpdb->update( $lt, $row, array( 'id' => $link->id ) );
		} else {
			$wpdb->replace( $lt, $row );
		}
		return true;
	}

	/** Numbers for the Settings page. */
	public static function stats( $studio_id ) {
		global $wpdb;
		$lt  = Installer::table( 'gcal_links' );
		$qt  = Installer::table( 'gcal_queue' );
		$out = array( 'synced' => array(), 'pending' => 0, 'failed' => 0 );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) n FROM $lt WHERE studio_id=%d GROUP BY kind", $studio_id ) ) as $r ) {
			$out['synced'][ $r->kind ] = (int) $r->n;
		}
		$out['pending'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $qt WHERE studio_id=%d AND status='pending'", $studio_id ) );
		$out['failed']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $qt WHERE studio_id=%d AND status='failed'", $studio_id ) );
		$out['errors']  = $wpdb->get_results( $wpdb->prepare( "SELECT entity_key, attempts, status, last_error, updated_at FROM $qt WHERE studio_id=%d AND last_error IS NOT NULL ORDER BY updated_at DESC LIMIT 10", $studio_id ) );
		foreach ( $out['errors'] as $e ) {
			$e->label    = Gcal_Events::label( $e->entity_key );
			$e->attempts = (int) $e->attempts;
		}
		return $out;
	}
}
