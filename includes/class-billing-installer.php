<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Tables and data migration for subscription billing (v0.6.0).
 *
 * Versioned with its own option (pcrm_billing_db_version), separate from
 * PCRM_DB_VERSION, so it can be merged next to other modules' migrations.
 * It only adds tables, columns and rows; nothing is removed.
 */
class Billing_Installer {

	const VERSION = '1';

	public static function maybe_install() {
		if ( get_option( 'pcrm_billing_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = function ( $n ) {
			return Installer::table( $n );
		};

		$sql   = array();
		$sql[] = "CREATE TABLE {$t('plans')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_key VARCHAR(20) NOT NULL,
			label VARCHAR(60) NOT NULL,
			tagline VARCHAR(190) NULL,
			price_monthly INT NOT NULL DEFAULT 0,
			price_yearly INT NOT NULL DEFAULT 0,
			trial_days INT NOT NULL DEFAULT 0,
			members INT NOT NULL DEFAULT 1,
			leads_per_month INT NOT NULL DEFAULT 50,
			features TEXT NULL,
			sort INT NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY plan_key (plan_key)
		) $c;";

		// One row per Razorpay subscription a studio starts. The studio row holds the plan in force.
		$sql[] = "CREATE TABLE {$t('subscriptions')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			plan_key VARCHAR(20) NOT NULL,
			billing_cycle VARCHAR(10) NOT NULL,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			gateway VARCHAR(20) NOT NULL DEFAULT 'razorpay',
			gateway_plan_id VARCHAR(64) NULL,
			gateway_subscription_id VARCHAR(64) NOT NULL,
			mode VARCHAR(10) NOT NULL DEFAULT 'test',
			status VARCHAR(20) NOT NULL DEFAULT 'created',
			short_url VARCHAR(255) NULL,
			current_start DATETIME NULL,
			current_end DATETIME NULL,
			paid_count INT NOT NULL DEFAULT 0,
			cancel_requested_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_subscription_id (gateway_subscription_id),
			KEY studio_status (studio_id,status)
		) $c;";

		$sql[] = "CREATE TABLE {$t('billing_payments')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			subscription_id BIGINT UNSIGNED NOT NULL,
			gateway_payment_id VARCHAR(64) NOT NULL,
			gateway_invoice_id VARCHAR(64) NULL,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			currency VARCHAR(3) NOT NULL DEFAULT 'INR',
			method VARCHAR(30) NULL,
			plan_key VARCHAR(20) NOT NULL,
			billing_cycle VARCHAR(10) NOT NULL,
			period_end DATETIME NULL,
			paid_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_payment_id (gateway_payment_id),
			KEY studio_paid (studio_id,paid_at)
		) $c;";

		// Webhook deliveries already handled (Razorpay retries, so events can arrive more than once).
		$sql[] = "CREATE TABLE {$t('billing_events')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(64) NOT NULL,
			event VARCHAR(60) NOT NULL,
			studio_id BIGINT UNSIGNED NULL,
			gateway_subscription_id VARCHAR(64) NULL,
			result VARCHAR(190) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY studio_id (studio_id)
		) $c;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}

		// Studio columns. Added with ALTER (not by editing the shared studios CREATE TABLE).
		$s    = Installer::table( 'studios' );
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM $s" ); // phpcs:ignore
		if ( $cols ) {
			$add = array(
				'trial_ends_at'   => 'DATE NULL AFTER plan_expires_at',
				'billing_cycle'   => 'VARCHAR(10) NULL AFTER trial_ends_at',
				'billing_status'  => 'VARCHAR(20) NULL AFTER billing_cycle',
				'subscription_id' => 'BIGINT UNSIGNED NULL AFTER billing_status',
			);
			foreach ( $add as $col => $def ) {
				if ( ! in_array( $col, $cols, true ) ) {
					$wpdb->query( "ALTER TABLE $s ADD COLUMN $col $def" ); // phpcs:ignore
					$cols[] = $col;
				}
			}
		}

		self::seed_plans();
		update_option( 'pcrm_billing_db_version', self::VERSION );
		Plans::flush();
		self::migrate_studios();
	}

	/** Inserts the plans that are missing. Existing rows (edited by the super admin) are left alone. */
	public static function seed_plans() {
		global $wpdb;
		$t   = Installer::table( 'plans' );
		$now = Db::now();
		foreach ( Plans::defaults() as $key => $p ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE plan_key=%s", $key ) ) ) { // phpcs:ignore
				continue;
			}
			$wpdb->insert(
				$t,
				array(
					'plan_key'        => $key,
					'label'           => $p['label'],
					'tagline'         => $p['tagline'],
					'price_monthly'   => $p['price'],
					'price_yearly'    => $p['price_yearly'],
					'trial_days'      => $p['trial_days'],
					'members'         => $p['members'],
					'leads_per_month' => $p['leads_per_month'],
					'features'        => wp_json_encode( $p['features'] ),
					'sort'            => $p['sort'],
					'is_active'       => 1,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
		}
	}

	/**
	 * Moves studios from the old plan names to the new ones without taking
	 * anything away:
	 *  - free         → starter, with a fresh 15-day trial from today
	 *  - starter (old paid plan, 5 logins) → professional, same expiry
	 *  - enterprise   → business, same expiry
	 *  - professional stays professional
	 * Only studios without trial_ends_at and not yet migrated are touched, so it is safe to re-run.
	 */
	public static function migrate_studios() {
		global $wpdb;
		if ( get_option( 'pcrm_billing_studios_migrated' ) ) {
			return;
		}
		$s     = Installer::table( 'studios' );
		$trial = (int) Plans::get( 'starter' )['trial_days'];
		$ends  = gmdate( 'Y-m-d', strtotime( Db::today() . ' 00:00:00 UTC +' . max( 0, $trial - 1 ) . ' days' ) );
		$now   = Db::now();
		$wpdb->query( $wpdb->prepare( "UPDATE $s SET plan='professional', updated_at=%s WHERE plan='starter'", $now ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "UPDATE $s SET plan='business', updated_at=%s WHERE plan='enterprise'", $now ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "UPDATE $s SET plan='starter', trial_ends_at=%s, plan_expires_at=NULL, updated_at=%s WHERE plan='free' OR plan=''", $ends, $now ) ); // phpcs:ignore
		// A paid plan that had already expired used to fall back to Free limits. Give those studios
		// a fresh trial instead of making them read-only the moment they update.
		$wpdb->query( $wpdb->prepare( "UPDATE $s SET plan='starter', trial_ends_at=%s, plan_expires_at=NULL, updated_at=%s WHERE plan IN ('professional','business') AND plan_expires_at IS NOT NULL AND plan_expires_at < %s", $ends, $now, Db::today() ) ); // phpcs:ignore
		update_option( 'pcrm_billing_studios_migrated', Db::now(), false );
	}
}
