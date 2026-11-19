<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands: wp pcrm create-studio / seed.
 */
class Cli {

	/**
	 * Creates a studio for an existing user.
	 *
	 * ## OPTIONS
	 * <user>
	 * : User ID, login or email.
	 * <name>
	 * : Studio name.
	 *
	 * @subcommand create-studio
	 */
	public function create_studio( $args ) {
		$u = is_numeric( $args[0] ) ? get_user_by( 'id', $args[0] ) : ( is_email( $args[0] ) ? get_user_by( 'email', $args[0] ) : get_user_by( 'login', $args[0] ) );
		if ( ! $u ) {
			\WP_CLI::error( 'User not found.' );
		}
		wp_set_current_user( $u->ID );
		$id = Studios::create( $u->ID, array( 'name' => $args[1], 'email' => $u->user_email ) );
		is_wp_error( $id ) ? \WP_CLI::error( $id->get_error_message() ) : \WP_CLI::success( "Studio $id created." );
	}

	/**
	 * Loads sample data into a studio.
	 *
	 * ## OPTIONS
	 * <studio_id>
	 * : Studio ID.
	 */
	public function seed( $args ) {
		$sid = (int) $args[0];
		$s   = Tenant::load_studio( $sid );
		if ( ! $s ) {
			\WP_CLI::error( 'Studio not found.' );
		}
		wp_set_current_user( $s->owner_user_id );
		Tenant::reset();
		$r = Seeder::sample( $sid );
		is_wp_error( $r ) ? \WP_CLI::error( $r->get_error_message() ) : \WP_CLI::success( wp_json_encode( $r ) );
	}

	/**
	 * Sets who is super admin (platform owner). Pass one or more users.
	 *
	 * ## OPTIONS
	 * <user>...
	 * : User ID, login or email. Each must be a WordPress administrator.
	 *
	 * @subcommand super-admin
	 */
	public function super_admin( $args ) {
		$ids = array();
		foreach ( $args as $a ) {
			$u = is_numeric( $a ) ? get_user_by( 'id', $a ) : ( is_email( $a ) ? get_user_by( 'email', $a ) : get_user_by( 'login', $a ) );
			if ( ! $u || ! user_can( $u, 'manage_options' ) ) {
				\WP_CLI::error( "$a is not a WordPress administrator." );
			}
			$ids[] = (int) $u->ID;
		}
		update_option( 'pcrm_super_admins', $ids, false );
		\WP_CLI::success( 'Super admin set: ' . implode( ', ', $ids ) );
	}
}
