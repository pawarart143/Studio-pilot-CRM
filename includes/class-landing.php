<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Public marketing homepage for ShootPilot.
 *
 * Served outside the theme (like the /crm app shell) at:
 *  - /shootpilot/           always available (preview / direct link)
 *  - the site front page    when "Use as homepage" is on (wp-admin → ShootPilot)
 *
 * Everything the page needs lives in includes/landing/ (PHP partials) and
 * assets/landing/ (one CSS + one JS file). No build step.
 */
class Landing {

	const OPTION = 'pcrm_landing';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'admin_post_pcrm_landing_save', array( __CLASS__, 'save_settings' ) );
	}

	public static function defaults() {
		return array(
			'front_page' => 1,
			'instagram'  => '',
			'facebook'   => '',
			'youtube'    => '',
			'contact'    => '',
			'terms'      => '',
			'about'      => '',
		);
	}

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	public static function maybe_render() {
		if ( get_query_var( 'pcrm_app' ) || get_query_var( 'pcrm_quote' ) ) {
			return;
		}
		$is_landing = (bool) get_query_var( 'pcrm_landing' );
		if ( ! $is_landing ) {
			$s = self::settings();
			// Only the bare front page: never paginated archives, searches or previews.
			if ( empty( $s['front_page'] ) || ! is_front_page() || is_paged() || is_search() || is_preview() || ! empty( $_GET ) ) { // phpcs:ignore
				return;
			}
		}
		self::render();
		exit;
	}

	/** Links used by CTAs. Point at the CRM's real routes. */
	public static function urls() {
		$logged = is_user_logged_in();
		return array(
			'home'     => home_url( '/' ),
			'register' => home_url( '/crm/register' ),
			'login'    => home_url( '/crm/login' ),
			'app'      => home_url( '/crm/' ),
			'primary'  => $logged ? home_url( '/crm/' ) : home_url( '/crm/register' ),
			'logged'   => $logged,
		);
	}

	/**
	 * Pricing cards. PLACEHOLDER pricing: amounts come from includes/class-plans.php
	 * and are shown with a "not final" note. Change them there (or with the
	 * pcrm_landing_pricing filter) once plans are finalised.
	 */
	public static function pricing() {
		$p     = Plans::all();
		$limit = function ( $n, $one, $many ) {
			return $n < 0 ? 'Unlimited ' . $many : sprintf( '%s %s', number_format_i18n( $n ), 1 === $n ? $one : $many );
		};
		$cards = array(
			array(
				'key'      => 'starter',
				'name'     => 'Starter',
				'for'      => 'For independent photographers.',
				'monthly'  => (int) $p['starter']['price'],
				'featured' => false,
				'items'    => array(
					$limit( $p['starter']['members'], 'team login', 'team logins' ),
					$limit( $p['starter']['leads_per_month'], 'new lead a month', 'new leads a month' ),
					'Leads, pipeline, calling & follow-ups',
					'Quotations with GST and PDF',
					'Bookings, calendar and tasks',
					'Payments, receipts and expenses',
					'Razorpay payment links (your own account)',
					'WhatsApp share with ready templates',
					'Reports and analytics',
				),
			),
			array(
				'key'      => 'professional',
				'name'     => 'Professional',
				'for'      => 'For growing photography studios.',
				'monthly'  => (int) $p['professional']['price'],
				'featured' => true,
				'items'    => array(
					$limit( $p['professional']['members'], 'team login', 'team logins' ),
					$limit( $p['professional']['leads_per_month'], 'lead', 'leads' ),
					'Everything in Starter',
					'WhatsApp Cloud API sending (your Meta account)',
				),
			),
			array(
				'key'      => 'enterprise',
				'name'     => 'Business',
				'for'      => 'For large studios and photography agencies.',
				'monthly'  => (int) $p['enterprise']['price'],
				'featured' => false,
				'items'    => array(
					$limit( $p['enterprise']['members'], 'team login', 'team logins' ),
					$limit( $p['enterprise']['leads_per_month'], 'lead', 'leads' ),
					'Everything in Professional',
					'Best for multi-team agencies',
				),
			),
		);
		return apply_filters( 'pcrm_landing_pricing', $cards );
	}

	/** Footer / resource links. Empty URL = page not live yet, shown as "Soon". */
	public static function footer() {
		$s       = self::settings();
		$privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		$cols    = array(
			'Product'   => array(
				array( 'Features', '#features' ),
				array( 'Pricing', '#pricing' ),
				array( 'Integrations', '#faq-whatsapp' ),
				array( 'Changelog', '' ),
			),
			'Solutions' => array(
				array( 'Wedding Photographers', '#solutions' ),
				array( 'Event Photographers', '#solutions' ),
				array( 'Photography Studios', '#solutions' ),
				array( 'Photography Agencies', '#solutions' ),
			),
			'Resources' => array(
				array( 'Blog', '' ),
				array( 'Help Center', '' ),
				array( 'Guides', '#how-it-works' ),
				array( 'Contact', $s['contact'] ? ( is_email( $s['contact'] ) ? 'mailto:' . $s['contact'] : $s['contact'] ) : '' ),
			),
			'Company'   => array(
				array( 'About Us', $s['about'] ),
				array( 'Privacy Policy', $privacy ),
				array( 'Terms & Conditions', $s['terms'] ),
			),
		);
		return apply_filters( 'pcrm_landing_footer', $cols );
	}

	public static function socials() {
		$s = self::settings();
		return array(
			'instagram' => array( 'Instagram', $s['instagram'] ),
			'facebook'  => array( 'Facebook', $s['facebook'] ),
			'youtube'   => array( 'YouTube', $s['youtube'] ),
		);
	}

	/** Inline Lucide-style icons (stroke, 24 grid). */
	public static function icon( $name, $class = '' ) {
		static $paths = array(
			'users'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
			'user'       => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
			'contact'    => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
			'columns'    => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/>',
			'message'    => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
			'briefcase'  => '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
			'file'       => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
			'card'       => '<rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/>',
			'rupee'      => '<path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/>',
			'calendar'   => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
			'checkbox'   => '<path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
			'chart'      => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
			'phone'      => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
			'check'      => '<path d="M20 6 9 17l-5-5"/>',
			'x'          => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
			'arrow'      => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
			'chevron'    => '<path d="m6 9 6 6 6-6"/>',
			'menu'       => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
			'sun'        => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
			'moon'       => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
			'search'     => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
			'bell'       => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
			'home'       => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
			'pin'        => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
			'clock'      => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
			'sparkles'   => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/>',
			'heart'      => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
			'camera'     => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
			'building'   => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
			'party'      => '<path d="M5.8 11.3 2 22l10.7-3.79"/><path d="M4 3h.01"/><path d="M22 8h.01"/><path d="M15 2h.01"/><path d="M22 20h.01"/><path d="m22 2-2.24.75a2.9 2.9 0 0 0-1.96 3.12c.1.86-.57 1.63-1.45 1.63h-.38c-.86 0-1.6.6-1.76 1.44L14 10"/><path d="M11 13c1.93 1.93 2.83 4.17 2 5-.83.83-3.07-.07-5-2-1.93-1.93-2.83-4.17-2-5 .83-.83 3.07.07 5 2Z"/>',
			'layers'     => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
			'send'       => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
			'inbox'      => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
			'shield'     => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
			'phone2'     => '<rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>',
			'sheet'      => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/><path d="M3 9h18"/><path d="M3 15h18"/>',
			'package'    => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
			'trend'      => '<path d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path d="M16 7h6v6"/>',
			'quote'      => '<path d="M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/><path d="M5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/>',
			'plus'       => '<path d="M5 12h14"/><path d="M12 5v14"/>',
			'receipt'    => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
			'image'      => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
			'video'      => '<path d="m16 13 5.22 3.48a.5.5 0 0 0 .78-.42V7.87a.5.5 0 0 0-.75-.43L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
			'instagram'  => '<rect width="20" height="20" x="2" y="2" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><path d="M17.5 6.5h.01"/>',
			'facebook'   => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
			'youtube'    => '<path d="M2.5 17a24.12 24.12 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.56 49.56 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24.12 24.12 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.55 49.55 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><path d="m10 15 5-3-5-3z"/>',
			'filter'     => '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>',
			'more'       => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
		);
		$inner = isset( $paths[ $name ] ) ? $paths[ $name ] : '';
		return '<svg class="ic ' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
	}

	/** The ShootPilot aperture mark (same geometry as assets/brand/icon.svg). */
	public static function logo( $class = 'logo-mark' ) {
		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 100 100" aria-hidden="true" focusable="false"><rect width="100" height="100" rx="24" fill="#F3724C"/><g fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"><circle cx="50" cy="50" r="27"/><path d="M50 61 23.36 45.62M40.47 55.5V24.74M40.47 44.5l26.65-15.38M50 39l26.64 15.38M59.53 44.5v30.76M59.53 55.5 32.88 70.88"/></g></svg>';
	}

	/** Section heading component. */
	public static function heading( $eyebrow, $title, $sub = '', $id = '' ) {
		echo '<div class="sec-head">';
		if ( $eyebrow ) {
			echo '<p class="eyebrow">' . esc_html( $eyebrow ) . '</p>';
		}
		echo '<h2' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $title ) . '</h2>';
		if ( $sub ) {
			echo '<p class="sec-sub">' . esc_html( $sub ) . '</p>';
		}
		echo '</div>';
	}

	/** Indian number format: 684500 -> 6,84,500 */
	public static function inr( $n ) {
		$n   = (string) (int) $n;
		$len = strlen( $n );
		if ( $len <= 3 ) {
			return $n;
		}
		$last = substr( $n, -3 );
		$rest = substr( $n, 0, -3 );
		$rest = preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', $rest );
		return $rest . ',' . $last;
	}

	public static function render() {
		status_header( 200 );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		$u   = self::urls();
		$ver = PCRM_VERSION;
		$css = PCRM_URL . 'assets/landing/landing.css';
		$js  = PCRM_URL . 'assets/landing/landing.js';
		require PCRM_DIR . 'includes/landing/page.php';
	}

	/* ---------- wp-admin settings (rendered on the ShootPilot admin page) ---------- */

	public static function settings_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::settings();
		echo '<hr><h2>Marketing homepage</h2>';
		if ( isset( $_GET['pcrm_saved'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success inline"><p>Homepage settings saved.</p></div>';
		}
		echo '<p>Preview: <a href="' . esc_url( home_url( '/shootpilot/' ) ) . '" target="_blank" rel="noopener">' . esc_html( home_url( '/shootpilot/' ) ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcrm_landing_save' );
		echo '<input type="hidden" name="action" value="pcrm_landing_save">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Use as site homepage</th><td><label><input type="checkbox" name="front_page" value="1" ' . checked( ! empty( $s['front_page'] ), true, false ) . '> Show the ShootPilot homepage at ' . esc_html( home_url( '/' ) ) . '</label><p class="description">Turn off to show your theme\'s homepage instead. The page stays available at /shootpilot/.</p></td></tr>';
		$fields = array(
			'contact'   => array( 'Contact email or URL', 'Shown as "Contact" in the footer.' ),
			'about'     => array( 'About Us page URL', '' ),
			'terms'     => array( 'Terms & Conditions URL', 'Privacy Policy uses Settings → Privacy.' ),
			'instagram' => array( 'Instagram URL', 'Social icons without a URL are shown greyed out as "coming soon".' ),
			'facebook'  => array( 'Facebook URL', '' ),
			'youtube'   => array( 'YouTube URL', '' ),
		);
		foreach ( $fields as $k => $f ) {
			echo '<tr><th scope="row"><label for="pcrm_' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td><input class="regular-text" id="pcrm_' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" value="' . esc_attr( $s[ $k ] ) . '">' . ( $f[1] ? '<p class="description">' . esc_html( $f[1] ) . '</p>' : '' ) . '</td></tr>';
		}
		echo '</table>';
		submit_button( 'Save homepage settings' );
		echo '</form>';
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'pcrm_landing_save' );
		$in  = wp_unslash( $_POST ); // phpcs:ignore
		$out = array( 'front_page' => empty( $in['front_page'] ) ? 0 : 1 );
		foreach ( array( 'about', 'terms', 'instagram', 'facebook', 'youtube' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? esc_url_raw( trim( $in[ $k ] ), array( 'http', 'https' ) ) : '';
		}
		$c              = isset( $in['contact'] ) ? trim( $in['contact'] ) : '';
		$out['contact'] = is_email( $c ) ? sanitize_email( $c ) : esc_url_raw( $c, array( 'http', 'https' ) );
		update_option( self::OPTION, $out, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'photographer-crm', 'pcrm_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
