<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Reminders;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Notification centre, in-app alerts and per-user reminder preferences (v0.6.0).
 * Every route works on the signed-in member's own notifications only.
 */
class Notifications extends Base {

	public function register() {
		$this->route( '/notifications', 'GET', array( $this, 'index' ), 'member' );
		$this->route( '/notifications/(?P<id>\d+)/alert', 'POST', array( $this, 'claim_alert' ), 'member' );
		$this->route( '/notifications/read', 'POST', array( $this, 'mark_read' ), 'member' );
		$this->route( '/notifications/prefs', 'GET', array( $this, 'get_prefs' ), 'member' );
		$this->route( '/notifications/prefs', 'PUT', array( $this, 'put_prefs' ), 'member' );
		$this->route( '/notifications/test-email', 'POST', array( $this, 'test_email' ), 'member' );
		$this->route( '/notifications/status', 'GET', array( $this, 'status' ), 'settings.edit' );
	}

	private function t() {
		return Reminders::table();
	}

	private function shape( $rows ) {
		return array_map(
			function ( $r ) {
				return array(
					'id'           => (int) $r->id,
					'kind'         => $r->kind,
					'item_id'      => (int) $r->item_id,
					'event'        => $r->event,
					'title'        => $r->title,
					'body'         => $r->body,
					'due_at'       => $r->due_at,
					'path'         => $this->path( $r->link ),
					'created_at'   => $r->created_at,
					'read'         => (bool) $r->read_at,
					'alerted'      => (bool) $r->alerted_at,
					'email_status' => $r->email_status,
				);
			},
			$rows ? $rows : array()
		);
	}

	/** In-app path under /crm (the SPA base) from the absolute link. */
	private function path( $link ) {
		$base = untrailingslashit( wp_parse_url( home_url( '/crm' ), PHP_URL_PATH ) );
		$p    = (string) wp_parse_url( $link, PHP_URL_PATH );
		$q    = (string) wp_parse_url( $link, PHP_URL_QUERY );
		if ( $base && 0 === strpos( $p, $base ) ) {
			$p = substr( $p, strlen( $base ) );
		}
		return ( $p ? $p : '/' ) . ( $q ? '?' . $q : '' );
	}

	public function index( $req ) {
		global $wpdb;
		$sid = $this->sid();
		$mid = $this->member_id();
		// Make sure reminders for this person exist right now, even if the
		// server cron is late. Idempotent; emails are still only sent by cron.
		Reminders::generate( $sid, $mid );

		$limit = min( 100, max( 10, (int) $req->get_param( 'limit' ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->t() . ' WHERE studio_id=%d AND member_id=%d ORDER BY created_at DESC, id DESC LIMIT %d', $sid, $mid, $limit ) );
		$unread = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->t() . ' WHERE studio_id=%d AND member_id=%d AND read_at IS NULL', $sid, $mid ) );

