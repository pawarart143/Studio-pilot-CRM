<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Private client delivery portal: one per booking at /portal/{token}.
 *
 * Security:
 * - The token is 48 random hex characters (192 bits), never a database id.
 * - The PIN is checked against a WordPress password hash. A second copy is
 *   kept encrypted (XSalsa20-Poly1305, key derived from this site's
 *   SECURE_AUTH salts and the portal id) so owners/admins can view it again.
 *   It is never stored as plain text. If the site salts change, the copy can't
 *   be read any more and the admin simply generates a new PIN.
 * - Status: "not_activated" until a PIN exists, then "active", or "disabled"
 *   when the studio turns it off.
 * - Wrong PINs lock the portal for 15 minutes after 5 tries (then 30, 60 …
 *   up to 24 hours), and one IP address gets at most 20 wrong tries per hour
 *   across all portals.
 * - After a correct PIN the browser gets a signed, HttpOnly cookie that stops
 *   working as soon as the PIN is changed or reset.
 */
class Portals {

	const MAX_TRIES     = 5;
	const IP_MAX_TRIES  = 20;
	const SESSION_DAYS  = 7;

	/* ---------- Records ---------- */

	public static function new_token() {
		return bin2hex( random_bytes( 24 ) );
	}

	public static function for_booking( $booking_id, $studio_id = null ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'client_portals' ) . ' WHERE studio_id=%d AND booking_id=%d', Db::sid( $studio_id ), $booking_id ) );
	}

	/**
	 * Returns the booking's portal, creating it if needed.
	 */
	public static function ensure( $booking_id, $studio_id = null ) {
		$row = self::for_booking( $booking_id, $studio_id );
		if ( $row ) {
			return $row;
		}
		if ( ! Db::exists( 'bookings', $booking_id, $studio_id ) ) {
			return null;
		}
		$id = Db::insert(
			'client_portals',
			array(
				'booking_id' => (int) $booking_id,
				'token'      => self::new_token(),
				'is_enabled' => 1,
				'created_by' => get_current_user_id() ? get_current_user_id() : null,
			),
			$studio_id
		);
		if ( is_wp_error( $id ) ) {
			// Another request may have created it at the same moment (unique key).
			return self::for_booking( $booking_id, $studio_id );
		}
		return self::for_booking( $booking_id, $studio_id );
	}

	public static function url( $row ) {
		return $row ? home_url( '/portal/' . $row->token ) : '';
	}

	public static function url_for_booking( $booking_id ) {
		return self::url( self::ensure( $booking_id ) );
	}

	/** not_activated (no PIN yet), active, or disabled. */
	public static function status( $row ) {
		if ( ! $row || empty( $row->pin_hash ) ) {
			return 'not_activated';
		}
		return (int) $row->is_enabled ? 'active' : 'disabled';
	}

	private static function pin_key( $row ) {
		return hash_hmac( 'sha256', 'pcrm-portal-pin|' . (int) $row->id . '|' . (int) $row->studio_id, wp_salt( 'secure_auth' ), true );
	}

	public static function encrypt_pin( $row, $pin ) {
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $pin, $nonce, self::pin_key( $row ) ) );
		} catch ( \Throwable $e ) {
			error_log( '[pcrm] PIN encryption unavailable: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * The current PIN in plain text for the studio's owner/admin, or null when
	 * it can't be shown (set before v0.4.1, or the site's salts were changed).
	 */
	public static function reveal_pin( $row ) {
		if ( ! $row || empty( $row->pin_hash ) || empty( $row->pin_encrypted ) || 0 !== strpos( $row->pin_encrypted, 'v1:' ) ) {
			return null;
		}
		try {
			$raw = base64_decode( substr( $row->pin_encrypted, 3 ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return null;
			}
			$pin = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::pin_key( $row ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		// Only return it if it still matches the hash that the portal checks.
		return ( false !== $pin && wp_check_password( $pin, $row->pin_hash ) ) ? $pin : null;
	}

	public static function summary( $row ) {
		if ( ! $row ) {
			return null;
		}
		$locked = $row->locked_until && strtotime( $row->locked_until . ' UTC' ) > time();
		return array(
			'url'             => self::url( $row ),
			'status'          => self::status( $row ),
			'enabled'         => (bool) (int) $row->is_enabled,
			'has_pin'         => ! empty( $row->pin_hash ),
			'pin_viewable'    => ! empty( $row->pin_hash ) && ! empty( $row->pin_encrypted ),
			'activated_at'    => isset( $row->activated_at ) ? $row->activated_at : null,
			'pin_set_at'      => $row->pin_set_at,
			'last_viewed_at'  => $row->last_viewed_at,
			'view_count'      => (int) $row->view_count,
			'failed_attempts' => (int) $row->failed_attempts,
			'locked'          => $locked,
			'locked_minutes'  => $locked ? (int) ceil( ( strtotime( $row->locked_until . ' UTC' ) - time() ) / 60 ) : 0,
		);
	}

	/**
	 * Validates a custom PIN: 4 to 8 digits, not all the same, not 1234-style.
	 */
	public static function check_pin_strength( $pin ) {
		if ( ! preg_match( '/^\d{4,8}$/', $pin ) ) {
			return new \WP_Error( 'validation_failed', 'PIN must be 4 to 8 digits.', array( 'status' => 422, 'errors' => array( 'pin' => '4 to 8 digits.' ) ) );
		}
		if ( preg_match( '/^(\d)\1+$/', $pin ) || false !== strpos( '0123456789', $pin ) || false !== strpos( '9876543210', $pin ) ) {
			return new \WP_Error( 'validation_failed', 'This PIN is too easy to guess. Avoid repeated or sequential digits.', array( 'status' => 422, 'errors' => array( 'pin' => 'Too easy to guess.' ) ) );
		}
		return true;
	}

	/**
	 * Sets a new PIN (random 6 digits when $pin is null). Signs out every
	 * device that opened the portal with the old PIN and clears any lock.
	 * Returns the plain PIN so the admin can share it once.
	 */
	public static function set_pin( $row, $pin = null ) {
		if ( null === $pin || '' === $pin ) {
			do {
				$pin = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			} while ( is_wp_error( self::check_pin_strength( $pin ) ) );
		} else {
			$pin = preg_replace( '/\s+/', '', (string) $pin );
			$ok  = self::check_pin_strength( $pin );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		global $wpdb;
		$data = array(
			'pin_hash'        => wp_hash_password( $pin ),
			'pin_encrypted'   => self::encrypt_pin( $row, $pin ),
			'pin_version'     => (int) $row->pin_version + 1,
			'pin_set_at'      => Db::now(),
			'failed_attempts' => 0,
			'locked_until'    => null,
			'updated_at'      => Db::now(),
		);
		if ( empty( $row->pin_hash ) ) {
			// The first PIN activates the portal.
			$data['is_enabled']   = 1;
			$data['activated_at'] = Db::now();
		}
		$ok = $wpdb->update( Db::t( 'client_portals' ), $data, array( 'id' => (int) $row->id, 'studio_id' => (int) $row->studio_id ) );
		if ( false === $ok ) {
			error_log( '[pcrm] Could not save portal PIN: ' . $wpdb->last_error );
			return new \WP_Error( 'db_error', 'The PIN could not be saved. Please try again. (' . $wpdb->last_error . ')', array( 'status' => 500 ) );
		}
		// Read it back, so the admin never sees a PIN the portal won't accept.
		$saved = $wpdb->get_var( $wpdb->prepare( 'SELECT pin_hash FROM ' . Db::t( 'client_portals' ) . ' WHERE id=%d AND studio_id=%d', $row->id, $row->studio_id ) );
		if ( ! $saved || ! wp_check_password( $pin, $saved ) ) {
			return new \WP_Error( 'db_error', 'The PIN could not be saved. Please try again.', array( 'status' => 500 ) );
		}
		Plugin::purge_url( self::url( $row ) );
		return $pin;
	}

	/* ---------- Public page ---------- */

	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}

	private static function ip_key() {
		return 'pcrm_pin_ip_' . md5( self::ip() . wp_salt( 'nonce' ) );
	}

	private static function cookie_name( $row ) {
		return 'pcrm_portal_' . (int) $row->id;
	}

	private static function sign( $row, $exp ) {
		return hash_hmac( 'sha256', $row->id . '|' . $row->pin_version . '|' . $exp, wp_salt( 'auth' ) . $row->pin_hash );
	}

	private static function has_session( $row ) {
		$name = self::cookie_name( $row );
		if ( empty( $_COOKIE[ $name ] ) || empty( $row->pin_hash ) ) {
			return false;
		}
		$parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) );
		if ( count( $parts ) !== 3 ) {
			return false;
		}
		list( $ver, $exp, $sig ) = $parts;
		if ( (int) $ver !== (int) $row->pin_version || (int) $exp < time() ) {
			return false;
		}
		return hash_equals( self::sign( $row, (int) $exp ), $sig );
	}

	private static function start_session( $row ) {
		$exp = time() + self::SESSION_DAYS * DAY_IN_SECONDS;
		$val = (int) $row->pin_version . '.' . $exp . '.' . self::sign( $row, $exp );
		setcookie(
			self::cookie_name( $row ),
			$val,
			array(
				'expires'  => $exp,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private static function end_session( $row ) {
		setcookie( self::cookie_name( $row ), '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}

	/**
	 * Checks a PIN with brute-force protection. Returns true or an error message.
	 */
	public static function attempt( $row, $pin ) {
		global $wpdb;
		$t = Db::t( 'client_portals' );
		if ( $row->locked_until && strtotime( $row->locked_until . ' UTC' ) > time() ) {
			$mins = (int) ceil( ( strtotime( $row->locked_until . ' UTC' ) - time() ) / 60 );
			return sprintf( 'Too many wrong PINs. Please try again in %d minute%s, or ask the studio to reset your PIN.', $mins, 1 === $mins ? '' : 's' );
		}
		$ip_tries = (int) get_transient( self::ip_key() );
		if ( $ip_tries >= self::IP_MAX_TRIES ) {
			return 'Too many attempts from this network. Please try again in an hour.';
		}
		$pin = preg_replace( '/\D+/', '', (string) $pin );
		if ( '' !== $pin && strlen( $pin ) <= 8 && wp_check_password( $pin, $row->pin_hash ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET failed_attempts=0, locked_until=NULL WHERE id=%d", $row->id ) );
			return true;
		}
		set_transient( self::ip_key(), $ip_tries + 1, HOUR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET failed_attempts=failed_attempts+1 WHERE id=%d", $row->id ) );
		$fails = (int) $wpdb->get_var( $wpdb->prepare( "SELECT failed_attempts FROM $t WHERE id=%d", $row->id ) );
		if ( $fails > 0 && 0 === $fails % self::MAX_TRIES ) {
			$mins = min( 1440, 15 * ( 2 ** ( (int) ( $fails / self::MAX_TRIES ) - 1 ) ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET locked_until=%s WHERE id=%d", gmdate( 'Y-m-d H:i:s', time() + $mins * 60 ), $row->id ) );
			Db::log( 'portal', 'Client portal locked for ' . $mins . ' minutes after ' . $fails . ' wrong PIN attempts', array( 'booking_id' => (int) $row->booking_id ), array(), (int) $row->studio_id );
			return sprintf( 'Too many wrong PINs. The portal is locked for %d minutes.', $mins );
		}
		$left = self::MAX_TRIES - ( $fails % self::MAX_TRIES );
		return sprintf( 'Incorrect PIN. Please try again. %d attempt%s left before the portal is locked.', $left, 1 === $left ? '' : 's' );
	}

	/**
	 * Redirects back to the same portal on the same host the visitor used
	 * (www or not, http or https), so the unlock cookie is always sent back.
	 */
	private static function reload( $row ) {
		$path = wp_parse_url( self::url( $row ), PHP_URL_PATH );
		header( 'Location: ' . $path, true, 303 );
		exit;
	}

	public static function render( $token ) {
		global $wpdb;
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src * data:; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" );

		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $token ) );
		$row   = strlen( $token ) >= 32 ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'client_portals' ) . ' WHERE token=%s', $token ) ) : null;
		if ( ! $row ) {
			status_header( 404 );
			self::page( 'Portal not found', null, self::notice( 'Portal not found', 'This link is not valid. Please check the link your studio sent you.' ) );
			return;
		}
		$sid     = (int) $row->studio_id;
		$studio  = Tenant::load_studio( $sid );
		$booking = Db::find( 'bookings', (int) $row->booking_id, $sid );
		if ( ! $studio || 'active' !== $studio->status || ! $booking ) {
			status_header( 404 );
			self::page( 'Unavailable', $studio, self::notice( 'Portal unavailable', 'This portal is not available right now.' ) );
			return;
		}
		if ( ! (int) $row->is_enabled || 'cancelled' === $booking->status ) {
			status_header( 403 );
			self::page( 'Portal paused', $studio, self::notice( 'This portal is currently turned off', 'Please contact ' . $studio->name . ' for an update on your project.' ), $studio );
			return;
		}
		if ( empty( $row->pin_hash ) ) {
			self::page( 'Not activated yet', $studio, self::notice( 'Portal not activated yet', $studio->name . ' has not activated access to this portal yet. Please contact them for your PIN.' ), $studio );
			return;
		}

		$error = '';
		$post  = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ); // phpcs:ignore
		if ( $post && isset( $_POST['pcrm_logout'] ) ) { // phpcs:ignore
			self::end_session( $row );
			self::reload( $row );
		}
		if ( $post && isset( $_POST['pin'] ) ) { // phpcs:ignore
			$res = self::attempt( $row, wp_unslash( $_POST['pin'] ) ); // phpcs:ignore
			if ( true === $res ) {
				self::start_session( $row );
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'client_portals' ) . ' SET view_count=view_count+1, last_viewed_at=%s WHERE id=%d', Db::now(), $row->id ) );
				// Post/redirect/get so a refresh doesn't resend the PIN.
				self::reload( $row );
			}
			$error = $res;
			status_header( 401 );
		}
		if ( ! self::has_session( $row ) ) {
			self::page( 'Enter PIN', $studio, self::pin_form( $studio, $error ), $studio );
			return;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'client_portals' ) . ' SET last_viewed_at=%s WHERE id=%d', Db::now(), $row->id ) );
		self::page( $booking->title, $studio, self::project( $studio, $booking, $row ), $studio, true );
	}

	/* ---------- Views ---------- */

	private static function e( $s ) {
		return esc_html( (string) $s );
	}

	private static function notice( $title, $text ) {
		return '<div class="card center"><div class="icon">i</div><h1>' . self::e( $title ) . '</h1><p class="muted">' . self::e( $text ) . '</p></div>';
	}

	private static function pin_form( $studio, $error ) {
		ob_start();
		?>
		<div class="card center pin">
			<div class="lock" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
			<h1>Your private delivery portal</h1>
			<p class="muted">Enter the PIN shared by <?php echo self::e( $studio->name ); ?> to see the progress of your photos and videos.</p>
			<?php if ( $error ) : ?><div class="alert" role="alert"><?php echo self::e( $error ); ?></div><?php endif; ?>
			<form method="post" autocomplete="off">
				<label for="pin" class="sr">PIN</label>
				<input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" minlength="4" required autofocus placeholder="••••••" aria-label="PIN">
				<button type="submit">View my project</button>
			</form>
			<p class="small muted">Lost your PIN? Ask the studio to reset it for you.</p>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function project( $studio, $booking, $row ) {
		global $wpdb;
		$sid    = (int) $studio->id;
		$client = Db::find( 'clients', (int) $booking->client_id, $sid );
		$events = $wpdb->get_results( $wpdb->prepare( 'SELECT name, event_date, start_time, venue, city FROM ' . Db::t( 'booking_events' ) . ' WHERE studio_id=%d AND booking_id=%d ORDER BY event_date, start_time, id', $sid, $booking->id ) );
		// Only client-safe columns: no notes, team, or payment data.
		$dels   = $wpdb->get_results( $wpdb->prepare( 'SELECT name, status, progress, expected_date, delivery_link, updated_at FROM ' . Db::t( 'booking_deliverables' ) . ' WHERE studio_id=%d AND booking_id=%d ORDER BY sort, id', $sid, $booking->id ) );
		$st     = Config::deliverable_statuses();
		$overall = Rest\Deliverables::overall( $dels );
		$done   = count( array_filter( $dels, function ( $d ) {
			return 'delivered' === $d->status;
		} ) );
		$last   = $booking->updated_at;
		foreach ( $dels as $d ) {
			if ( $d->updated_at > $last ) {
				$last = $d->updated_at;
			}
		}
		$labels = array(
			'confirmed'    => 'Booking confirmed',
			'shoot_done'   => 'Event completed',
			'editing'      => 'Editing in progress',
			'album_design' => 'Album design',
			'delivered'    => 'Delivered',
			'completed'    => 'Completed',
		);
		$types  = Config::event_types();
		$tone   = array(
			'not_started'        => 'gray',
			'in_progress'        => 'blue',
			'editing'            => 'violet',
			'awaiting_selection' => 'amber',
			'under_review'       => 'blue',
			'ready'              => 'teal',
			'delivered'          => 'green',
			'revision_requested' => 'red',
		);
		$dates  = array_unique( wp_list_pluck( $events, 'event_date' ) );
		$date_text = $dates ? ( count( $dates ) > 1 ? Format::date( min( $dates ) ) . ' – ' . Format::date( max( $dates ) ) : Format::date( reset( $dates ) ) ) : '—';
		ob_start();
		?>
		<section class="card hero">
			<p class="eyebrow">Hi <?php echo self::e( $client ? $client->name : '' ); ?> 👋</p>
			<h1><?php echo self::e( $booking->title ); ?></h1>
			<div class="meta">
				<div><span>Booking ID</span><b><?php echo self::e( $booking->number ); ?></b></div>
				<div><span>Event date</span><b><?php echo self::e( $date_text ); ?></b></div>
				<div><span>Status</span><b><span class="pill brand"><?php echo self::e( isset( $labels[ $booking->status ] ) ? $labels[ $booking->status ] : $booking->status ); ?></span></b></div>
			</div>
			<div class="progress-wrap">
				<div class="progress-head"><span>Overall delivery progress</span><b><?php echo (int) $overall; ?>%</b></div>
				<div class="bar" role="progressbar" aria-valuenow="<?php echo (int) $overall; ?>" aria-valuemin="0" aria-valuemax="100"><i style="width:<?php echo (int) $overall; ?>%"></i></div>
				<p class="small muted"><?php echo (int) $done; ?> of <?php echo count( $dels ); ?> deliverable<?php echo 1 === count( $dels ) ? '' : 's'; ?> delivered · Last updated <?php echo self::e( Format::date( substr( $last, 0, 10 ) ) ); ?></p>
			</div>
		</section>

		<section class="card">
			<h2>Your deliverables</h2>
			<?php if ( ! $dels ) : ?>
				<p class="muted">Your studio will list your photos, films and album here soon.</p>
			<?php else : ?>
				<ul class="dlist">
					<?php foreach ( $dels as $d ) : $p = 'delivered' === $d->status ? 100 : (int) $d->progress; ?>
						<li>
							<div class="drow">
								<div class="dname"><?php echo self::e( $d->name ); ?></div>
								<span class="pill <?php echo esc_attr( $tone[ $d->status ] ); ?>"><?php echo self::e( $st[ $d->status ] ); ?></span>
							</div>
							<div class="bar sm"><i style="width:<?php echo (int) $p; ?>%"></i></div>
							<div class="dmeta">
								<span><?php echo (int) $p; ?>% done</span>
								<?php if ( $d->expected_date && 'delivered' !== $d->status ) : ?><span>Expected <?php echo self::e( Format::date( $d->expected_date ) ); ?></span><?php endif; ?>
								<span>Updated <?php echo self::e( Format::date( substr( $d->updated_at, 0, 10 ) ) ); ?></span>
							</div>
							<?php if ( $d->delivery_link && in_array( $d->status, array( 'awaiting_selection', 'ready', 'delivered', 'under_review', 'revision_requested' ), true ) ) : ?>
								<a class="btn" href="<?php echo esc_url( $d->delivery_link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo 'awaiting_selection' === $d->status ? 'Open to select photos' : 'View / download'; ?> →</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<?php if ( $events ) : ?>
		<section class="card">
			<h2>Event details</h2>
			<ul class="events">
				<?php foreach ( $events as $ev ) : ?>
					<li>
						<div class="date"><b><?php echo self::e( date_i18n( 'd', strtotime( $ev->event_date ) ) ); ?></b><span><?php echo self::e( date_i18n( 'M Y', strtotime( $ev->event_date ) ) ); ?></span></div>
						<div><div class="dname"><?php echo self::e( $ev->name ); ?></div><div class="muted small"><?php echo self::e( trim( ( $ev->start_time ? Format::time( $ev->start_time ) . ' · ' : '' ) . trim( $ev->venue . ( $ev->city ? ', ' . $ev->city : '' ), ', ' ), ' ·' ) ); ?></div></div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php endif; ?>

		<section class="foot">
			<?php if ( $studio->phone ) : ?>
				<p>Questions? <a href="<?php echo esc_url( Whatsapp::link( $studio->phone, 'Hi ' . $studio->name . ', I have a question about my booking ' . $booking->number . '.' ) ); ?>" target="_blank" rel="noopener noreferrer">WhatsApp <?php echo self::e( $studio->name ); ?></a></p>
			<?php endif; ?>
			<form method="post"><button class="link" name="pcrm_logout" value="1" type="submit">Sign out of this portal</button></form>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function page( $title, $studio, $body, $brand = null, $wide = false ) {
		$name = $studio ? $studio->name : get_bloginfo( 'name' );
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo self::e( $title . ' · ' . $name ); ?></title>
<style>
:root{--brand:#F3724C;--brand-soft:#FDEDE7;--ink:#14161B;--muted:#667085;--line:#E7E9EE;--bg:#F6F7F9;--card:#fff}
@media (prefers-color-scheme:dark){:root{--brand-soft:#3a2219;--ink:#F2F4F7;--muted:#98A2B3;--line:#272B33;--bg:#0E1014;--card:#16191F}}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
.wrap{max-width:<?php echo $wide ? '720px' : '440px'; ?>;margin:0 auto;padding:20px 16px 40px}
header.top{display:flex;align-items:center;gap:12px;margin:4px 0 18px}
header.top img{height:44px;width:auto;max-width:140px;object-fit:contain;border-radius:8px}
header.top .mono{height:44px;width:44px;border-radius:12px;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px}
header.top .sn{font-weight:650;font-size:16px;line-height:1.2}header.top .tag{font-size:12px;color:var(--muted)}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;margin-bottom:14px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.center{text-align:center}h1{font-size:21px;line-height:1.25;margin:4px 0 6px;letter-spacing:-.01em}h2{font-size:16px;margin:0 0 12px}
.muted{color:var(--muted)}.small{font-size:13px}
.icon,.lock{width:52px;height:52px;border-radius:50%;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center;margin:4px auto 10px;font-weight:700}
form{margin:16px 0 8px}
input[type=password]{width:100%;font-size:24px;letter-spacing:.4em;text-align:center;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--bg);color:var(--ink);outline:none}
input[type=password]:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(243,114,76,.2)}
button{font:inherit;cursor:pointer}
form button[type=submit]:not(.link){width:100%;margin-top:12px;background:var(--brand);color:#fff;border:0;border-radius:12px;padding:13px;font-weight:600;font-size:15px}
.alert{background:#FEF3F2;color:#B42318;border:1px solid #FECDCA;border-radius:10px;padding:10px 12px;font-size:14px;margin:12px 0 0;text-align:left}
@media (prefers-color-scheme:dark){.alert{background:#3b1513;color:#FDA29B;border-color:#5c1f1b}}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.eyebrow{margin:0;color:var(--brand);font-weight:600;font-size:14px}
.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0 4px}
.meta div{background:var(--bg);border-radius:12px;padding:10px 12px;min-width:0}.meta span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}.meta b{display:block;font-size:14px;margin-top:2px;overflow-wrap:anywhere}
@media (max-width:520px){.meta{grid-template-columns:1fr 1fr}.meta div:last-child{grid-column:span 2}}
.progress-wrap{margin-top:16px}.progress-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;font-size:14px}.progress-head b{font-size:22px;color:var(--brand)}
.bar{height:10px;background:var(--line);border-radius:99px;overflow:hidden}.bar i{display:block;height:100%;background:linear-gradient(90deg,#F3724C,#F79A7A);border-radius:99px;transition:width .4s}
.bar.sm{height:6px;margin:10px 0 8px}
.pill{display:inline-block;font-size:12px;font-weight:600;border-radius:99px;padding:3px 10px;white-space:nowrap}
.pill.brand{background:var(--brand-soft);color:#C2410C}.pill.gray{background:#F2F4F7;color:#475467}.pill.blue{background:#EFF8FF;color:#175CD3}.pill.violet{background:#F4F3FF;color:#5925DC}.pill.amber{background:#FFFAEB;color:#B54708}.pill.teal{background:#F0FDF9;color:#107569}.pill.green{background:#ECFDF3;color:#067647}.pill.red{background:#FEF3F2;color:#B42318}
@media (prefers-color-scheme:dark){.pill.brand{color:#FDBA9F}.pill.gray{background:#252a33;color:#cbd5e1}.pill.blue{background:#102a4c;color:#84caff}.pill.violet{background:#2a1f55;color:#bdb4fe}.pill.amber{background:#3d2a0a;color:#fec84b}.pill.teal{background:#0b3530;color:#5fe9d0}.pill.green{background:#0b3a22;color:#75e0a7}.pill.red{background:#3b1513;color:#fda29b}}
.dlist{list-style:none;margin:0;padding:0}.dlist li{padding:14px 0;border-top:1px solid var(--line)}.dlist li:first-child{border-top:0;padding-top:2px}
.drow{display:flex;align-items:center;justify-content:space-between;gap:10px}.dname{font-weight:600}
.dmeta{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:12.5px;color:var(--muted)}
.btn{display:inline-block;margin-top:10px;background:var(--brand);color:#fff;text-decoration:none;font-weight:600;font-size:14px;border-radius:10px;padding:8px 14px}
.events{list-style:none;margin:0;padding:0}.events li{display:flex;gap:12px;align-items:center;padding:10px 0;border-top:1px solid var(--line)}.events li:first-child{border-top:0}
.events .date{width:52px;flex:none;text-align:center;background:var(--brand-soft);border-radius:12px;padding:6px 0}.events .date b{display:block;font-size:18px;color:var(--brand);line-height:1.1}.events .date span{font-size:11px;color:var(--muted)}
.foot{text-align:center;font-size:13px;color:var(--muted);margin-top:18px}.foot a{color:var(--brand);font-weight:600}
.link{background:none;border:0;color:var(--muted);text-decoration:underline;font-size:13px;padding:0;margin:0}
.powered{text-align:center;font-size:11px;color:var(--muted);margin-top:22px;opacity:.8}
</style>
</head>
<body>
<div class="wrap">
	<header class="top">
		<?php if ( $studio && $studio->logo_url ) : ?>
			<img src="<?php echo esc_url( $studio->logo_url ); ?>" alt="<?php echo esc_attr( $name ); ?>">
		<?php else : ?>
			<div class="mono" aria-hidden="true"><?php echo self::e( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ); ?></div>
		<?php endif; ?>
		<div><div class="sn"><?php echo self::e( $name ); ?></div><div class="tag">Client delivery portal</div></div>
	</header>
	<?php echo $body; // phpcs:ignore -- built from escaped parts above. ?>
	<p class="powered">Secured by ShootPilot</p>
</div>
</body>
</html>
		<?php
	}
}
