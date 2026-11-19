<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

class Studios {

	/**
	 * Creates a studio with its owner membership and default catalogue.
	 */
	public static function create( $user_id, array $data ) {
		global $wpdb;
		$name = $data['name'];
		$slug = sanitize_title( $name );
		$base = $slug ? $slug : 'studio';
		$slug = $base;
		$i    = 2;
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'studios' ) . ' WHERE slug=%s', $slug ) ) ) {
			$slug = $base . '-' . $i++;
		}
		$now = Db::now();
		$ok  = $wpdb->insert(
			Installer::table( 'studios' ),
			array(
				'name'          => $name,
				'slug'          => $slug,
				'owner_user_id' => $user_id,
				'email'         => isset( $data['email'] ) ? $data['email'] : null,
				'phone'         => isset( $data['phone'] ) ? $data['phone'] : null,
				'city'          => isset( $data['city'] ) ? $data['city'] : null,
				'plan'          => 'free',
				'status'        => 'active',
				'settings'      => wp_json_encode( Config::default_settings() ),
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'db_error', 'Could not create studio.', array( 'status' => 500 ) );
		}
		$studio_id = (int) $wpdb->insert_id;
		$user      = get_userdata( $user_id );
		Db::insert(
			'members',
			array(
				'user_id' => $user_id,
				'name'    => isset( $data['owner_name'] ) ? $data['owner_name'] : $user->display_name,
				'email'   => $user->user_email,
				'phone'   => isset( $data['phone'] ) ? $data['phone'] : null,
				'role'    => 'owner',
				'status'  => 'active',
				'color'   => '#F3724C',
			),
			$studio_id
		);
		update_user_meta( $user_id, 'pcrm_current_studio', $studio_id );
		Seeder::defaults( $studio_id );
		Enquiry::ensure_form( $studio_id );
		Tenant::reset();
		return $studio_id;
	}

	public static function update_settings( $studio_id, array $settings ) {
		global $wpdb;
		$studio = Tenant::load_studio( $studio_id );
		$merged = array_merge( $studio->settings, $settings );
		$wpdb->update( Installer::table( 'studios' ), array( 'settings' => wp_json_encode( $merged ), 'updated_at' => Db::now() ), array( 'id' => $studio_id ) );
		Tenant::reset();
		return $merged;
	}
}
