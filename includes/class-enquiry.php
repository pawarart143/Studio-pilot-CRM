<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Public enquiry form: /enquire/{studio-slug}.
 *
 * Every submission is stored in pcrm_enquiry_submissions (the raw answers)
 * and goes straight into the studio's existing Leads (pcrm_leads), so it shows
 * in the leads table, pipeline, dashboard and lead source reports. There is no
 * separate enquiry database.
 *
 * Protection: honeypot field, signed time token (too fast / too old rejected),
 * per-IP and per-form rate limits, per-phone daily limit, one-time token (a
 * double click or refresh never creates a second lead), optional Cloudflare
 * Turnstile CAPTCHA, server-side validation of every field, and no private
 * CRM data is ever sent to the page.
 */
class Enquiry {

	const MIN_SECONDS = 3;

	/* =========================================================
	 * Form and fields
	 * ======================================================= */

	/**
	 * Default fields. Core fields map to lead columns and can't be deleted;
	 * "locked" ones can't be turned off or made optional.
	 */
	public static function default_fields() {
		$wed = Config::wedding_event_types();
		return array(
			array( 'full_name', 'text', 'Full name', 'client', 1, 1, 'Your full name' ),
			array( 'phone', 'phone', 'Mobile number', 'client', 1, 1, '98765 43210' ),
			array( 'whatsapp', 'phone', 'WhatsApp number', 'client', 1, 0, 'If different from mobile' ),
			array( 'email', 'email', 'Email address', 'client', 1, 1, 'you@example.com' ),
			array( 'event_type', 'select', 'Event type', 'event', 1, 1, 'Choose event type' ),
			array( 'day_type', 'radio', 'Is it a single-day or multi-day event?', 'event', 1, 1, '' ),
			array( 'event_date', 'date', 'Event date', 'event', 1, 1, '' ),
			array( 'event_end_date', 'date', 'Event end date', 'event', 1, 0, '' ),
			array( 'event_city', 'text', 'Event city', 'event', 1, 1, 'e.g. Indore' ),
			array( 'venue', 'text', 'Event venue', 'event', 1, 0, 'Hotel, banquet or home' ),
			array( 'address', 'textarea', 'Event address', 'event', 1, 0, '' ),
			array( 'couple_names', 'text', 'Bride & groom names', 'event', 0, 0, 'e.g. Priya & Rahul', $wed ),
			array( 'guest_count', 'select', 'Approximate guest count', 'event', 0, 0, '', array_merge( $wed, array( 'birthday', 'corporate' ) ), array( 'Up to 100', '100 – 300', '300 – 600', '600 – 1000', '1000+' ) ),
			array( 'services', 'multiselect', 'Which services do you need?', 'services', 1, 1, '' ),
			array( 'budget', 'select', 'Estimated budget', 'budget', 1, 0, 'Choose a range' ),
			array( 'heard_from', 'select', 'How did you hear about us?', 'additional', 1, 0, 'Choose one' ),
			array( 'requirements', 'textarea', 'Additional requirements', 'additional', 1, 0, 'Albums, number of photographers, special moments…' ),
			array( 'message', 'textarea', 'Message', 'additional', 1, 0, 'Anything else you would like us to know' ),
			array( 'consent', 'checkbox', 'I agree to be contacted regarding my enquiry.', 'additional', 1, 1, '' ),
		);
	}

	/** Fields that must stay on and required. */
	public static function locked_keys() {
		return array( 'full_name', 'phone', 'consent' );
	}

	public static function core_keys() {
		return wp_list_pluck( self::default_fields(), 0 );
	}

	public static function sections() {
		return array(
			'client'     => 'Client details',
			'event'      => 'Event details',
			'services'   => 'Photography services',
			'budget'     => 'Budget',
			'additional' => 'Additional information',
		);
	}

	public static function field_types() {
		return array(
			'text'        => 'Short answer',
			'textarea'    => 'Paragraph',
			'select'      => 'Dropdown',
			'radio'       => 'Single choice',
			'multiselect' => 'Multiple choice',
			'checkbox'    => 'Yes / tick box',
			'date'        => 'Date',
			'number'      => 'Number',
			'phone'       => 'Phone number',
			'email'       => 'Email',
		);
	}

