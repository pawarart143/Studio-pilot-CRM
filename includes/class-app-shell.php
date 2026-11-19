<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the single-page app shell at /crm/* (outside the theme).
 */
class App_Shell {

	private static function assets() {
		$manifest = PCRM_DIR . 'assets/app/.vite/manifest.json';
		if ( ! file_exists( $manifest ) ) {
			return null;
		}
		$m     = json_decode( file_get_contents( $manifest ), true ); // phpcs:ignore
		$entry = isset( $m['index.html'] ) ? $m['index.html'] : reset( $m );
		return array(
			'js'  => PCRM_URL . 'assets/app/' . $entry['file'],
			'css' => isset( $entry['css'] ) ? array_map(
				function ( $c ) {
					return PCRM_URL . 'assets/app/' . $c;
				},
				$entry['css']
			) : array(),
		);
	}

	public static function render() {
		nocache_headers();
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		$assets = self::assets();
		$boot   = array(
			'restUrl'  => esc_url_raw( rest_url( Plugin::REST_NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'base'     => wp_parse_url( home_url( '/crm' ), PHP_URL_PATH ),
			'homeUrl'  => home_url( '/' ),
			'loggedIn' => is_user_logged_in(),
			'version'  => PCRM_VERSION,
			'siteName' => get_bloginfo( 'name' ),
			'google'   => Google_Auth::enabled() ? Google_Auth::start_url() : '',
		);
		?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F3724C">
<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( PCRM_URL . 'assets/brand/icon.svg' ); ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( PCRM_URL . 'assets/brand/favicon-32.png' ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( PCRM_URL . 'assets/brand/apple-touch-icon.png' ); ?>">
<title>ShootPilot</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
(function(){try{var t=localStorage.getItem('pcrm-theme');var d=t?t==='dark':window.matchMedia('(prefers-color-scheme: dark)').matches;if(d)document.documentElement.classList.add('dark');}catch(e){}})();
window.PCRM = <?php echo wp_json_encode( $boot ); ?>;
</script>
<?php if ( $assets ) : ?>
	<?php foreach ( $assets['css'] as $css ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>">
	<?php endforeach; ?>
<script type="module" src="<?php echo esc_url( $assets['js'] ); ?>"></script>
<?php endif; ?>
</head>
<body>
<div id="root"></div>
<?php if ( ! $assets ) : ?>
<p style="font-family:sans-serif;padding:40px">The CRM app has not been built. Run <code>npm run build</code> in the plugin's <code>app</code> folder.</p>
<?php endif; ?>
<noscript>Please enable JavaScript to use ShootPilot.</noscript>
</body>
</html>
		<?php
	}
}
