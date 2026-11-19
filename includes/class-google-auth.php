<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * "Continue with Google" using Google's OAuth 2.0 authorization-code flow.
 * The super admin enters a Google Client ID and Client Secret in Super Admin > Platform settings.
 * The routes are query-string based (?pcrm_google=start / callback), so they work without rewrites.
 */
class Google_Auth {

	public static function client_id() {
		return trim( (string) get_option( 'pcrm_google_client_id', '' ) );
	}

	public static function enabled() {
		return self::client_id() && get_option( 'pcrm_google_client_secret' );
	}

	public static function redirect_uri() {
		return add_query_arg( 'pcrm_google', 'callback', home_url( '/' ) );
	}

	public static function start_url() {
		return add_query_arg( 'pcrm_google', 'start', home_url( '/' ) );
	}

	private static function fail( $code ) {
		wp_safe_redirect( home_url( '/crm/login?google_error=' . rawurlencode( $code ) ) );
		exit;
	}

	public static function handle() {
		$step = isset( $_GET['pcrm_google'] ) ? sanitize_key( wp_unslash( $_GET['pcrm_google'] ) ) : ''; // phpcs:ignore
		if ( 'start' === $step ) {
			self::start();
		} elseif ( 'callback' === $step ) {
			self::callback();
		}
	}

	private static function start() {
		if ( ! self::enabled() ) {
			self::fail( 'not_configured' );
		}
		$state = wp_generate_password( 32, false );
		set_transient( 'pcrm_gstate_' . $state, 1, 10 * MINUTE_IN_SECONDS );
		setcookie( 'pcrm_gstate', $state, array( 'expires' => time() + 600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( self::client_id() ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( 'openid email profile' ),
				'state'         => $state,
				'prompt'        => 'select_account',
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
		wp_redirect( $url ); // phpcs:ignore -- external Google URL.
		exit;
	}

	private static function callback() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['error'] ) ) {
			self::fail( 'cancelled' );
		}
		$state  = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$cookie = isset( $_COOKIE['pcrm_gstate'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['pcrm_gstate'] ) ) : '';
		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable
		if ( ! $state || ! hash_equals( $cookie, $state ) || ! get_transient( 'pcrm_gstate_' . $state ) ) {
			self::fail( 'state' );
		}
		delete_transient( 'pcrm_gstate_' . $state );
		setcookie( 'pcrm_gstate', '', array( 'expires' => time() - 3600, 'path' => '/' ) );
		if ( ! $code || ! self::enabled() ) {
			self::fail( 'not_configured' );
		}
		$res = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => self::client_id(),
					'client_secret' => get_option( 'pcrm_google_client_secret' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		$tok = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $tok['id_token'] ) ) {
			error_log( '[pcrm] Google token exchange failed: ' . ( is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_body( $res ) ) ); // phpcs:ignore
			self::fail( 'token' );
		}
		// Google verifies the ID token's signature for us here.
		$info = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $tok['id_token'] ), array( 'timeout' => 15 ) );
		$c    = is_wp_error( $info ) ? null : json_decode( wp_remote_retrieve_body( $info ), true );
		$user = self::verified_user( $c );
		if ( is_wp_error( $user ) ) {
			self::fail( $user->get_error_code() );
		}
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		wp_safe_redirect( home_url( '/crm/' ) );
		exit;
	}

	/**
	 * Checks the token claims and finds or creates the WordPress user. Public for tests.
	 */
	public static function verified_user( $c ) {
		if ( ! is_array( $c ) || empty( $c['email'] ) || empty( $c['aud'] ) ) {
			return new \WP_Error( 'token' );
		}
		if ( $c['aud'] !== self::client_id() || ! in_array( $c['iss'] ?? '', array( 'accounts.google.com', 'https://accounts.google.com' ), true ) || (int) ( $c['exp'] ?? 0 ) < time() ) {
			return new \WP_Error( 'token' );
		}
		if ( ! in_array( $c['email_verified'] ?? '', array( true, 'true' ), true ) ) {
			return new \WP_Error( 'unverified' );
		}
		$email = sanitize_email( $c['email'] );
		$user  = get_user_by( 'email', $email );
		if ( $user ) {
			return $user;
		}
		$base  = sanitize_user( strstr( $email, '@', true ), true );
		$base  = $base ? $base : 'user';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i++;
		}
		$name = sanitize_text_field( $c['name'] ?? $base );
		$uid  = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => $name,
				'first_name'   => sanitize_text_field( $c['given_name'] ?? $name ),
				'last_name'    => sanitize_text_field( $c['family_name'] ?? '' ),
				'role'         => 'subscriber',
			)
		);
		return is_wp_error( $uid ) ? new \WP_Error( 'create' ) : get_user_by( 'id', $uid );
	}
}
