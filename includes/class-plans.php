<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription plans. -1 means unlimited.
 */
class Plans {

	public static function all() {
		return array(
			'free'         => array(
				'label'           => 'Free',
				'price'           => 0,
				'members'         => 3,
				'leads_per_month' => 50,
				'pdf'             => true,
				'reports'         => true,
				'whatsapp_api'    => false,
			),
			'starter'      => array(
				'label'           => 'Starter',
				'price'           => 999,
				'members'         => 5,
				'leads_per_month' => 500,
				'pdf'             => true,
				'reports'         => true,
				'whatsapp_api'    => false,
			),
			'professional' => array(
				'label'           => 'Professional',
				'price'           => 2499,
				'members'         => 15,
				'leads_per_month' => -1,
				'pdf'             => true,
				'reports'         => true,
				'whatsapp_api'    => true,
			),
			'enterprise'   => array(
				'label'           => 'Enterprise',
				'price'           => 5999,
				'members'         => -1,
				'leads_per_month' => -1,
				'pdf'             => true,
				'reports'         => true,
				'whatsapp_api'    => true,
			),
		);
	}

	public static function get( $plan ) {
		$all = self::all();
		return isset( $all[ $plan ] ) ? $all[ $plan ] : $all['free'];
	}

	/**
	 * Returns WP_Error when a studio has hit a plan limit, else true.
	 */
	public static function check( $studio, $what ) {
		global $wpdb;
		$plan = self::get( $studio->plan );
		if ( $studio->plan_expires_at && $studio->plan !== 'free' && $studio->plan_expires_at < Db::date( 'Y-m-d' ) ) {
			$plan = self::get( 'free' );
		}
		if ( 'members' === $what && $plan['members'] >= 0 ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'members' ) . " WHERE studio_id=%d AND status<>'inactive' AND user_id IS NOT NULL", $studio->id ) );
			if ( $n >= $plan['members'] ) {
				return new \WP_Error( 'plan_limit', sprintf( 'Your %s plan allows %d team logins. You can still add crew without a login, or upgrade for more logins.', $plan['label'], $plan['members'] ), array( 'status' => 402 ) );
			}
		}
		if ( 'leads' === $what && $plan['leads_per_month'] >= 0 ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'leads' ) . ' WHERE studio_id=%d AND created_at >= %s', $studio->id, Db::date( 'Y-m-01 00:00:00' ) ) );
			if ( $n >= $plan['leads_per_month'] ) {
				return new \WP_Error( 'plan_limit', sprintf( 'Your %s plan allows %d new leads per month. Upgrade to add more.', $plan['label'], $plan['leads_per_month'] ), array( 'status' => 402 ) );
			}
		}
		if ( in_array( $what, array( 'reports', 'whatsapp_api' ), true ) && empty( $plan[ $what ] ) ) {
			return new \WP_Error( 'plan_limit', sprintf( 'This feature is not included in the %s plan.', $plan['label'] ), array( 'status' => 402 ) );
		}
		return true;
	}
}