		return $this->ok(
			array(
				'items'        => $this->shape( $rows ),
				'unread'       => $unread,
				'due'          => $this->due_list( $sid, $mid ),
				'prefs'        => Reminders::prefs( get_current_user_id() ),
				'lead_minutes' => Reminders::lead_minutes( $sid ),
				'now'          => Db::now(),
			)
		);
	}

	/**
	 * My open tasks and follow-ups that are overdue or due before the end of today
	 * (or within the reminder window if that reaches past midnight).
	 */
	private function due_list( $sid, $mid ) {
		global $wpdb;
		$until = max( Db::today() . ' 23:59:59', Reminders::local( $sid, time() + Reminders::lead_minutes( $sid ) * MINUTE_IN_SECONDS ) );
		$from  = Reminders::local( $sid, time() - 30 * DAY_IN_SECONDS );
		$out   = array();
		if ( Tenant::can( 'tasks.view' ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.id, t.title, t.due_at, t.priority FROM ' . Db::t( 'tasks' ) . " t WHERE t.studio_id=%d AND t.assigned_to=%d AND t.status<>'done' AND t.due_at IS NOT NULL AND t.due_at BETWEEN %s AND %s ORDER BY t.due_at LIMIT 50", $sid, $mid, $from, $until ) );
			foreach ( $rows as $r ) {
				$out[] = array( 'kind' => 'task', 'id' => (int) $r->id, 'title' => $r->title, 'due_at' => $r->due_at, 'path' => '/tasks?task=' . (int) $r->id );
			}
		}
		if ( Tenant::can( 'leads.view' ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT f.id, f.lead_id, f.due_at, f.note, l.name AS lead_name FROM ' . Db::t( 'followups' ) . ' f JOIN ' . Db::t( 'leads' ) . " l ON l.id=f.lead_id WHERE f.studio_id=%d AND f.assigned_to=%d AND f.status='pending' AND f.due_at BETWEEN %s AND %s ORDER BY f.due_at LIMIT 50", $sid, $mid, $from, $until ) );
			foreach ( $rows as $r ) {
				$out[] = array( 'kind' => 'followup', 'id' => (int) $r->id, 'title' => 'Follow-up: ' . $r->lead_name, 'note' => $r->note, 'due_at' => $r->due_at, 'path' => '/leads/' . (int) $r->lead_id . '?followup=' . (int) $r->id );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['due_at'], $b['due_at'] );
			}
		);
		return $out;
	}

	/**
	 * The first tab/device to claim an alert shows it (with sound); every other
	 * tab gets claimed=false, so the same reminder never rings twice.
	 */
	public function claim_alert( $req ) {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->t() . ' SET alerted_at=%s WHERE id=%d AND studio_id=%d AND member_id=%d AND alerted_at IS NULL', Db::now(), (int) $req['id'], $this->sid(), $this->member_id() ) );
		return $this->ok( array( 'claimed' => 1 === (int) $n ) );
	}

	public function mark_read( $req ) {
		global $wpdb;
		$b   = $this->body( $req );
		$sid = $this->sid();
		$mid = $this->member_id();
		if ( ! empty( $b['all'] ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->t() . ' SET read_at=%s, alerted_at=COALESCE(alerted_at,%s) WHERE studio_id=%d AND member_id=%d AND read_at IS NULL', Db::now(), Db::now(), $sid, $mid ) );
		} else {
			$ids = isset( $b['ids'] ) ? $this->ids_in( (array) $b['ids'] ) : '0';
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->t() . " SET read_at=%s, alerted_at=COALESCE(alerted_at,%s) WHERE studio_id=%d AND member_id=%d AND id IN ($ids) AND read_at IS NULL", Db::now(), Db::now(), $sid, $mid ) );
		}
		$unread = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->t() . ' WHERE studio_id=%d AND member_id=%d AND read_at IS NULL', $sid, $mid ) );
		return $this->ok( array( 'unread' => $unread ) );
	}

	public function get_prefs() {
		return $this->ok( Reminders::prefs( get_current_user_id() ) );
	}

	public function put_prefs( $req ) {
		$b = $this->body( $req );
		if ( ! is_array( $b ) ) {
			return new \WP_Error( 'validation_failed', 'Nothing to save.', array( 'status' => 422 ) );
		}
		return $this->ok( Reminders::save_prefs( get_current_user_id(), $b ) );
	}

	/**
	 * Sends a sample reminder email to the signed-in user, so the studio can
	 * check that their server actually delivers mail.
	 */
	public function test_email() {
		$uid = get_current_user_id();
		$key = 'pcrm_test_mail_' . $uid;
		if ( get_transient( $key ) ) {
			return new \WP_Error( 'rate_limited', 'Please wait a minute before sending another test email.', array( 'status' => 429 ) );
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		$u   = wp_get_current_user();
		$sid = $this->sid();
		$m   = Tenant::member();
		$fake = (object) array(
			'id'          => 0,
			'title'       => 'Test reminder – check your email setup',
			'description' => 'If you can read this, ShootPilot reminder emails reach your inbox. Check your spam folder too, and mark this as "Not spam" if it landed there.',
			'category'    => 'other',
			'priority'    => 'medium',
			'booking_id'  => null,
			'lead_id'     => null,
			'due_at'      => Reminders::local( $sid, time() + Reminders::lead_minutes( $sid ) * MINUTE_IN_SECONDS ),
		);
		$n = (object) array( 'kind' => 'task', 'event' => 'reminder', 'link' => home_url( '/crm/tasks' ) );
		list( $subject, $html, $text ) = Reminders::render( $sid, $n, $fake, $m );
		$r = Reminders::mail( $sid, $u->user_email, '[Test] ' . $subject, $html, $text );
		if ( true !== $r ) {
			return new \WP_Error( 'mail_failed', 'Your server could not send the email: ' . $r . '. Install an SMTP plugin (for example WP Mail SMTP) and try again.', array( 'status' => 502 ) );
		}
		return $this->ok( array( 'sent' => true, 'to' => $u->user_email ) );
	}

	/** Scheduler health for Settings → Reminders. */
	public function status() {
		global $wpdb;
		$sid  = $this->sid();
		$last = (int) get_option( 'pcrm_reminders_last_run' );
		$next = wp_next_scheduled( Reminders::CRON_HOOK );
		$since = Reminders::local( $sid, time() - 7 * DAY_IN_SECONDS );
		$counts = $wpdb->get_results( $wpdb->prepare( 'SELECT email_status, COUNT(*) n FROM ' . $this->t() . " WHERE studio_id=%d AND created_at >= %s AND email_status<>'none' GROUP BY email_status", $sid, $since ), OBJECT_K );
		$err = $wpdb->get_row( $wpdb->prepare( 'SELECT email_error, created_at FROM ' . $this->t() . " WHERE studio_id=%d AND email_status='failed' ORDER BY id DESC LIMIT 1", $sid ) );
		$c = array();
		foreach ( array( 'sent', 'pending', 'failed', 'skipped' ) as $k ) {
			$c[ $k ] = isset( $counts[ $k ] ) ? (int) $counts[ $k ]->n : 0;
		}
		return $this->ok(
			array(
				'last_run'        => $last ? Reminders::local( $sid, $last ) : null,
				'last_run_ago'    => $last ? time() - $last : null,
				'next_run'        => $next ? Reminders::local( $sid, $next ) : null,
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'cron_url'        => site_url( 'wp-cron.php?doing_wp_cron' ),
				'emails_7d'       => $c,
				'last_error'      => $err ? array( 'message' => $err->email_error, 'at' => $err->created_at ) : null,
				'smtp_plugin'     => $this->smtp_plugin(),
			)
		);
	}

	private function smtp_plugin() {
		$known = array(
			'wp-mail-smtp/wp_mail_smtp.php'       => 'WP Mail SMTP',
			'post-smtp/postman-smtp.php'          => 'Post SMTP',
			'fluent-smtp/fluent-smtp.php'         => 'FluentSMTP',
			'easy-wp-smtp/easy-wp-smtp.php'       => 'Easy WP SMTP',
			'smtp-mailer/main.php'                => 'SMTP Mailer',
			'brevo/sendinblue.php'                => 'Brevo',
			'mailgun/mailgun.php'                 => 'Mailgun',
			'wp-ses/wp-ses.php'                   => 'WP Offload SES',
		);
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $known as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				return $name;
			}
		}
		return null;
	}
}
