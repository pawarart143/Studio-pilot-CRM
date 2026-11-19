<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Reminders for tasks and call follow-ups (v0.6.0).
 *
 * Every reminder is a row in pcrm_notifications. A row is unique per
 * studio + dedupe key (kind:item:event:member:due_at), so running the
 * scheduler twice, two browser tabs polling, or a page load racing the cron
 * can never create the same reminder twice (INSERT IGNORE on a UNIQUE key).
 *
 * Events:
 *  - assigned : a task or follow-up was scheduled for / assigned to someone, or rescheduled.
 *  - reminder : the item is due within the studio's lead time (default 30 min).
 *  - due      : the due time has arrived (in-app ring; emailed only if no reminder went out).
 *
 * Emails are sent by the cron (every 5 minutes) only. Each email row is
 * claimed with an atomic UPDATE before sending, so one row = one email at most.
 */
class Reminders {

	const CRON_HOOK     = 'pcrm_reminders_tick';
	const SCHEDULE      = 'pcrm_five_minutes';
	const DB_OPTION     = 'pcrm_reminders_db_version';
	const DB_VERSION    = '1';
	const PREFS_META    = 'pcrm_notify_prefs';
	const LOCK_OPTION   = 'pcrm_reminders_lock';
	const DEFAULT_LEAD  = 30;
	const LOOKBACK_HRS  = 12;
	const MAX_ATTEMPTS  = 3;

	/** Set true while loading sample data so seeded items don't email anyone. */
	public static $mute = false;

	public static function init() {
		self::maybe_install();
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_filter( 'pcrm_watched_tables', array( __CLASS__, 'watched_tables' ) );
		add_action( 'pcrm_inserted', array( __CLASS__, 'on_insert' ), 10, 4 );
		add_action( 'pcrm_updated', array( __CLASS__, 'on_update' ), 10, 5 );
	}

	/* ------------------------------------------------------------------ */
	/* Install (own option, independent of PCRM_DB_VERSION)                */
	/* ------------------------------------------------------------------ */

	public static function maybe_install() {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = Installer::table( 'notifications' );
		dbDelta(
			"CREATE TABLE $t (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_id BIGINT UNSIGNED NOT NULL,
			member_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL,
			item_id BIGINT UNSIGNED NOT NULL,
			event VARCHAR(20) NOT NULL,
			dedupe_key VARCHAR(150) NOT NULL,
			due_at DATETIME NULL,
			title VARCHAR(255) NOT NULL,
			body VARCHAR(500) NULL,
			link VARCHAR(500) NULL,
			email_status VARCHAR(20) NOT NULL DEFAULT 'none',
			email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			email_claimed_at DATETIME NULL,
			emailed_at DATETIME NULL,
			email_error VARCHAR(255) NULL,
			alerted_at DATETIME NULL,
			read_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY studio_dedupe (studio_id,dedupe_key),
			KEY member_created (studio_id,member_id,created_at),
			KEY email_queue (email_status,studio_id),
			KEY item (studio_id,kind,item_id)
		) $c;"
		);
		if ( ! get_option( 'pcrm_reminders_started_at' ) ) {
			// Items due before this moment never get a catch-up "overdue" email.
			update_option( 'pcrm_reminders_started_at', time(), false );
		}
		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	public static function table() {
		return Installer::table( 'notifications' );
	}

	/* ------------------------------------------------------------------ */
	/* Scheduler                                                            */
	/* ------------------------------------------------------------------ */

