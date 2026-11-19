<?php
/**
 * Plugin Name:       ShootPilot (Photographer CRM)
 * Description:       Multi-studio CRM for photographers: leads, pipeline, calling, quotations, bookings, payments, deliverables, client portal, calendar with Google Calendar sync, team, tasks and reports. Open it at /crm.
 * Version:           0.6.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            ShootPilot
 * License:           GPL-2.0-or-later
 * Text Domain:       photographer-crm
 */

defined( 'ABSPATH' ) || exit;

define( 'PCRM_VERSION', '0.6.0' );
define( 'PCRM_DB_VERSION', '5' );
// Quotation generator schema, versioned on its own option (pcrm_quote_db_version) so it never clashes with PCRM_DB_VERSION.
define( 'PCRM_QUOTE_DB_VERSION', '1' );
define( 'PCRM_FILE', __FILE__ );
define( 'PCRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCRM_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( PCRM_DIR . 'vendor/autoload.php' ) ) {
	require_once PCRM_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'PCRM\\' ) !== 0 ) {
			return;
		}
		$rel   = substr( $class, 5 );
		$parts = explode( '\\', $rel );
		$name  = array_pop( $parts );
		$file  = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$path  = PCRM_DIR . 'includes/' . $dir . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'PCRM\\Installer', 'activate' ) );
register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
		wp_clear_scheduled_hook( 'pcrm_reminders_tick' );
	}
);

add_action( 'plugins_loaded', array( 'PCRM\\Plugin', 'init' ) );
