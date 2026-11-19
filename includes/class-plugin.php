<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

class Plugin {

	const REST_NS = 'pcrm/v1';

	public static function controllers() {
		return array( 'Auth', 'Dashboard', 'Leads', 'Calls', 'Followups', 'Clients', 'Services', 'Quotations', 'Bookings', 'Payments', 'Expenses', 'Calendar', 'Team', 'Tasks', 'Documents', 'Reports', 'Whatsapp', 'Settings', 'Admin', 'Razorpay', 'Deliverables', 'Portal', 'Crew', 'Enquiry_Forms', 'Billing' );
	}

	public static function init() {
		Installer::maybe_upgrade();
		Billing_Installer::maybe_install();
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 0 );
		add_filter(
			'rocket_cache_reject_uri',
			function ( $uris ) {
				return array_merge( (array) $uris, self::private_paths() );
			}
		);
		Landing::init();
		if ( isset( $_GET['pcrm_google'] ) ) { // phpcs:ignore
			add_action( 'init', array( 'PCRM\\Google_Auth', 'handle' ), 20 );
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'rest_no_cache' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'permalink_notice' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_action( 'pcrm_daily', array( 'PCRM\\Rest\\Quotations', 'expire_old' ) );
		if ( ! wp_next_scheduled( 'pcrm_daily' ) ) {
			wp_schedule_event( time() + 60, 'daily', 'pcrm_daily' );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pcrm', 'PCRM\\Cli' );
		}
	}

	public static function add_rewrite() {
		add_rewrite_rule( '^crm/q/([A-Za-z0-9]+)/pdf/?$', 'index.php?pcrm_quote=$matches[1]&pcrm_pdf=1', 'top' );
		add_rewrite_rule( '^crm/q/([A-Za-z0-9]+)/?$', 'index.php?pcrm_quote=$matches[1]', 'top' );
		add_rewrite_rule( '^crm(/.*)?$', 'index.php?pcrm_app=1', 'top' );
		add_rewrite_rule( '^shootpilot/?$', 'index.php?pcrm_landing=1', 'top' );
		add_rewrite_rule( '^portal/([A-Za-z0-9]+)/?$', 'index.php?pcrm_portal=$matches[1]', 'top' );
		add_rewrite_rule( '^enquire/([a-z0-9-]+)/?$', 'index.php?pcrm_enquire=$matches[1]', 'top' );
		// Plugin updates (zip replace) don't re-run activation: flush once per version.
		if ( did_action( 'init' ) && get_option( 'pcrm_rewrite_version' ) !== PCRM_VERSION ) {
			update_option( 'pcrm_rewrite_version', PCRM_VERSION, true );
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'pcrm_app';
		$vars[] = 'pcrm_quote';
		$vars[] = 'pcrm_pdf';
		$vars[] = 'pcrm_landing';
		$vars[] = 'pcrm_portal';
		$vars[] = 'pcrm_enquire';
		return $vars;
	}

	/**
	 * Stops page caches (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super
	 * Cache, SG Optimizer, Nginx/Varnish/Cloudflare) from storing a private or
	 * per-visitor page. Without this a host cache can keep serving an old copy
	 * of a client portal (for example "not activated yet") after the PIN is set.
	 */
	public static function no_cache() {
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY', 'DONOTCDN' ) as $c ) {
			if ( ! defined( $c ) ) {
				define( $c, true );
			}
		}
		do_action( 'litespeed_control_set_nocache', 'ShootPilot private page' );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
			header( 'Pragma: no-cache' );
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'X-Accel-Expires: 0' );
			header( 'CDN-Cache-Control: no-store' );
			header( 'Cloudflare-CDN-Cache-Control: no-store' );
			header( 'Surrogate-Control: no-store' );
		}
	}

	/**
	 * Tells caches to drop a stored copy of a URL (used when a portal changes).
	 */
	public static function purge_url( $url ) {
		do_action( 'litespeed_purge_url', $url );
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array( $url ) );
		}
		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $url );
		}
		if ( function_exists( 'wpsc_delete_url_cache' ) ) {
			wpsc_delete_url_cache( $url );
		}
	}

	/**
	 * URL prefixes that must never be page cached. WP Rocket and others read
	 * these through their filters.
	 */
	public static function private_paths() {
		return array( '/crm/(.*)', '/portal/(.*)', '/enquire/(.*)' );
	}

	public static function route() {
		$token  = get_query_var( 'pcrm_quote' );
		$portal = get_query_var( 'pcrm_portal' );
		if ( $portal || $token || get_query_var( 'pcrm_app' ) || get_query_var( 'pcrm_enquire' ) ) {
			self::no_cache();
		}
		if ( $token ) {
			Public_Quote::render( $token, (bool) get_query_var( 'pcrm_pdf' ) );
			exit;
		}
		if ( $portal ) {
			Portals::render( $portal );
			exit;
		}
		$enquire = get_query_var( 'pcrm_enquire' );
		if ( $enquire ) {
			Enquiry::render( $enquire );
			exit;
		}
		if ( get_query_var( 'pcrm_app' ) ) {
			App_Shell::render();
			exit;
		}
	}

	/** CRM API answers are private: never let a cache store them. */
	public static function rest_no_cache( $res, $server, $req ) {
		if ( 0 === strpos( $req->get_route(), '/' . self::REST_NS . '/' ) && $res instanceof \WP_REST_Response ) {
			do_action( 'litespeed_control_set_nocache', 'ShootPilot API' );
			$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
			$res->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}
		return $res;
	}

	public static function register_rest() {
		foreach ( self::controllers() as $c ) {
			$cls = 'PCRM\\Rest\\' . $c;
			( new $cls() )->register();
		}
	}

	public static function hide_admin_bar( $show ) {
		return get_query_var( 'pcrm_app' ) ? false : $show;
	}

	/**
	 * The /crm app needs pretty permalinks; plain "?p=123" links cannot route it.
	 */
	public static function permalink_notice() {
		if ( get_option( 'permalink_structure' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>ShootPilot:</strong> the CRM at /crm/ needs pretty permalinks. Go to <a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">Settings &rarr; Permalinks</a>, choose "Post name" and save.</p></div>';
	}

	public static function admin_menu() {
		add_menu_page(
			'ShootPilot',
			'ShootPilot',
			'read',
			'photographer-crm',
			function () {
				$url = home_url( '/crm/' );
				$supers = array_map(
					function ( $id ) {
						$u = get_userdata( $id );
						return $u ? $u->user_email : '#' . $id;
					},
					Tenant::super_admin_ids()
				);
				echo '<div class="wrap"><h1>ShootPilot</h1><p>The CRM runs as its own app.</p><p><a class="button button-primary button-hero" href="' . esc_url( $url ) . '">Open ShootPilot</a></p><p>Share this sign-up link with studios: <code>' . esc_html( home_url( '/crm/register' ) ) . '</code></p>';
				if ( current_user_can( 'manage_options' ) ) {
					echo '<p>Super admin (sees all studios): <strong>' . esc_html( $supers ? implode( ', ', $supers ) : 'nobody' ) . '</strong></p>';
				}
				Landing::settings_form();
				echo '</div>';
			},
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><g fill="none" stroke="black" stroke-width="7" stroke-linecap="round"><circle cx="50" cy="50" r="27"/><path d="M50.00 61.00L23.36 45.62 M40.47 55.50L40.47 24.74 M40.47 44.50L67.12 29.12 M50.00 39.00L76.64 54.38 M59.53 44.50L59.53 75.26 M59.53 55.50L32.88 70.88"/></g></svg>' ),
			3
		);
	}
}