	public static function cron_schedules( $s ) {
		$s[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every 5 minutes (ShootPilot reminders)',
		);
		return $s;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * One scheduler pass over every active studio. Safe to run concurrently:
	 * a short lock avoids wasted work, and the unique key plus the email claim
	 * make duplicates impossible even if the lock is bypassed.
	 */
	public static function run( $force = false ) {
		$now = time();
		if ( ! $force ) {
			$lock = (int) get_option( self::LOCK_OPTION );
			if ( $lock && $lock > $now - 4 * MINUTE_IN_SECONDS ) {
				return array( 'skipped' => 'locked' );
			}
		}
		update_option( self::LOCK_OPTION, $now, false );
		global $wpdb;
		$stats   = array( 'studios' => 0, 'created' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0 );
		$studios = $wpdb->get_col( 'SELECT id FROM ' . Installer::table( 'studios' ) . " WHERE status='active'" );
		foreach ( $studios as $sid ) {
			$sid = (int) $sid;
			++$stats['studios'];
			$stats['created'] += self::generate( $sid );
			$r                 = self::send_pending( $sid );
			$stats['sent']    += $r['sent'];
			$stats['failed']  += $r['failed'];
			$stats['skipped'] += $r['skipped'];
		}
		update_option( 'pcrm_reminders_last_run', $now, false );
		delete_option( self::LOCK_OPTION );
		return $stats;
	}

	/* ------------------------------------------------------------------ */
	/* Studio time and settings (works without a signed-in user)            */
	/* ------------------------------------------------------------------ */

	private static $studio_cache = array();

	public static function studio( $sid ) {
		if ( ! isset( self::$studio_cache[ $sid ] ) ) {
			self::$studio_cache[ $sid ] = Tenant::load_studio( $sid );
		}
		return self::$studio_cache[ $sid ];
	}

	public static function flush_cache() {
		self::$studio_cache = array();
	}

	public static function tz( $sid ) {
		$s    = self::studio( $sid );
		$name = $s && ! empty( $s->settings['timezone'] ) ? $s->settings['timezone'] : 'Asia/Kolkata';
		try {
			return new \DateTimeZone( $name );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'Asia/Kolkata' );
		}
	}

	/** Studio wall-clock "Y-m-d H:i:s" for a unix time (default now). */
	public static function local( $sid, $unix = null ) {
		$d = new \DateTime( '@' . ( null === $unix ? time() : (int) $unix ) );
		$d->setTimezone( self::tz( $sid ) );
		return $d->format( 'Y-m-d H:i:s' );
	}

