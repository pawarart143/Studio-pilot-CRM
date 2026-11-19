<?php
/**
 * Plugin Name:       ShootPilot (Photographer CRM)
 * Description:       Multi-studio CRM for photographers: leads, pipeline, calling, quotations, bookings, payments, deliverables, client portal, calendar, team, tasks and reports. Open it at /crm.
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
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

add_action( 'plugins_loaded', array( 'PCRM\\Plugin', 'init' ) );
