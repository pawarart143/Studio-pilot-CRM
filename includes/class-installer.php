<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables.
 */
class Installer {

	public static function activate() {
		self::install();
		Billing_Installer::install();
		if ( ! get_option( 'pcrm_super_admins' ) && get_current_user_id() && current_user_can( 'manage_options' ) ) {
			update_option( 'pcrm_super_admins', array( get_current_user_id() ), false );
		}
		Plugin::add_rewrite();
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'pcrm_db_version' ) !== PCRM_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Data migrations that run once per DB version, after dbDelta has added the
	 * new columns and tables. They only add or fill data; nothing is removed.
	 */
	public static function migrate( $from ) {
		global $wpdb;
		$from = (int) $from;
		if ( $from < 3 ) {
			$p = self::table( 'payments' );
			// Existing payments were all money received. Mark the first payment of
			// each booking as the advance and the one that cleared it as final.
			$wpdb->query( "UPDATE $p SET status='received' WHERE status IS NULL OR status=''" );
			$wpdb->query( "UPDATE $p p JOIN (SELECT booking_id, MIN(id) mid FROM $p GROUP BY booking_id) f ON f.mid=p.id SET p.payment_type='advance' WHERE p.payment_type='installment'" );
			$wpdb->query( 'UPDATE ' . $p . ' p JOIN (SELECT booking_id, MAX(id) xid, SUM(amount) s, COUNT(*) n FROM ' . $p . ' GROUP BY booking_id) l ON l.xid=p.id JOIN ' . self::table( 'bookings' ) . " b ON b.id=p.booking_id SET p.payment_type='final' WHERE l.n>1 AND b.total_amount>0 AND l.s >= b.total_amount - 0.01" );
			$wpdb->query( 'UPDATE ' . self::table( 'members' ) . " SET availability='available' WHERE availability IS NULL OR availability=''" );
			$roles = array( 'photographer' => 'lead_photographer', 'cinematographer' => 'cinematographer', 'editor' => 'photo_editor', 'album_designer' => 'album_designer', 'production_manager' => 'production_manager' );
			foreach ( $roles as $access => $team_role ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'members' ) . " SET team_role=%s WHERE role=%s AND (team_role IS NULL OR team_role='')", $team_role, $access ) );
			}
			$studios = $wpdb->get_col( 'SELECT id FROM ' . self::table( 'studios' ) );
			foreach ( $studios as $sid ) {
				Seeder::defaults_v3( (int) $sid );
			}
		}
		if ( $from < 5 ) {
			// v0.5.0: every studio gets its enquiry form (on, with default fields).
			$studios = $wpdb->get_col( 'SELECT id FROM ' . self::table( 'studios' ) );
			foreach ( $studios as $sid ) {
				Enquiry::ensure_form( (int) $sid );
			}
		}
		if ( $from < 4 ) {
			// v0.4.1: portals that already have a PIN count as activated.
			$cp = self::table( 'client_portals' );
			$wpdb->query( "UPDATE $cp SET activated_at=COALESCE(pin_set_at, created_at) WHERE pin_hash IS NOT NULL AND pin_hash<>'' AND activated_at IS NULL" );
		}
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'pcrm_' . $name;
	}

	public static function tables() {
		return array( 'studios', 'members', 'clients', 'leads', 'activities', 'calls', 'followups', 'services', 'quotations', 'quotation_items', 'bookings', 'booking_events', 'event_assignments', 'installments', 'payments', 'expenses', 'tasks', 'documents', 'wa_templates', 'payment_links', 'payment_activity_logs', 'booking_services', 'service_team_assignments', 'deliverable_templates', 'deliverable_template_items', 'booking_deliverables', 'client_portals', 'crew_members', 'crew_schedules', 'crew_schedule_assignments', 'crew_expenses', 'crew_expense_payments', 'public_enquiry_forms', 'enquiry_form_fields', 'enquiry_submissions', 'audit_logs' );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = function ( $n ) {
			return self::table( $n );
		};

		$sql = array();

		$sql[] = "CREATE TABLE {$t('studios')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			slug VARCHAR(190) NOT NULL,
			owner_user_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(190) NULL,
			phone VARCHAR(32) NULL,
			address TEXT NULL,
			city VARCHAR(100) NULL,
			state VARCHAR(100) NULL,
			country VARCHAR(2) NOT NULL DEFAULT 'IN',
			gstin VARCHAR(20) NULL,
			logo_url VARCHAR(500) NULL,
			plan VARCHAR(20) NOT NULL DEFAULT 'free',
			plan_expires_at DATE NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			settings LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY owner_user_id (owner_user_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('members')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			name VARCHAR(190) NOT NULL,
			email VARCHAR(190) NULL,
			phone VARCHAR(32) NULL,
			role VARCHAR(40) NOT NULL DEFAULT 'sales',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			color VARCHAR(20) NULL,
			photo_url VARCHAR(500) NULL,
			team_role VARCHAR(40) NULL,
			specialization VARCHAR(190) NULL,
			city VARCHAR(100) NULL,
			availability VARCHAR(20) NOT NULL DEFAULT 'available',
			joining_date DATE NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_id (studio_id),
			KEY studio_team_role (studio_id,team_role),
			UNIQUE KEY studio_user (studio_id,user_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('clients')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			phone VARCHAR(32) NULL,
			whatsapp VARCHAR(32) NULL,
			email VARCHAR(190) NULL,
			city VARCHAR(100) NULL,
			address TEXT NULL,
			notes TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_id (studio_id),
			KEY studio_phone (studio_id,phone)
		) $c;";

		$sql[] = "CREATE TABLE {$t('leads')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			client_id BIGINT UNSIGNED NULL,
			name VARCHAR(190) NOT NULL,
			phone VARCHAR(32) NULL,
			whatsapp VARCHAR(32) NULL,
			email VARCHAR(190) NULL,
			event_type VARCHAR(40) NULL,
			event_date DATE NULL,
			event_end_date DATE NULL,
			event_duration VARCHAR(60) NULL,
			venue VARCHAR(255) NULL,
			city VARCHAR(100) NULL,
			source VARCHAR(40) NULL,
			assigned_to BIGINT UNSIGNED NULL,
			budget DECIMAL(12,2) NULL,
			stage VARCHAR(40) NOT NULL DEFAULT 'new',
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			call_status VARCHAR(40) NOT NULL DEFAULT 'not_called',
			last_call_at DATETIME NULL,
			next_followup_at DATETIME NULL,
			lost_reason VARCHAR(255) NULL,
			notes TEXT NULL,
			services TEXT NULL,
			enquiry_submission_id BIGINT UNSIGNED NULL,
			form_url VARCHAR(500) NULL,
			utm_source VARCHAR(190) NULL,
			utm_medium VARCHAR(190) NULL,
			utm_campaign VARCHAR(190) NULL,
			position INT NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_stage (studio_id,stage),
			KEY enquiry_submission_id (enquiry_submission_id),
			KEY studio_phone (studio_id,phone),
			KEY studio_created (studio_id,created_at),
			KEY studio_followup (studio_id,next_followup_at),
			KEY client_id (client_id),
			KEY assigned_to (assigned_to)
		) $c;";

		$sql[] = "CREATE TABLE {$t('activities')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			lead_id BIGINT UNSIGNED NULL,
			client_id BIGINT UNSIGNED NULL,
			booking_id BIGINT UNSIGNED NULL,
			type VARCHAR(40) NOT NULL,
			message TEXT NOT NULL,
			meta LONGTEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_created (studio_id,created_at),
			KEY lead_id (lead_id),
			KEY client_id (client_id),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('calls')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			lead_id BIGINT UNSIGNED NOT NULL,
			member_id BIGINT UNSIGNED NULL,
			status VARCHAR(40) NOT NULL,
			notes TEXT NULL,
			called_at DATETIME NOT NULL,
			next_call_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_called (studio_id,called_at),
			KEY lead_id (lead_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('followups')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			lead_id BIGINT UNSIGNED NOT NULL,
			assigned_to BIGINT UNSIGNED NULL,
			due_at DATETIME NOT NULL,
			note TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			completed_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_due (studio_id,status,due_at),
			KEY lead_id (lead_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('services')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(40) NOT NULL,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			price DECIMAL(12,2) NOT NULL DEFAULT 0,
			unit VARCHAR(40) NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			sort INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_id (studio_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('quotations')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			number VARCHAR(40) NOT NULL,
			lead_id BIGINT UNSIGNED NULL,
			client_id BIGINT UNSIGNED NULL,
			title VARCHAR(190) NOT NULL,
			customer_name VARCHAR(190) NOT NULL,
			customer_phone VARCHAR(32) NULL,
			customer_email VARCHAR(190) NULL,
			event_type VARCHAR(40) NULL,
			event_date DATE NULL,
			venue VARCHAR(255) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			valid_until DATE NULL,
			subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
			discount_type VARCHAR(10) NOT NULL DEFAULT 'flat',
			discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
			discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
			gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			total DECIMAL(12,2) NOT NULL DEFAULT 0,
			notes TEXT NULL,
			terms TEXT NULL,
			public_token VARCHAR(64) NOT NULL,
			sent_at DATETIME NULL,
			viewed_at DATETIME NULL,
			responded_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_status (studio_id,status),
			KEY lead_id (lead_id),
			KEY client_id (client_id),
			UNIQUE KEY public_token (public_token)
		) $c;";

		$sql[] = "CREATE TABLE {$t('quotation_items')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			quotation_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NULL,
			category VARCHAR(40) NULL,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			qty DECIMAL(10,2) NOT NULL DEFAULT 1,
			rate DECIMAL(12,2) NOT NULL DEFAULT 0,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			sort INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY quotation_id (quotation_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('bookings')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			number VARCHAR(40) NOT NULL,
			client_id BIGINT UNSIGNED NOT NULL,
			lead_id BIGINT UNSIGNED NULL,
			quotation_id BIGINT UNSIGNED NULL,
			title VARCHAR(190) NOT NULL,
			event_type VARCHAR(40) NULL,
			package_details TEXT NULL,
			total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			status VARCHAR(30) NOT NULL DEFAULT 'confirmed',
			confirmed_on DATE NULL,
			delivery_due DATE NULL,
			special_instructions TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_status (studio_id,status),
			KEY client_id (client_id),
			KEY lead_id (lead_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('booking_events')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			event_type VARCHAR(40) NULL,
			event_date DATE NOT NULL,
			start_time TIME NULL,
			end_time TIME NULL,
			venue VARCHAR(255) NULL,
			city VARCHAR(100) NULL,
			address TEXT NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_date (studio_id,event_date),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('event_assignments')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_event_id BIGINT UNSIGNED NOT NULL,
			member_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(40) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_member (booking_event_id,member_id),
			KEY member_id (member_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('installments')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			label VARCHAR(100) NOT NULL,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			due_date DATE NULL,
			sort INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY studio_due (studio_id,due_date)
		) $c;";

		$sql[] = "CREATE TABLE {$t('payments')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			installment_id BIGINT UNSIGNED NULL,
			receipt_no VARCHAR(40) NOT NULL,
			amount DECIMAL(12,2) NOT NULL,
			paid_on DATE NOT NULL,
			method VARCHAR(30) NOT NULL,
			transaction_ref VARCHAR(100) NULL,
			payment_type VARCHAR(20) NOT NULL DEFAULT 'installment',
			status VARCHAR(20) NOT NULL DEFAULT 'received',
			notes TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			updated_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY studio_paid (studio_id,paid_on),
			KEY studio_status (studio_id,status),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('payment_activity_logs')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			payment_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(20) NOT NULL,
			summary VARCHAR(255) NOT NULL,
			changes LONGTEXT NULL,
			snapshot LONGTEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			user_name VARCHAR(190) NULL,
			ip VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_created (studio_id,created_at),
			KEY payment_id (payment_id),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('booking_services')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NULL,
			name VARCHAR(190) NOT NULL,
			category VARCHAR(40) NULL,
			on_site TINYINT(1) NOT NULL DEFAULT 1,
			notes TEXT NULL,
			sort INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_booking (studio_id,booking_id),
			KEY service_id (service_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('service_team_assignments')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_service_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			member_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_member (booking_service_id,member_id),
			KEY studio_member (studio_id,member_id),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('deliverable_templates')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			slug VARCHAR(60) NULL,
			name VARCHAR(190) NOT NULL,
			event_type VARCHAR(40) NULL,
			description TEXT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_id (studio_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('deliverable_template_items')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			template_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			days_after_event INT NULL,
			sort INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY template_id (template_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('booking_deliverables')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			assigned_to BIGINT UNSIGNED NULL,
			expected_date DATE NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'not_started',
			progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
			delivery_link VARCHAR(500) NULL,
			admin_notes TEXT NULL,
			sort INT NOT NULL DEFAULT 0,
			delivered_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			updated_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_booking (studio_id,booking_id),
			KEY studio_status (studio_id,status),
			KEY assigned_to (assigned_to)
		) $c;";

		$sql[] = "CREATE TABLE {$t('client_portals')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			token VARCHAR(64) NOT NULL,
			pin_hash VARCHAR(255) NULL,
			pin_version INT UNSIGNED NOT NULL DEFAULT 0,
			pin_encrypted TEXT NULL,
			pin_set_at DATETIME NULL,
			activated_at DATETIME NULL,
			is_enabled TINYINT(1) NOT NULL DEFAULT 1,
			failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			locked_until DATETIME NULL,
			last_viewed_at DATETIME NULL,
			view_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			UNIQUE KEY studio_booking (studio_id,booking_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('expenses')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NULL,
			category VARCHAR(60) NOT NULL,
			description VARCHAR(255) NULL,
			amount DECIMAL(12,2) NOT NULL,
			spent_on DATE NOT NULL,
			method VARCHAR(30) NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_spent (studio_id,spent_on),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('tasks')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL,
			description TEXT NULL,
			category VARCHAR(40) NULL,
			lead_id BIGINT UNSIGNED NULL,
			booking_id BIGINT UNSIGNED NULL,
			assigned_to BIGINT UNSIGNED NULL,
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			due_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'todo',
			completed_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_status (studio_id,status,due_at),
			KEY assigned_to (assigned_to)
		) $c;";

		$sql[] = "CREATE TABLE {$t('documents')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			client_id BIGINT UNSIGNED NULL,
			booking_id BIGINT UNSIGNED NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			mime VARCHAR(100) NULL,
			size BIGINT UNSIGNED NULL,
			uploaded_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('payment_links')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			installment_id BIGINT UNSIGNED NULL,
			amount DECIMAL(12,2) NOT NULL,
			gateway VARCHAR(20) NOT NULL DEFAULT 'razorpay',
			gateway_id VARCHAR(60) NOT NULL,
			short_url VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'created',
			payment_id BIGINT UNSIGNED NULL,
			gateway_payment_id VARCHAR(60) NULL,
			note VARCHAR(255) NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY gateway_id (gateway_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('wa_templates')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			slug VARCHAR(60) NOT NULL,
			name VARCHAR(190) NOT NULL,
			body TEXT NOT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY studio_slug (studio_id,slug)
		) $c;";

		/* ---------- v0.5.0: crew scheduling (independent of team members) ---------- */
		$sql[] = "CREATE TABLE {$t('crew_members')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			phone VARCHAR(32) NULL,
			whatsapp VARCHAR(32) NULL,
			email VARCHAR(190) NULL,
			photo_url VARCHAR(500) NULL,
			category VARCHAR(40) NOT NULL DEFAULT 'other',
			specialization VARCHAR(190) NULL,
			city VARCHAR(100) NULL,
			daily_rate DECIMAL(12,2) NULL,
			availability VARCHAR(20) NOT NULL DEFAULT 'available',
			notes TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY studio_category (studio_id,category),
			KEY studio_phone (studio_id,phone)
		) $c;";

		$sql[] = "CREATE TABLE {$t('crew_schedules')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NULL,
			booking_event_id BIGINT UNSIGNED NULL,
			client_name VARCHAR(190) NULL,
			event_name VARCHAR(190) NOT NULL,
			event_type VARCHAR(40) NULL,
			event_date DATE NOT NULL,
			start_time TIME NULL,
			reporting_time TIME NULL,
			end_time TIME NULL,
			venue VARCHAR(255) NULL,
			city VARCHAR(100) NULL,
			transport TEXT NULL,
			accommodation TEXT NULL,
			instructions TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_date (studio_id,event_date),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('crew_schedule_assignments')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			schedule_id BIGINT UNSIGNED NOT NULL,
			crew_member_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(40) NOT NULL,
			service VARCHAR(190) NULL,
			rate DECIMAL(12,2) NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
			conflict_override TINYINT(1) NOT NULL DEFAULT 0,
			override_note VARCHAR(255) NULL,
			notes TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			updated_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_schedule (studio_id,schedule_id),
			KEY studio_crew (studio_id,crew_member_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('crew_expenses')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			crew_member_id BIGINT UNSIGNED NOT NULL,
			schedule_id BIGINT UNSIGNED NULL,
			assignment_id BIGINT UNSIGNED NULL,
			role VARCHAR(40) NULL,
			agreed_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
			notes TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY assignment_id (assignment_id),
			KEY studio_crew (studio_id,crew_member_id),
			KEY schedule_id (schedule_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('crew_expense_payments')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			crew_expense_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(12,2) NOT NULL,
			paid_on DATE NOT NULL,
			payment_type VARCHAR(20) NOT NULL DEFAULT 'payment',
			method VARCHAR(30) NULL,
			reference VARCHAR(100) NULL,
			note VARCHAR(255) NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_paid (studio_id,paid_on),
			KEY crew_expense_id (crew_expense_id)
		) $c;";

		/* ---------- v0.5.0: public enquiry form ---------- */
		$sql[] = "CREATE TABLE {$t('public_enquiry_forms')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			slug VARCHAR(190) NOT NULL,
			is_enabled TINYINT(1) NOT NULL DEFAULT 1,
			title VARCHAR(190) NOT NULL,
			description TEXT NULL,
			logo_url VARCHAR(500) NULL,
			brand_color VARCHAR(7) NOT NULL DEFAULT '#F3724C',
			success_title VARCHAR(190) NULL,
			success_message TEXT NULL,
			whatsapp_number VARCHAR(32) NULL,
			consent_text VARCHAR(500) NULL,
			assign_mode VARCHAR(20) NOT NULL DEFAULT 'none',
			assign_member_id BIGINT UNSIGNED NULL,
			assign_pool TEXT NULL,
			assign_last_member_id BIGINT UNSIGNED NULL,
			captcha_site_key VARCHAR(190) NULL,
			captcha_secret VARCHAR(190) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			UNIQUE KEY studio_id (studio_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('enquiry_form_fields')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			form_id BIGINT UNSIGNED NOT NULL,
			field_key VARCHAR(60) NOT NULL,
			type VARCHAR(20) NOT NULL,
			label VARCHAR(190) NOT NULL,
			placeholder VARCHAR(190) NULL,
			help VARCHAR(255) NULL,
			options TEXT NULL,
			section VARCHAR(20) NOT NULL DEFAULT 'additional',
			is_core TINYINT(1) NOT NULL DEFAULT 0,
			is_required TINYINT(1) NOT NULL DEFAULT 0,
			is_enabled TINYINT(1) NOT NULL DEFAULT 1,
			show_for TEXT NULL,
			sort INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY form_key (form_id,field_key),
			KEY studio_form (studio_id,form_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('enquiry_submissions')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			form_id BIGINT UNSIGNED NOT NULL,
			lead_id BIGINT UNSIGNED NULL,
			duplicate_lead_id BIGINT UNSIGNED NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'lead_created',
			name VARCHAR(190) NOT NULL,
			phone VARCHAR(32) NULL,
			email VARCHAR(190) NULL,
			event_type VARCHAR(40) NULL,
			event_date DATE NULL,
			answers LONGTEXT NOT NULL,
			form_url VARCHAR(500) NULL,
			referrer VARCHAR(500) NULL,
			utm_source VARCHAR(190) NULL,
			utm_medium VARCHAR(190) NULL,
			utm_campaign VARCHAR(190) NULL,
			utm_term VARCHAR(190) NULL,
			utm_content VARCHAR(190) NULL,
			ip_hash VARCHAR(64) NULL,
			user_agent VARCHAR(255) NULL,
			token_hash VARCHAR(64) NULL,
			handled_by BIGINT UNSIGNED NULL,
			handled_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY studio_created (studio_id,created_at),
			KEY studio_status (studio_id,status),
			KEY studio_phone (studio_id,phone),
			KEY lead_id (lead_id)
		) $c;";

		/* ---------- v0.5.0: audit log for crew and enquiry actions ---------- */
		$sql[] = "CREATE TABLE {$t('audit_logs')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			entity VARCHAR(40) NOT NULL,
			entity_id BIGINT UNSIGNED NULL,
			action VARCHAR(40) NOT NULL,
			summary VARCHAR(500) NOT NULL,
			changes LONGTEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			ip_hash VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY studio_entity (studio_id,entity,entity_id),
			KEY studio_created (studio_id,created_at)
		) $c;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}

		$from = get_option( 'pcrm_db_version' );
		if ( false !== $from && $from !== PCRM_DB_VERSION ) {
			self::migrate( $from );
		} elseif ( false === $from ) {
			// Fresh install: studios created later get the defaults from Seeder::defaults().
			self::migrate( 0 );
		}
		update_option( 'pcrm_db_version', PCRM_DB_VERSION );
	}
}