	/** Unix time of a studio wall-clock "Y-m-d H:i:s". */
	public static function to_unix( $sid, $local ) {
		try {
			return ( new \DateTime( $local, self::tz( $sid ) ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return time();
		}
	}

	public static function lead_minutes( $sid ) {
		$s = self::studio( $sid );
		$v = $s && isset( $s->settings['reminder_lead_minutes'] ) ? (int) $s->settings['reminder_lead_minutes'] : self::DEFAULT_LEAD;
		return max( 5, min( 1440, $v ? $v : self::DEFAULT_LEAD ) );
	}

	public static function emails_enabled( $sid ) {
		$s = self::studio( $sid );
		return ! $s || ! isset( $s->settings['reminder_emails'] ) || (bool) $s->settings['reminder_emails'];
	}

	/* ------------------------------------------------------------------ */
	/* Per-user preferences                                                 */
	/* ------------------------------------------------------------------ */

	public static function default_prefs() {
		return array(
			'email_assigned' => true,
			'email_reminder' => true,
			'popup'          => true,
			'sound'          => true,
		);
	}

	public static function prefs( $user_id ) {
		$p = $user_id ? get_user_meta( (int) $user_id, self::PREFS_META, true ) : array();
		$p = is_array( $p ) ? $p : array();
		$out = array();
		foreach ( self::default_prefs() as $k => $v ) {
			$out[ $k ] = array_key_exists( $k, $p ) ? (bool) $p[ $k ] : $v;
		}
		return $out;
	}

	public static function save_prefs( $user_id, array $in ) {
		$cur = self::prefs( $user_id );
		foreach ( self::default_prefs() as $k => $v ) {
			if ( array_key_exists( $k, $in ) ) {
				$cur[ $k ] = filter_var( $in[ $k ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		update_user_meta( (int) $user_id, self::PREFS_META, $cur );
		return $cur;
	}

	/* ------------------------------------------------------------------ */
	/* Who may receive what                                                 */
	/* ------------------------------------------------------------------ */

	public static function member( $sid, $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'members' ) . ' WHERE id=%d AND studio_id=%d', $member_id, $sid ) );
	}

	/**
	 * A member gets reminders only for items their role lets them open:
	 * an active login, tasks.view for tasks, leads.view (and the lead in
	 * scope) for follow-ups.
	 */
	public static function eligible( $m, $kind, $item = null ) {
		if ( ! $m || 'active' !== $m->status || ! $m->user_id || ! get_userdata( (int) $m->user_id ) ) {
			return false;
		}
		if ( 'task' === $kind ) {
			return Permissions::can( $m, 'tasks.view' );
		}
		if ( 'followup' === $kind ) {
			if ( ! Permissions::can( $m, 'leads.view' ) ) {
				return false;
			}
			if ( Permissions::can( $m, 'leads.view_all' ) ) {
				return true;
			}
			return $item && isset( $item->lead_assigned_to ) && (int) $item->lead_assigned_to === (int) $m->id;
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Items                                                                */
	/* ------------------------------------------------------------------ */

	public static function load_item( $sid, $kind, $id ) {
		global $wpdb;
		if ( 'task' === $kind ) {
			return $wpdb->get_row( $wpdb->prepare( 'SELECT t.*, l.name AS lead_name, b.title AS booking_title, b.number AS booking_number FROM ' . Installer::table( 'tasks' ) . ' t LEFT JOIN ' . Installer::table( 'leads' ) . ' l ON l.id=t.lead_id LEFT JOIN ' . Installer::table( 'bookings' ) . ' b ON b.id=t.booking_id WHERE t.id=%d AND t.studio_id=%d', $id, $sid ) );
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT f.*, l.name AS lead_name, l.phone AS lead_phone, l.event_type, l.assigned_to AS lead_assigned_to FROM ' . Installer::table( 'followups' ) . ' f JOIN ' . Installer::table( 'leads' ) . ' l ON l.id=f.lead_id WHERE f.id=%d AND f.studio_id=%d', $id, $sid ) );
	}

	public static function is_open( $kind, $item ) {
		if ( ! $item ) {
			return false;
		}
		return 'task' === $kind ? 'done' !== $item->status : 'pending' === $item->status;
	}

	public static function link( $kind, $item ) {
		if ( 'task' === $kind ) {
			return home_url( '/crm/tasks?task=' . (int) $item->id );
		}
		return home_url( '/crm/leads/' . (int) $item->lead_id . '?followup=' . (int) $item->id );
	}

	public static function fmt( $dt ) {
		return $dt ? mysql2date( 'd-m-Y, h:i A', $dt ) : '';
	}

	private static function item_title( $kind, $item ) {
		return 'task' === $kind ? $item->title : 'Follow-up: ' . $item->lead_name;
	}

	private static function item_body( $kind, $item ) {
		if ( 'task' === $kind ) {
			$bits = array();
			if ( $item->booking_id && $item->booking_title ) {
				$bits[] = trim( $item->booking_number . ' · ' . $item->booking_title );
			} elseif ( $item->lead_id && $item->lead_name ) {
				$bits[] = 'Lead: ' . $item->lead_name;
			}
			if ( $item->description ) {
				$bits[] = wp_trim_words( $item->description, 20 );
			}
			return implode( ' — ', $bits );
		}
		$bits = array();
		if ( $item->lead_phone ) {
			$bits[] = $item->lead_phone;
		}
		if ( $item->note ) {
			$bits[] = wp_trim_words( $item->note, 20 );
		}
		return implode( ' — ', $bits );
	}

	public static function dedupe( $kind, $item_id, $event, $member_id, $due_at ) {
		return $kind . ':' . (int) $item_id . ':' . $event . ':' . (int) $member_id . ':' . ( $due_at ? str_replace( array( '-', ' ', ':' ), '', $due_at ) : 'none' );
	}

	/**
	 * Creates one notification row. Returns the new id, or 0 if it already existed.
	 */
	public static function create( $sid, $member_id, $kind, $item, $event, $email ) {
		global $wpdb;
		$due = $item->due_at ? $item->due_at : null;
		$key = self::dedupe( $kind, $item->id, $event, $member_id, $due );
		$ok  = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (studio_id, member_id, kind, item_id, event, dedupe_key, due_at, title, body, link, email_status, created_at) VALUES (%d,%d,%s,%d,%s,%s,' . ( $due ? '%s' : 'NULL' ) . ',%s,%s,%s,%s,%s)',
				array_values(
					array_filter(
						array( $sid, $member_id, $kind, $item->id, $event, $key, $due, mb_substr( self::item_title( $kind, $item ), 0, 255 ), mb_substr( self::item_body( $kind, $item ), 0, 500 ), self::link( $kind, $item ), $email ? 'pending' : 'none', self::local( $sid ) ),
						function ( $v ) {
							return null !== $v;
						}
					)
				)
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* ------------------------------------------------------------------ */
	/* "Assigned / scheduled" — from database writes                        */
	/* ------------------------------------------------------------------ */

	public static function watched_tables( $t ) {
		$t[] = 'tasks';
		$t[] = 'followups';
		return $t;
	}

	public static function on_insert( $table, $id, $data, $sid ) {
		if ( self::$mute || ! in_array( $table, array( 'tasks', 'followups' ), true ) ) {
			return;
		}
		self::queue_assigned( $sid, 'tasks' === $table ? 'task' : 'followup', $id );
	}

	public static function on_update( $table, $id, $old, $data, $sid ) {
		if ( self::$mute || ! $old || ! in_array( $table, array( 'tasks', 'followups' ), true ) ) {
			return;
		}
		$kind = 'tasks' === $table ? 'task' : 'followup';
		$new  = self::load_item( $sid, $kind, $id );
		if ( ! $new ) {
			return;
		}
		$changed = (int) $old->assigned_to !== (int) $new->assigned_to || (string) $old->due_at !== (string) $new->due_at;
		$reopened = 'task' === $kind ? ( 'done' === $old->status && 'done' !== $new->status ) : ( 'pending' !== $old->status && 'pending' === $new->status );
		if ( $changed || $reopened ) {
			self::queue_assigned( $sid, $kind, $id, $new );
		}
	}

	/**
	 * Queues the "scheduled for you" notification + email for the assignee.
	 */
	public static function queue_assigned( $sid, $kind, $id, $item = null ) {
		$item = $item ? $item : self::load_item( $sid, $kind, $id );
		if ( ! self::is_open( $kind, $item ) || ! $item->assigned_to ) {
			return 0;
		}
		// Long past items (e.g. imported history) don't need a "scheduled" email.
		if ( $item->due_at && $item->due_at < self::local( $sid, time() - DAY_IN_SECONDS ) ) {
			return 0;
		}
		$m = self::member( $sid, (int) $item->assigned_to );
		if ( ! self::eligible( $m, $kind, $item ) ) {
			return 0;
		}
		return self::create( $sid, (int) $m->id, $kind, $item, 'assigned', true );
	}

	/* ------------------------------------------------------------------ */
	/* "Reminder" and "Due" — from the scheduler and in-app polling         */
	/* ------------------------------------------------------------------ */

	/**
	 * Creates reminder/due notifications for items in the window. Idempotent.
	 * $member_id limits it to one person (used by the in-app poll).
	 */
	public static function generate( $sid, $member_id = 0 ) {
		global $wpdb;
		$now_u   = time();
		$now     = self::local( $sid, $now_u );
		$lead    = self::lead_minutes( $sid );
		$until   = self::local( $sid, $now_u + $lead * MINUTE_IN_SECONDS );
		$started = (int) get_option( 'pcrm_reminders_started_at', $now_u );
		$from_u  = max( $now_u - self::LOOKBACK_HRS * HOUR_IN_SECONDS, $started - HOUR_IN_SECONDS );
		$from    = self::local( $sid, $from_u );
		$started_local = self::local( $sid, $started );
		$m_sql   = $member_id ? $wpdb->prepare( ' AND x.assigned_to=%d', $member_id ) : '';
		$mt      = Installer::table( 'members' );

		$rows  = array();
		$tasks = $wpdb->get_col( $wpdb->prepare( 'SELECT x.id FROM ' . Installer::table( 'tasks' ) . " x JOIN $mt m ON m.id=x.assigned_to AND m.studio_id=x.studio_id WHERE x.studio_id=%d AND x.status<>'done' AND x.due_at IS NOT NULL AND x.due_at BETWEEN %s AND %s AND m.status='active' AND m.user_id IS NOT NULL" . $m_sql . ' LIMIT 500', $sid, $from, $until ) );
		foreach ( $tasks as $id ) {
			$rows[] = array( 'task', (int) $id );
		}
		$fups = $wpdb->get_col( $wpdb->prepare( 'SELECT x.id FROM ' . Installer::table( 'followups' ) . " x JOIN $mt m ON m.id=x.assigned_to AND m.studio_id=x.studio_id WHERE x.studio_id=%d AND x.status='pending' AND x.due_at BETWEEN %s AND %s AND m.status='active' AND m.user_id IS NOT NULL" . $m_sql . ' LIMIT 500', $sid, $from, $until ) );
		foreach ( $fups as $id ) {
			$rows[] = array( 'followup', (int) $id );
		}

		$created = 0;
		$t       = self::table();
		foreach ( $rows as $r ) {
			list( $kind, $id ) = $r;
			$item = self::load_item( $sid, $kind, $id );
			if ( ! self::is_open( $kind, $item ) ) {
				continue;
			}
			$m = self::member( $sid, (int) $item->assigned_to );
			if ( ! self::eligible( $m, $kind, $item ) ) {
				continue;
			}
			$mid = (int) $m->id;
			if ( $item->due_at > $now ) {
				// Skip the reminder email if the "scheduled" email went out inside the lead window.
				$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE studio_id=%d AND dedupe_key=%s AND created_at >= %s", $sid, self::dedupe( $kind, $id, 'assigned', $mid, $item->due_at ), self::local( $sid, self::to_unix( $sid, $item->due_at ) - $lead * MINUTE_IN_SECONDS ) ) );
				$created += self::create( $sid, $mid, $kind, $item, 'reminder', ! $recent ) ? 1 : 0;
			} else {
				// Due now. Email only if no reminder row exists (window missed) and it is not old history.
				$had = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE studio_id=%d AND dedupe_key=%s", $sid, self::dedupe( $kind, $id, 'reminder', $mid, $item->due_at ) ) );
				$created += self::create( $sid, $mid, $kind, $item, 'due', ! $had && $item->due_at >= $started_local ) ? 1 : 0;
			}
		}
		return $created;
	}

	/* ------------------------------------------------------------------ */
	/* Email                                                                */
	/* ------------------------------------------------------------------ */

	public static function send_pending( $sid, $limit = 50 ) {
		global $wpdb;
		$t   = self::table();
		$out = array( 'sent' => 0, 'failed' => 0, 'skipped' => 0 );
		// A claim older than 15 minutes means a run died mid-send. We can't know
		// whether the mail left, so it is marked failed rather than re-sent.
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET email_status='failed', email_error='Interrupted while sending' WHERE studio_id=%d AND email_status='sending' AND email_claimed_at < %s", $sid, self::local( $sid, time() - 15 * MINUTE_IN_SECONDS ) ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE studio_id=%d AND email_status='pending' ORDER BY id LIMIT %d", $sid, $limit ) );
		foreach ( $ids as $id ) {
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $t SET email_status='sending', email_claimed_at=%s, email_attempts=email_attempts+1 WHERE id=%d AND studio_id=%d AND email_status='pending'", self::local( $sid ), $id, $sid ) );
			if ( 1 !== (int) $claimed ) {
				continue; // Another run took it.
			}
			$n      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $id ) );
			$result = self::deliver( $sid, $n );
			if ( true === $result ) {
				$wpdb->update( $t, array( 'email_status' => 'sent', 'emailed_at' => self::local( $sid ), 'email_error' => null ), array( 'id' => $id ) );
				++$out['sent'];
			} elseif ( is_string( $result ) && 0 === strpos( $result, 'skip:' ) ) {
				$wpdb->update( $t, array( 'email_status' => 'skipped', 'email_error' => substr( $result, 5, 250 ) ), array( 'id' => $id ) );
				++$out['skipped'];
			} else {
				$retry = (int) $n->email_attempts < self::MAX_ATTEMPTS;
				$wpdb->update( $t, array( 'email_status' => $retry ? 'pending' : 'failed', 'email_error' => mb_substr( (string) $result, 0, 250 ) ), array( 'id' => $id ) );
				++$out['failed'];
			}
		}
		return $out;
	}

	/**
	 * Sends one notification email. Returns true, "skip:reason" or an error string.
	 */
	public static function deliver( $sid, $n ) {
		if ( ! self::emails_enabled( $sid ) ) {
			return 'skip:Studio reminder emails are off';
		}
		$item = self::load_item( $sid, $n->kind, (int) $n->item_id );
		if ( ! self::is_open( $n->kind, $item ) ) {
			return 'skip:Already completed or removed';
		}
		if ( (string) $item->due_at !== (string) $n->due_at || (int) $item->assigned_to !== (int) $n->member_id ) {
			return 'skip:Rescheduled or reassigned';
		}
		$m = self::member( $sid, (int) $n->member_id );
		if ( ! self::eligible( $m, $n->kind, $item ) ) {
			return 'skip:Recipient can no longer see this item';
		}
		$prefs = self::prefs( (int) $m->user_id );
		if ( 'assigned' === $n->event && ! $prefs['email_assigned'] ) {
			return 'skip:Recipient turned off scheduled emails';
		}
		if ( 'assigned' !== $n->event && ! $prefs['email_reminder'] ) {
			return 'skip:Recipient turned off reminder emails';
		}
		$user = get_userdata( (int) $m->user_id );
		$to   = $user && is_email( $user->user_email ) ? $user->user_email : ( is_email( $m->email ) ? $m->email : '' );
		if ( ! $to ) {
			return 'skip:No email address';
		}
		list( $subject, $html, $text ) = self::render( $sid, $n, $item, $m );
		return self::mail( $sid, $to, $subject, $html, $text );
	}

	/**
	 * wp_mail with the studio's name as sender name and Reply-To.
	 */
	public static function mail( $sid, $to, $subject, $html, $text = '' ) {
		$studio  = self::studio( $sid );
		$name    = $studio ? $studio->name : get_bloginfo( 'name' );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $studio && is_email( $studio->email ) ) {
			$headers[] = 'Reply-To: ' . $name . ' <' . $studio->email . '>';
		}
		$from_name = function () use ( $name ) {
			return $name;
		};
		$alt = function ( $phpmailer ) use ( $text ) {
			if ( $text ) {
				$phpmailer->AltBody = $text;
			}
		};
		$err = '';
		$fail = function ( $e ) use ( &$err ) {
			$err = $e instanceof \WP_Error ? $e->get_error_message() : 'wp_mail failed';
		};
		add_filter( 'wp_mail_from_name', $from_name, 99 );
		add_action( 'phpmailer_init', $alt );
		add_action( 'wp_mail_failed', $fail );
		$ok = wp_mail( $to, $subject, $html, $headers );
		remove_filter( 'wp_mail_from_name', $from_name, 99 );
		remove_action( 'phpmailer_init', $alt );
		remove_action( 'wp_mail_failed', $fail );
		return $ok ? true : ( $err ? $err : 'wp_mail returned false (check the server mail / SMTP setup)' );
	}

	public static function brand( $sid ) {
		global $wpdb;
		$c = $wpdb->get_var( $wpdb->prepare( 'SELECT brand_color FROM ' . Installer::table( 'public_enquiry_forms' ) . ' WHERE studio_id=%d LIMIT 1', $sid ) );
		return preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $c ) ? $c : '#F3724C';
	}

	/**
	 * Returns [subject, html, text].
	 */
	public static function render( $sid, $n, $item, $m ) {
		$studio = self::studio( $sid );
		$sname  = $studio ? $studio->name : get_bloginfo( 'name' );
		$is_t   = 'task' === $n->kind;
		$what   = $is_t ? 'task' : 'follow-up call';
		$title  = $is_t ? $item->title : $item->lead_name;
		$when   = $item->due_at ? self::fmt( $item->due_at ) : 'No deadline';
		$time   = $item->due_at ? mysql2date( 'h:i A', $item->due_at ) : '';
		$lead   = self::lead_minutes( $sid );
		$lead_s = $lead >= 60 && 0 === $lead % 60 ? ( $lead / 60 ) . ' hour' . ( 60 === $lead ? '' : 's' ) : $lead . ' min';

		switch ( $n->event ) {
			case 'assigned':
				$subject = $is_t ? 'New task: ' . $title . ( $item->due_at ? ' · due ' . $when : '' ) : 'Follow-up scheduled: ' . $title . ' · ' . $when;
				$heading = $is_t ? 'A task has been assigned to you' : 'A follow-up call is scheduled for you';
				break;
			case 'reminder':
				$subject = 'Reminder: ' . ( $is_t ? $title : 'follow up with ' . $title ) . ' at ' . $time;
				$heading = ucfirst( $what ) . ' due in about ' . $lead_s;
				break;
			default:
				$subject = 'Due now: ' . ( $is_t ? $title : 'follow up with ' . $title ) . ' (' . $when . ')';
				$heading = ucfirst( $what ) . ' is due now';
		}

		$rows = array();
		if ( $is_t ) {
			$rows['Task'] = $item->title;
			$cats         = Config::task_categories();
			$pri          = Config::priorities();
			if ( $item->category && isset( $cats[ $item->category ] ) ) {
				$rows['Category'] = $cats[ $item->category ];
			}
			$rows['Priority'] = isset( $pri[ $item->priority ] ) ? $pri[ $item->priority ] : $item->priority;
			if ( $item->booking_id && $item->booking_title ) {
				$rows['Booking'] = trim( $item->booking_number . ' · ' . $item->booking_title );
			} elseif ( $item->lead_id && $item->lead_name ) {
				$rows['Lead'] = $item->lead_name;
			}
		} else {
			$rows['Client / lead'] = $item->lead_name;
			if ( $item->lead_phone ) {
				$rows['Phone'] = $item->lead_phone;
			}
		}
		$rows['Due'] = $when;
		$note        = $is_t ? $item->description : $item->note;

		$brand = self::brand( $sid );
		$logo  = $studio && $studio->logo_url ? esc_url( $studio->logo_url ) : '';
		$link  = esc_url( $n->link );
		$e     = 'esc_html';

		$tr = '';
		foreach ( $rows as $k => $v ) {
			$tr .= '<tr><td style="padding:6px 0;color:#64748b;font-size:13px;width:120px;vertical-align:top">' . $e( $k ) . '</td><td style="padding:6px 0;color:#0f172a;font-size:14px;font-weight:600">' . $e( $v ) . '</td></tr>';
		}
		$head = $logo
			? '<img src="' . $logo . '" alt="' . esc_attr( $sname ) . '" style="max-height:44px;max-width:180px;display:block">'
			: '<span style="font-size:18px;font-weight:700;color:#0f172a">' . $e( $sname ) . '</span>';

		$html  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e( $subject ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">';
		$html .= '<tr><td style="height:4px;background:' . esc_attr( $brand ) . '"></td></tr>';
		$html .= '<tr><td style="padding:22px 28px 6px">' . $head . '</td></tr>';
		$html .= '<tr><td style="padding:10px 28px 0"><p style="margin:0 0 4px;color:#64748b;font-size:13px">Hi ' . $e( $m->name ) . ',</p><h1 style="margin:0;font-size:20px;line-height:1.3;color:#0f172a">' . $e( $heading ) . '</h1></td></tr>';
		$html .= '<tr><td style="padding:14px 28px 4px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:6px 0">' . $tr . '</table></td></tr>';
		if ( $note ) {
			$html .= '<tr><td style="padding:10px 28px 0"><p style="margin:0;padding:10px 12px;background:#f8fafc;border-radius:8px;color:#334155;font-size:13px;line-height:1.5">' . nl2br( $e( wp_trim_words( $note, 60 ) ) ) . '</p></td></tr>';
		}
		$html .= '<tr><td style="padding:20px 28px 8px"><a href="' . $link . '" style="display:inline-block;background:' . esc_attr( $brand ) . ';color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 20px;border-radius:8px">Open ' . $e( $what ) . '</a></td></tr>';
		$html .= '<tr><td style="padding:4px 28px 22px;color:#94a3b8;font-size:12px;line-height:1.5">Or copy this link: ' . $link . '</td></tr>';
		$html .= '<tr><td style="padding:14px 28px;background:#f8fafc;color:#94a3b8;font-size:11px;line-height:1.5;border-top:1px solid #e2e8f0">Sent by ShootPilot for ' . $e( $sname ) . '. Change which reminder emails you get from the bell icon in the CRM → Preferences.</td></tr>';
		$html .= '</table></td></tr></table></body></html>';

		$text = $heading . "\n\n";
		foreach ( $rows as $k => $v ) {
			$text .= $k . ': ' . $v . "\n";
		}
		if ( $note ) {
			$text .= "\n" . wp_trim_words( $note, 60 ) . "\n";
		}
		$text .= "\nOpen: " . $n->link . "\n";

		return array( $subject, $html, $text );
	}
}