	/**
	 * Creates the studio's form with default fields if it doesn't exist yet.
	 */
	public static function ensure_form( $studio_id ) {
		global $wpdb;
		$t   = Db::t( 'public_enquiry_forms' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE studio_id=%d", $studio_id ) );
		if ( $row ) {
			return $row;
		}
		$studio = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, slug, phone FROM ' . Db::t( 'studios' ) . ' WHERE id=%d', $studio_id ) );
		if ( ! $studio ) {
			return null;
		}
		$slug = self::unique_slug( $studio->slug ? $studio->slug : sanitize_title( $studio->name ), 0 );
		$now  = Db::now();
		$ok   = $wpdb->insert(
			$t,
			array(
				'studio_id'       => $studio_id,
				'slug'            => $slug,
				'is_enabled'      => 1,
				'title'           => 'Tell us about your celebration',
				'description'     => 'Share a few details and our team will get back to you with availability and packages.',
				'brand_color'     => '#F3724C',
				'success_title'   => 'Thank You for Your Enquiry!',
				'success_message' => "We've received your details. Our team will get in touch with you shortly to discuss your photography requirements.",
				'whatsapp_number' => $studio->phone,
				'consent_text'    => 'I agree to be contacted regarding my enquiry.',
				'assign_mode'     => 'none',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		if ( ! $ok ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE studio_id=%d", $studio_id ) );
		}
		$form_id = (int) $wpdb->insert_id;
		foreach ( self::default_fields() as $i => $f ) {
			$wpdb->insert(
				Db::t( 'enquiry_form_fields' ),
				array(
					'studio_id'   => $studio_id,
					'form_id'     => $form_id,
					'field_key'   => $f[0],
					'type'        => $f[1],
					'label'       => $f[2],
					'section'     => $f[3],
					'is_core'     => $f[4],
					'is_required' => $f[5],
					'placeholder' => $f[6] ? $f[6] : null,
					'show_for'    => ! empty( $f[7] ) ? wp_json_encode( array_values( $f[7] ) ) : null,
					'options'     => ! empty( $f[8] ) ? wp_json_encode( $f[8] ) : null,
					'is_enabled'  => 1,
					'sort'        => ( $i + 1 ) * 10,
					'created_at'  => $now,
					'updated_at'  => $now,
				)
			);
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $form_id ) );
	}

	public static function unique_slug( $base, $form_id ) {
		global $wpdb;
		$base = trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $base ) ), '-' );
		$base = $base ? substr( $base, 0, 80 ) : 'studio';
		$slug = $base;
		$i    = 2;
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'public_enquiry_forms' ) . ' WHERE slug=%s AND id<>%d', $slug, (int) $form_id ) ) ) {
			$slug = $base . '-' . $i++;
		}
		return $slug;
	}

	public static function url( $form ) {
		return $form ? home_url( '/enquire/' . $form->slug . '/' ) : '';
	}

	public static function fields( $form_id, $enabled_only = false ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'enquiry_form_fields' ) . ' WHERE form_id=%d' . ( $enabled_only ? ' AND is_enabled=1' : '' ) . ' ORDER BY sort, id', $form_id ) );
		foreach ( $rows as $r ) {
			$r->options  = $r->options ? json_decode( $r->options, true ) : array();
			$r->show_for = $r->show_for ? json_decode( $r->show_for, true ) : array();
			$r->locked   = in_array( $r->field_key, self::locked_keys(), true );
		}
		return Db::cast_all( $rows );
	}

	/** Options a field offers, built-in ones from the studio vocabulary. */
	public static function field_options( $f ) {
		switch ( $f->field_key ) {
			case 'event_type':
				return Config::event_types();
			case 'services':
				return Config::enquiry_services();
			case 'budget':
				return array_map(
					function ( $b ) {
						return $b[0];
					},
					Config::enquiry_budgets()
				);
			case 'heard_from':
				return Config::enquiry_heard_from();
			case 'day_type':
				return array( 'single' => 'Single day', 'multi' => 'Multiple days' );
		}
		$out = array();
		foreach ( (array) $f->options as $o ) {
			$o = (string) $o;
			if ( '' !== $o ) {
				$out[ $o ] = $o;
			}
		}
		return $out;
	}

	/* =========================================================
	 * Public page
	 * ======================================================= */

	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}

	private static function ip_hash() {
		return hash_hmac( 'sha256', self::ip(), wp_salt( 'nonce' ) );
	}

	/** Signed, single-use form token: form id, issue time and a random part. */
	public static function make_token( $form_id ) {
		$payload = (int) $form_id . '.' . time() . '.' . bin2hex( random_bytes( 12 ) );
		return $payload . '.' . substr( hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) . 'pcrm-enquiry' ), 0, 32 );
	}

	/** Returns the issue time, or a WP_Error. */
	public static function check_token( $token, $form_id ) {
		$p = explode( '.', (string) $token );
		if ( count( $p ) !== 4 ) {
			return new \WP_Error( 'bad_token', 'This form has expired. Please refresh the page and try again.', array( 'status' => 400 ) );
		}
		$payload = $p[0] . '.' . $p[1] . '.' . $p[2];
		if ( ! hash_equals( substr( hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) . 'pcrm-enquiry' ), 0, 32 ), $p[3] ) || (int) $p[0] !== (int) $form_id ) {
			return new \WP_Error( 'bad_token', 'This form has expired. Please refresh the page and try again.', array( 'status' => 400 ) );
		}
		$age = time() - (int) $p[1];
		if ( $age > DAY_IN_SECONDS ) {
			return new \WP_Error( 'bad_token', 'This form was open for too long. Please refresh the page and submit again.', array( 'status' => 400 ) );
		}
		return (int) $p[1];
	}

	private static function rate_hit( $key, $max, $ttl ) {
		$k = 'pcrm_eq_' . md5( $key );
		$n = (int) get_transient( $k );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $k, $n + 1, $ttl );
		return true;
	}

	public static function form_by_slug( $slug ) {
		global $wpdb;
		$slug = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $slug ) );
		return $slug ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'public_enquiry_forms' ) . ' WHERE slug=%s', $slug ) ) : null;
	}

	/** Logged-in owner/admin of this studio (for previewing a disabled form). */
	private static function can_preview( $form ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		global $wpdb;
		$role = $wpdb->get_var( $wpdb->prepare( 'SELECT role FROM ' . Db::t( 'members' ) . " WHERE studio_id=%d AND user_id=%d AND status='active'", $form->studio_id, get_current_user_id() ) );
		return $role && in_array( 'enquiry.manage', Permissions::for_role( $role ), true );
	}

	public static function render( $slug ) {
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'X-Content-Type-Options: nosniff' );
		$form   = self::form_by_slug( $slug );
		$studio = $form ? Tenant::load_studio( (int) $form->studio_id ) : null;
		$preview = $form && isset( $_GET['preview'] ) && self::can_preview( $form ); // phpcs:ignore
		if ( ! $form || ! $studio || 'active' !== $studio->status ) {
			status_header( 404 );
			self::shell( 'Form not found', null, null, '<div class="card center"><h1>Form not found</h1><p class="muted">This enquiry link is not valid. Please check the link and try again.</p></div>' );
			return;
		}
		if ( ! (int) $form->is_enabled && ! $preview ) {
			status_header( 410 );
			self::shell( 'Enquiries closed', $studio, $form, '<div class="card center"><h1>Enquiries are paused</h1><p class="muted">' . esc_html( $studio->name ) . ' is not taking online enquiries right now.' . ( $studio->phone ? ' Please call or WhatsApp ' . esc_html( Format::phone( $studio->phone ) ) . '.' : '' ) . '</p></div>' );
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' === $method ) {
			self::handle_post( $form, $studio, $preview );
			return;
		}
		self::page( $form, $studio, $preview );
	}

	private static function wa_link( $form, $studio, $name ) {
		$num = $form->whatsapp_number ? $form->whatsapp_number : '';
		return $num ? Whatsapp::link( $num, 'Hi ' . $studio->name . ', I just sent an enquiry on your website. My name is ' . $name . '.' ) : null;
	}

	/** Answers POSTed as JSON (the page script) or as a normal form (no JavaScript). */
	private static function handle_post( $form, $studio, $preview ) {
		$ctype = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) ) : '';
		$json  = false !== strpos( $ctype, 'application/json' );
		if ( $json ) {
			$raw   = file_get_contents( 'php://input', false, null, 0, 65536 );
			$input = json_decode( (string) $raw, true );
			$input = is_array( $input ) ? $input : array();
		} else {
			$input = wp_unslash( $_POST ); // phpcs:ignore
		}
		if ( $preview ) {
			$res = new \WP_Error( 'preview', 'This is a preview. Submissions are turned off here, so no lead was created.', array( 'status' => 400 ) );
		} else {
			$res = self::submit( $form, $studio, is_array( $input ) ? $input : array() );
		}
		if ( $json ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			if ( is_wp_error( $res ) ) {
				$d = $res->get_error_data();
				status_header( isset( $d['status'] ) ? (int) $d['status'] : 400 );
				echo wp_json_encode( array( 'ok' => false, 'code' => $res->get_error_code(), 'message' => $res->get_error_message(), 'errors' => isset( $d['errors'] ) ? $d['errors'] : (object) array() ) );
			} else {
				echo wp_json_encode( $res );
			}
			return;
		}
		if ( is_wp_error( $res ) ) {
			$d = $res->get_error_data();
			status_header( isset( $d['status'] ) ? (int) $d['status'] : 400 );
			$errs = isset( $d['errors'] ) ? (array) $d['errors'] : array();
			self::page( $form, $studio, $preview, $res->get_error_message(), $errs, $input );
			return;
		}
		self::shell( 'Thank you', $studio, $form, self::thanks_html( $form, $studio, $res ) );
	}

	private static function thanks_html( $form, $studio, $res ) {
		ob_start();
		?>
		<div class="card center thanks">
			<div class="tick" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>
			<h1><?php echo esc_html( $form->success_title ? $form->success_title : 'Thank You for Your Enquiry!' ); ?></h1>
			<p class="lead">Thank you, <?php echo esc_html( $res['name'] ); ?>.</p>
			<p class="muted"><?php echo esc_html( $form->success_message ); ?></p>
			<p class="ref">Enquiry reference <b><?php echo esc_html( $res['reference'] ); ?></b> · <?php echo esc_html( $res['submitted_at'] ); ?></p>
			<?php if ( ! empty( $res['whatsapp_link'] ) ) : ?>
				<a class="btn wa" href="<?php echo esc_url( $res['whatsapp_link'] ); ?>" target="_blank" rel="noopener">Chat with us on WhatsApp</a>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Validates the answers, applies spam and rate checks, and creates the lead.
	 * Returns the success payload or a WP_Error (with field errors).
	 */
	public static function submit( $form, $studio, array $in ) {
		global $wpdb;
		$sid = (int) $form->studio_id;
		// Spam traps first: a filled honeypot gets a fake success and no lead.
		if ( ! empty( $in['website_url'] ) ) {
			return array( 'ok' => true, 'name' => 'there', 'reference' => 'EQ-' . wp_rand( 100000, 999999 ), 'submitted_at' => Format::date( Db::today() ), 'whatsapp_link' => null );
		}
		$token = isset( $in['_token'] ) ? (string) $in['_token'] : '';
		$issued = self::check_token( $token, $form->id );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}
		$token_hash = hash( 'sha256', $token );
		// The same token again (double click, refresh, retry): return the first result.
		$prev = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, created_at FROM ' . Db::t( 'enquiry_submissions' ) . ' WHERE token_hash=%s AND studio_id=%d', $token_hash, $sid ) );
		if ( $prev ) {
			return array( 'ok' => true, 'duplicate_submit' => true, 'name' => $prev->name, 'reference' => 'EQ-' . str_pad( (string) $prev->id, 6, '0', STR_PAD_LEFT ), 'submitted_at' => Format::date( substr( $prev->created_at, 0, 10 ) ), 'whatsapp_link' => self::wa_link( $form, $studio, $prev->name ) );
		}
		if ( time() - $issued < self::MIN_SECONDS ) {
			return new \WP_Error( 'too_fast', 'That was quick! Please check your details and submit again.', array( 'status' => 429 ) );
		}
		$iph = self::ip_hash();
		if ( ! self::rate_hit( 'ip10m' . $form->id . $iph, 5, 10 * MINUTE_IN_SECONDS ) || ! self::rate_hit( 'ipday' . $form->id . $iph, 20, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'rate_limited', 'Too many enquiries from your network. Please try again later, or contact the studio on WhatsApp.', array( 'status' => 429 ) );
		}
		if ( ! self::rate_hit( 'form1h' . $form->id, 200, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'rate_limited', 'We are receiving a lot of enquiries right now. Please try again in a little while.', array( 'status' => 429 ) );
		}
		if ( $form->captcha_site_key && $form->captcha_secret ) {
			$ok = self::verify_turnstile( $form, isset( $in['cf-turnstile-response'] ) ? (string) $in['cf-turnstile-response'] : '' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}

		$v = self::validate( $form, $in );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		list( $vals, $answers ) = $v;

		if ( ! self::rate_hit( 'phone' . $sid . $vals['phone'], 3, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'rate_limited', 'We already have your enquiry from this number today. Our team will contact you soon.', array( 'status' => 429 ) );
		}

		$utm = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $k ) {
			$utm[ $k ] = isset( $in[ $k ] ) && '' !== trim( (string) $in[ $k ] ) ? mb_substr( sanitize_text_field( (string) $in[ $k ] ), 0, 190 ) : null;
		}
		$page_url = isset( $in['_page'] ) ? esc_url_raw( mb_substr( (string) $in['_page'], 0, 500 ) ) : '';
		$form_url = self::url( $form );
		if ( ! $page_url || 0 !== strpos( $page_url, untrailingslashit( $form_url ) ) ) {
			$page_url = $form_url;
		}
		$referrer = isset( $in['_ref'] ) ? esc_url_raw( mb_substr( (string) $in['_ref'], 0, 500 ) ) : '';

		$now = Db::date( 'Y-m-d H:i:s' );
		$sub_id = Db::insert(
			'enquiry_submissions',
			array(
				'form_id'      => (int) $form->id,
				'status'       => 'lead_created',
				'name'         => $vals['name'],
				'phone'        => $vals['phone'],
				'email'        => $vals['email'],
				'event_type'   => $vals['event_type'],
				'event_date'   => $vals['event_date'],
				'answers'      => wp_json_encode( $answers ),
				'form_url'     => $page_url,
				'referrer'     => $referrer ? $referrer : null,
				'utm_source'   => $utm['utm_source'],
				'utm_medium'   => $utm['utm_medium'],
				'utm_campaign' => $utm['utm_campaign'],
				'utm_term'     => $utm['utm_term'],
				'utm_content'  => $utm['utm_content'],
				'ip_hash'      => $iph,
				'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : null,
				'token_hash'   => $token_hash,
				'created_at'   => $now,
			),
			$sid
		);
		if ( is_wp_error( $sub_id ) ) {
			// Unique token: a parallel request saved it first.
			$prev = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, created_at FROM ' . Db::t( 'enquiry_submissions' ) . ' WHERE token_hash=%s', $token_hash ) );
			if ( $prev ) {
				return array( 'ok' => true, 'duplicate_submit' => true, 'name' => $prev->name, 'reference' => 'EQ-' . str_pad( (string) $prev->id, 6, '0', STR_PAD_LEFT ), 'submitted_at' => Format::date( substr( $prev->created_at, 0, 10 ) ), 'whatsapp_link' => self::wa_link( $form, $studio, $prev->name ) );
			}
			error_log( '[pcrm] enquiry save failed: ' . $sub_id->get_error_message() );
			return new \WP_Error( 'server_error', 'Sorry, we could not save your enquiry. Please try again or contact us on WhatsApp.', array( 'status' => 500 ) );
		}

		self::process( $sub_id, $sid );
		self::notify( $form, $studio, $sub_id );

		return array(
			'ok'            => true,
			'name'          => $vals['name'],
			'reference'     => 'EQ-' . str_pad( (string) $sub_id, 6, '0', STR_PAD_LEFT ),
			'submitted_at'  => Format::date( substr( $now, 0, 10 ) ),
			'whatsapp_link' => self::wa_link( $form, $studio, $vals['name'] ),
		);
	}

	private static function verify_turnstile( $form, $response ) {
		if ( '' === $response ) {
			return new \WP_Error( 'captcha', 'Please complete the security check.', array( 'status' => 422 ) );
		}
		$r = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $form->captcha_secret,
					'response' => $response,
					'remoteip' => self::ip(),
				),
			)
		);
		if ( is_wp_error( $r ) ) {
			return new \WP_Error( 'captcha', 'The security check could not be verified. Please try again.', array( 'status' => 503 ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $r ), true );
		return ! empty( $body['success'] ) ? true : new \WP_Error( 'captcha', 'The security check failed. Please try again.', array( 'status' => 422 ) );
	}

	/**
	 * Validates answers against the form's own field settings.
	 * Returns [lead values, labelled answers] or WP_Error with field errors.
	 */
	public static function validate( $form, array $in ) {
		$fields = self::fields( (int) $form->id, true );
		$errors = array();
		$ans    = array();
		$event  = isset( $in['event_type'] ) ? (string) $in['event_type'] : '';
		$multi  = isset( $in['day_type'] ) && 'multi' === $in['day_type'];
		$vals   = array( 'name' => '', 'phone' => null, 'whatsapp' => null, 'email' => null, 'event_type' => null, 'event_date' => null, 'event_end_date' => null, 'city' => null, 'venue' => null, 'address' => null, 'services' => array(), 'budget' => null, 'budget_label' => null, 'heard_from' => null, 'requirements' => null, 'message' => null, 'dates' => array(), 'day_type' => 'single' );
		foreach ( $fields as $f ) {
			$k = $f->field_key;
			if ( $f->show_for && $event && ! in_array( $event, $f->show_for, true ) ) {
				continue; // Hidden for this event type.
			}
			if ( 'event_end_date' === $k && ! $multi ) {
				continue;
			}
			$raw   = isset( $in[ $k ] ) ? $in[ $k ] : null;
			if ( is_string( $raw ) ) {
				$raw = trim( $raw );
			}
			$empty = null === $raw || '' === $raw || ( is_array( $raw ) && ! array_filter( $raw, 'strlen' ) ) || ( 'checkbox' === $f->type && ! filter_var( $raw, FILTER_VALIDATE_BOOLEAN ) );
			$req   = (int) $f->is_required || in_array( $k, self::locked_keys(), true ) || ( 'event_end_date' === $k && $multi );
			if ( $empty ) {
				if ( $req ) {
					$errors[ $k ] = 'consent' === $k ? 'Please tick this box so we can contact you.' : 'This field is required.';
				}
				continue;
			}
			$opts  = self::field_options( $f );
			$value = null;
			$label = null;
			switch ( $f->type ) {
				case 'text':
				case 'textarea':
					$value = 'textarea' === $f->type ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw );
					$max   = 'textarea' === $f->type ? 3000 : 190;
					if ( mb_strlen( $value ) > $max ) {
						$errors[ $k ] = "Please keep this under $max characters.";
					}
					if ( 'full_name' === $k && ( mb_strlen( $value ) < 2 || ! preg_match( '/\p{L}/u', $value ) ) ) {
						$errors[ $k ] = 'Please enter your name.';
					}
					break;
				case 'phone':
					$value = Validator::phone( (string) $raw );
					if ( ! $value ) {
						$errors[ $k ] = 'Enter a valid mobile number, like 98765 43210.';
					}
					break;
				case 'email':
					$value = sanitize_email( (string) $raw );
					if ( ! is_email( $value ) ) {
						$errors[ $k ] = 'Enter a valid email address.';
					}
					break;
				case 'date':
					$value = Validator::date( (string) $raw );
					if ( ! $value ) {
						$errors[ $k ] = 'Enter a valid date.';
					} elseif ( in_array( $k, array( 'event_date', 'event_end_date' ), true ) && $value < Db::today() ) {
						$errors[ $k ] = 'The date cannot be in the past.';
					} elseif ( $value > gmdate( 'Y-m-d', strtotime( '+6 years' ) ) ) {
						$errors[ $k ] = 'Please check the year.';
					}
					break;
				case 'number':
					if ( ! is_numeric( $raw ) || (float) $raw < 0 || (float) $raw > 1e9 ) {
						$errors[ $k ] = 'Enter a valid number.';
					} else {
						$value = 0 + $raw;
					}
					break;
				case 'select':
				case 'radio':
					$value = (string) $raw;
					if ( ! array_key_exists( $value, $opts ) ) {
						$errors[ $k ] = 'Choose one of the options.';
					} else {
						$label = $opts[ $value ];
					}
					break;
				case 'multiselect':
					$value = array_values( array_unique( array_map( 'strval', (array) $raw ) ) );
					$bad   = array_diff( $value, array_keys( $opts ) );
					if ( $bad || count( $value ) > 30 ) {
						$errors[ $k ] = 'Choose from the options shown.';
					} else {
						$label = implode( ', ', array_map( function ( $x ) use ( $opts ) {
							return $opts[ $x ];
						}, $value ) );
					}
					break;
				case 'checkbox':
					$value = true;
					$label = 'Yes';
					break;
			}
			if ( isset( $errors[ $k ] ) ) {
				continue;
			}
			$ans[ $k ] = array( 'label' => 'consent' === $k ? 'Consent to be contacted' : $f->label, 'value' => $value, 'display' => null !== $label ? $label : ( is_array( $value ) ? implode( ', ', $value ) : (string) $value ), 'section' => $f->section, 'core' => (bool) $f->is_core );
		}
		// Built-in extras: custom budget, multi-day dates.
		if ( isset( $ans['budget'] ) && 'custom' === $ans['budget']['value'] ) {
			$amt = isset( $in['budget_custom'] ) ? preg_replace( '/[^\d.]/', '', (string) $in['budget_custom'] ) : '';
			if ( '' === $amt || (float) $amt < 1000 || (float) $amt > 1e9 ) {
				$errors['budget_custom'] = 'Enter your budget in rupees (₹1,000 or more).';
			} else {
				$vals['budget']       = round( (float) $amt, 2 );
				$vals['budget_label'] = '₹' . Format::inr( $vals['budget'] );
				$ans['budget']['display'] = $vals['budget_label'] . ' (own budget)';
			}
		} elseif ( isset( $ans['budget'] ) ) {
			$b                    = Config::enquiry_budgets()[ $ans['budget']['value'] ];
			$vals['budget']       = $b[1];
			$vals['budget_label'] = $b[0];
		}
		if ( $multi ) {
			$vals['day_type'] = 'multi';
			$list             = isset( $in['event_dates'] ) && is_array( $in['event_dates'] ) ? array_slice( $in['event_dates'], 0, 15 ) : array();
			foreach ( $list as $i => $row ) {
				$d = is_array( $row ) ? ( isset( $row['date'] ) ? $row['date'] : '' ) : $row;
				$n = is_array( $row ) && isset( $row['label'] ) ? mb_substr( sanitize_text_field( (string) $row['label'] ), 0, 60 ) : '';
				if ( '' === trim( (string) $d ) ) {
					continue;
				}
				$dv = Validator::date( (string) $d );
				if ( ! $dv || $dv < Db::today() ) {
					$errors['event_dates'] = 'Check the event dates: each must be a valid, future date.';
					break;
				}
				$vals['dates'][] = array( 'date' => $dv, 'label' => $n );
			}
			usort( $vals['dates'], function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			} );
			if ( $vals['dates'] ) {
				$ans['event_dates'] = array(
					'label'   => 'Event dates',
					'value'   => $vals['dates'],
					'display' => implode( ', ', array_map( function ( $d ) {
						return ( $d['label'] ? $d['label'] . ': ' : '' ) . Format::date( $d['date'] );
					}, $vals['dates'] ) ),
					'section' => 'event',
					'core'    => true,
				);
			}
		}
		if ( isset( $ans['event_date'], $ans['event_end_date'] ) && $ans['event_end_date']['value'] < $ans['event_date']['value'] ) {
			$errors['event_end_date'] = 'The end date must be on or after the event date.';
		}
		if ( $errors ) {
			return new \WP_Error( 'validation_failed', 'Please check the highlighted fields.', array( 'status' => 422, 'errors' => $errors ) );
		}
		$get = function ( $k ) use ( $ans ) {
			return isset( $ans[ $k ] ) ? $ans[ $k ]['value'] : null;
		};
		$vals['name']           = $get( 'full_name' );
		$vals['phone']          = $get( 'phone' );
		$vals['whatsapp']       = $get( 'whatsapp' ) ? $get( 'whatsapp' ) : $vals['phone'];
		$vals['email']          = $get( 'email' );
		$vals['event_type']     = $get( 'event_type' );
		$vals['event_date']     = $get( 'event_date' );
		$vals['event_end_date'] = $get( 'event_end_date' );
		if ( $vals['dates'] ) {
			$first = $vals['dates'][0]['date'];
			$last  = end( $vals['dates'] )['date'];
			if ( ! $vals['event_date'] || $first < $vals['event_date'] ) {
				$vals['event_date'] = $first;
			}
			if ( ! $vals['event_end_date'] || $last > $vals['event_end_date'] ) {
				$vals['event_end_date'] = $last;
			}
		}
		$vals['city']         = $get( 'event_city' );
		$vals['venue']        = $get( 'venue' );
		$vals['address']      = $get( 'address' );
		$vals['services']     = (array) $get( 'services' );
		$vals['heard_from']   = isset( $ans['heard_from'] ) ? $ans['heard_from']['display'] : null;
		$vals['requirements'] = $get( 'requirements' );
		$vals['message']      = $get( 'message' );
		return array( $vals, array( 'fields' => $ans, 'values' => $vals ) );
	}

	/**
	 * Turns a stored submission into a lead, or flags it as a possible
	 * duplicate of an open lead with the same phone number (never overwriting it).
	 */
	public static function process( $sub_id, $sid ) {
		global $wpdb;
		$sub = Db::find( 'enquiry_submissions', $sub_id, $sid );
		if ( ! $sub ) {
			return;
		}
		$data = json_decode( $sub->answers, true );
		$vals = $data['values'];
		// Duplicate check: same phone or WhatsApp in this studio.
		$phones = array_filter( array_unique( array( $vals['phone'], $vals['whatsapp'] ) ) );
		$in     = "'" . implode( "','", array_map( 'esc_sql', $phones ) ) . "'";
		$match  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, stage FROM ' . Db::t( 'leads' ) . " WHERE studio_id=%d AND (phone IN ($in) OR whatsapp IN ($in)) ORDER BY (stage NOT IN ('booked','lost')) DESC, id DESC LIMIT 5", $sid ) );
		$open   = null;
		foreach ( $match as $m ) {
			if ( ! in_array( $m->stage, array( 'booked', 'lost' ), true ) ) {
				$open = $m;
				break;
			}
		}
		if ( $open ) {
			Db::update( 'enquiry_submissions', $sub_id, array( 'status' => 'duplicate', 'duplicate_lead_id' => (int) $open->id ), $sid );
			Db::log( 'enquiry', 'New enquiry form submission from the same phone number (EQ-' . str_pad( (string) $sub_id, 6, '0', STR_PAD_LEFT ) . '). Not merged automatically: review it in Settings → Enquiry form → Submissions.', array( 'lead_id' => (int) $open->id ), array( 'submission_id' => (int) $sub_id ), $sid );
			return;
		}
		$studio = Tenant::load_studio( $sid );
		$limit  = Plans::check( $studio, 'leads' );
		if ( is_wp_error( $limit ) ) {
			Db::update( 'enquiry_submissions', $sub_id, array( 'status' => 'on_hold' ), $sid );
			return;
		}
		$lead_id = self::create_lead( $sub, $sid, $match ? $match[0] : null );
		if ( ! is_wp_error( $lead_id ) ) {
			Db::update( 'enquiry_submissions', $sub_id, array( 'status' => 'lead_created', 'lead_id' => $lead_id ), $sid );
		} else {
			Db::update( 'enquiry_submissions', $sub_id, array( 'status' => 'on_hold' ), $sid );
			error_log( '[pcrm] enquiry lead failed: ' . $lead_id->get_error_message() );
		}
	}

	/** Builds the lead notes from everything that has no lead column. */
	public static function notes_for( array $data ) {
		$v     = $data['values'];
		$lines = array( 'Enquiry form submission' );
		if ( ! empty( $v['dates'] ) ) {
			$lines[] = 'Event dates: ' . $data['fields']['event_dates']['display'];
		}
		if ( ! empty( $v['address'] ) ) {
			$lines[] = 'Address: ' . $v['address'];
		}
		if ( ! empty( $v['services'] ) ) {
			$lines[] = 'Services: ' . $data['fields']['services']['display'];
		}
		if ( ! empty( $v['budget_label'] ) ) {
			$lines[] = 'Budget: ' . $v['budget_label'];
		}
		if ( ! empty( $v['heard_from'] ) ) {
			$lines[] = 'Heard about us: ' . $v['heard_from'];
		}
		$skip = array( 'full_name', 'phone', 'whatsapp', 'email', 'event_type', 'day_type', 'event_date', 'event_end_date', 'event_city', 'venue', 'address', 'services', 'budget', 'heard_from', 'requirements', 'message', 'consent', 'event_dates' );
		foreach ( $data['fields'] as $k => $a ) {
			if ( ! in_array( $k, $skip, true ) && '' !== (string) $a['display'] ) {
				$lines[] = $a['label'] . ': ' . $a['display'];
			}
		}
		if ( ! empty( $v['requirements'] ) ) {
			$lines[] = "Additional requirements:\n" . $v['requirements'];
		}
		if ( ! empty( $v['message'] ) ) {
			$lines[] = "Message:\n" . $v['message'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Picks the lead owner from the form's lead assignment settings.
	 */
	public static function pick_assignee( $sid ) {
		global $wpdb;
		$form = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'public_enquiry_forms' ) . ' WHERE studio_id=%d', $sid ) );
		if ( ! $form ) {
			return null;
		}
		$active = function ( $id ) use ( $wpdb, $sid ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'members' ) . " WHERE id=%d AND studio_id=%d AND status='active'", $id, $sid ) );
		};
		if ( 'fixed' === $form->assign_mode && $form->assign_member_id && $active( (int) $form->assign_member_id ) ) {
			return (int) $form->assign_member_id;
		}
		if ( 'round_robin' === $form->assign_mode ) {
			$pool = array_values( array_filter( array_map( 'intval', (array) json_decode( (string) $form->assign_pool, true ) ), $active ) );
			if ( ! $pool ) {
				return null;
			}
			$lock = 'pcrm_rr_' . $sid;
			$wpdb->query( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
			$last = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT assign_last_member_id FROM ' . Db::t( 'public_enquiry_forms' ) . ' WHERE id=%d', $form->id ) );
			$pos  = array_search( $last, $pool, true );
			$next = $pool[ false === $pos ? 0 : ( $pos + 1 ) % count( $pool ) ];
			$wpdb->update( Db::t( 'public_enquiry_forms' ), array( 'assign_last_member_id' => $next ), array( 'id' => $form->id ) );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			return $next;
		}
		return null;
	}

	public static function create_lead( $sub, $sid, $returning = null ) {
		$data = json_decode( $sub->answers, true );
		$v    = $data['values'];
		$days = null;
		if ( $v['event_date'] && $v['event_end_date'] && $v['event_end_date'] > $v['event_date'] ) {
			$n    = (int) round( ( strtotime( $v['event_end_date'] ) - strtotime( $v['event_date'] ) ) / DAY_IN_SECONDS ) + 1;
			$days = $n . ' days';
		} elseif ( $v['event_date'] ) {
			$days = '1 day';
		}
		$notes = self::notes_for( $data );
		if ( $returning ) {
			$notes .= "\n\nReturning contact: same phone as lead #" . (int) $returning->id . ' (' . $returning->name . ').';
		}
		$lead = array(
			'name'                  => $v['name'],
			'phone'                 => $v['phone'],
			'whatsapp'              => $v['whatsapp'],
			'email'                 => $v['email'],
			'event_type'            => $v['event_type'],
			'event_date'            => $v['event_date'],
			'event_end_date'        => $v['event_end_date'],
			'event_duration'        => $days,
			'venue'                 => $v['venue'] ? mb_substr( $v['venue'], 0, 255 ) : null,
			'city'                  => $v['city'] ? mb_substr( $v['city'], 0, 100 ) : null,
			'source'                => 'enquiry_form',
			'assigned_to'           => self::pick_assignee( $sid ),
			'budget'                => $v['budget'],
			'stage'                 => 'new',
			'priority'              => 'medium',
			'call_status'           => 'not_called',
			'notes'                 => $notes,
			'services'              => $v['services'] ? wp_json_encode( array_values( $v['services'] ) ) : null,
			'enquiry_submission_id' => (int) $sub->id,
			'form_url'              => $sub->form_url,
			'utm_source'            => $sub->utm_source,
			'utm_medium'            => $sub->utm_medium,
			'utm_campaign'          => $sub->utm_campaign,
			'created_at'            => $sub->created_at,
		);
		return Rest\Leads::insert_lead( $lead, $sid );
	}

	/** Emails the studio about a new enquiry (best effort; needs working mail). */
	private static function notify( $form, $studio, $sub_id ) {
		$sub = Db::find( 'enquiry_submissions', $sub_id, (int) $studio->id );
		if ( ! $sub || ! $studio->email || ! is_email( $studio->email ) ) {
			return;
		}
		$link  = home_url( '/crm/' . ( $sub->lead_id ? 'leads/' . $sub->lead_id : 'settings?tab=enquiry' ) );
		$lines = array(
			'New enquiry from ' . $sub->name . ' (' . Format::phone( $sub->phone ) . ')',
			$sub->event_type ? 'Event: ' . Config::event_types()[ $sub->event_type ] . ( $sub->event_date ? ' on ' . Format::date( $sub->event_date ) : '' ) : '',
			'duplicate' === $sub->status ? 'This phone number already has an open lead. Please review it.' : '',
			'Open in ShootPilot: ' . $link,
		);
		wp_mail( $studio->email, 'New enquiry: ' . $sub->name, implode( "\n", array_filter( $lines ) ) );
	}

	/* =========================================================
	 * HTML
	 * ======================================================= */

	/** Public form config: only the form's own public settings, never CRM data. */
	private static function public_config( $form, $studio, $preview ) {
		$fields = array();
		foreach ( self::fields( (int) $form->id, true ) as $f ) {
			$opts = self::field_options( $f );
			$fields[] = array(
				'key'         => $f->field_key,
				'type'        => $f->type,
				'label'       => 'consent' === $f->field_key && $form->consent_text ? $form->consent_text : $f->label,
				'placeholder' => (string) $f->placeholder,
				'help'        => (string) $f->help,
				'required'    => (bool) $f->is_required || in_array( $f->field_key, self::locked_keys(), true ),
				'section'     => $f->section,
				'options'     => array_map( null, array_keys( $opts ), array_values( $opts ) ),
				'show_for'    => $f->show_for,
			);
		}
		return array(
			'fields'           => $fields,
			'sections'         => self::sections(),
			'wedding_types'    => Config::wedding_event_types(),
			'wedding_services' => Config::enquiry_wedding_services(),
			'token'            => self::make_token( $form->id ),
			'preview'          => (bool) $preview,
			'action'           => self::url( $form ),
			'today'            => Db::today(),
			'brand'            => $form->brand_color,
			'studio'           => $studio->name,
			'logo'             => $form->logo_url ? $form->logo_url : $studio->logo_url,
			'success_title'    => $form->success_title ? $form->success_title : 'Thank You for Your Enquiry!',
			'success_message'  => (string) $form->success_message,
			'captcha'          => $form->captcha_site_key && $form->captcha_secret ? $form->captcha_site_key : '',
		);
	}

	private static function page( $form, $studio, $preview, $error = '', array $errors = array(), array $old = array() ) {
		$cfg = self::public_config( $form, $studio, $preview );
		$old += array( 'day_type' => 'single' );
		ob_start();
		?>
		<div class="hero">
			<h1><?php echo esc_html( $form->title ); ?></h1>
			<?php if ( $form->description ) : ?><p class="muted"><?php echo nl2br( esc_html( $form->description ) ); ?></p><?php endif; ?>
		</div>
		<?php if ( $preview ) : ?><div class="note">Preview<?php echo (int) $form->is_enabled ? '' : ' (the form is turned off for visitors)'; ?>. Submissions are disabled here.</div><?php endif; ?>
		<div class="card" id="pcrm-enquiry">
			<?php if ( $error ) : ?><div class="alert" role="alert"><?php echo esc_html( $error ); ?></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( self::url( $form ) . ( $preview ? '?preview=1' : '' ) ); ?>" novalidate class="nojs">
				<input type="hidden" name="_token" value="<?php echo esc_attr( $cfg['token'] ); ?>">
				<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
				<?php
				foreach ( $cfg['fields'] as $f ) {
					echo self::field_html( $f, isset( $old[ $f['key'] ] ) ? $old[ $f['key'] ] : null, isset( $errors[ $f['key'] ] ) ? $errors[ $f['key'] ] : '' ); // phpcs:ignore
					if ( 'budget' === $f['key'] ) {
						echo '<div class="field" data-key="budget_custom"><label for="f-budget_custom">Your budget (₹)</label><input id="f-budget_custom" name="budget_custom" inputmode="numeric" placeholder="e.g. 250000" value="' . esc_attr( isset( $old['budget_custom'] ) ? $old['budget_custom'] : '' ) . '">' . ( isset( $errors['budget_custom'] ) ? '<p class="err">' . esc_html( $errors['budget_custom'] ) . '</p>' : '' ) . '</div>';
					}
				}
				?>
				<button type="submit" class="btn primary">Send enquiry</button>
			</form>
		</div>
		<script type="application/json" id="pcrm-enquiry-config"><?php echo wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
		<?php
		self::shell( $form->title, $studio, $form, ob_get_clean(), true );
	}

	/** Plain HTML field (used without JavaScript; the script rebuilds the form). */
	private static function field_html( $f, $old, $err ) {
		$id   = 'f-' . $f['key'];
		$req  = $f['required'] ? ' <span class="req">*</span>' : '';
		$out  = '<div class="field" data-key="' . esc_attr( $f['key'] ) . '">';
		$name = esc_attr( $f['key'] );
		switch ( $f['type'] ) {
			case 'textarea':
				$out .= '<label for="' . $id . '">' . esc_html( $f['label'] ) . $req . '</label><textarea id="' . $id . '" name="' . $name . '" rows="3" placeholder="' . esc_attr( $f['placeholder'] ) . '">' . esc_textarea( (string) $old ) . '</textarea>';
				break;
			case 'select':
				$out .= '<label for="' . $id . '">' . esc_html( $f['label'] ) . $req . '</label><select id="' . $id . '" name="' . $name . '"><option value="">' . esc_html( $f['placeholder'] ? $f['placeholder'] : 'Choose…' ) . '</option>';
				foreach ( $f['options'] as $o ) {
					$out .= '<option value="' . esc_attr( $o[0] ) . '"' . selected( (string) $old, (string) $o[0], false ) . '>' . esc_html( $o[1] ) . '</option>';
				}
				$out .= '</select>';
				break;
			case 'radio':
			case 'multiselect':
				$multi = 'multiselect' === $f['type'];
				$out  .= '<fieldset><legend>' . esc_html( $f['label'] ) . $req . '</legend><div class="opts">';
				foreach ( $f['options'] as $o ) {
					$chk  = $multi ? in_array( (string) $o[0], (array) $old, true ) : (string) $old === (string) $o[0];
					$out .= '<label class="opt"><input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" name="' . $name . ( $multi ? '[]' : '' ) . '" value="' . esc_attr( $o[0] ) . '"' . ( $chk ? ' checked' : '' ) . '> <span>' . esc_html( $o[1] ) . '</span></label>';
				}
				$out .= '</div></fieldset>';
				break;
			case 'checkbox':
				$out .= '<label class="opt check"><input type="checkbox" name="' . $name . '" value="1"' . ( $old ? ' checked' : '' ) . '> <span>' . esc_html( $f['label'] ) . $req . '</span></label>';
				break;
			default:
				$type = array( 'phone' => 'tel', 'email' => 'email', 'date' => 'date', 'number' => 'number' );
				$t    = isset( $type[ $f['type'] ] ) ? $type[ $f['type'] ] : 'text';
				$out .= '<label for="' . $id . '">' . esc_html( $f['label'] ) . $req . '</label><input id="' . $id . '" type="' . $t . '" name="' . $name . '" placeholder="' . esc_attr( $f['placeholder'] ) . '" value="' . esc_attr( is_scalar( $old ) ? (string) $old : '' ) . '">';
		}
		if ( $err ) {
			$out .= '<p class="err">' . esc_html( $err ) . '</p>';
		}
		return $out . '</div>';
	}

	private static function shell( $title, $studio, $form, $body, $script = false ) {
		$brand = $form && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $form->brand_color ) ? $form->brand_color : '#F3724C';
		$name  = $studio ? $studio->name : get_bloginfo( 'name' );
		$logo  = $form && $form->logo_url ? $form->logo_url : ( $studio ? $studio->logo_url : '' );
		$ver   = PCRM_VERSION;
		$csp   = "default-src 'self'; img-src * data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors *";
		header( 'Content-Security-Policy: ' . $csp );
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, follow">
<meta name="theme-color" content="<?php echo esc_attr( $brand ); ?>">
<title><?php echo esc_html( $title . ' · ' . $name ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url( PCRM_URL . 'assets/enquiry/enquiry.css?v=' . $ver ); ?>">
<style>:root{--brand:<?php echo esc_html( $brand ); ?>}</style>
</head>
<body>
<div class="wrap">
	<header class="top">
		<?php if ( $logo ) : ?>
			<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>" class="logo">
		<?php else : ?>
			<div class="mono" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ); ?></div>
		<?php endif; ?>
		<div class="sn"><?php echo esc_html( $name ); ?></div>
	</header>
	<main><?php echo $body; // phpcs:ignore -- escaped above. ?></main>
	<p class="powered">Powered by ShootPilot</p>
</div>
<?php if ( $script ) : ?>
<script src="<?php echo esc_url( PCRM_URL . 'assets/enquiry/enquiry.js?v=' . $ver ); ?>" defer></script>
<?php endif; ?>
</body>
</html>
		<?php
	}
}
