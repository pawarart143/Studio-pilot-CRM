<?php
/**
 * ShootPilot marketing homepage. Rendered by PCRM\Landing::render().
 * Available vars: $u (urls), $ver, $css, $js.
 *
 * Sections are separate partials in includes/landing/sections/ so each one
 * can be edited (or reordered) on its own.
 */
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$title       = 'ShootPilot | CRM & Studio Management Software for Photographers';
$description = 'ShootPilot is the all-in-one CRM for photographers and studios: manage leads, follow-ups, quotations, bookings, payments, team and WhatsApp from one dashboard. Built for Indian photography businesses.';
$canonical   = get_query_var( 'pcrm_landing' ) ? home_url( '/shootpilot/' ) : home_url( '/' );
$og_image    = PCRM_URL . 'assets/brand/icon-512.png';
$sections    = array( 'hero', 'capabilities', 'problems', 'features', 'operations', 'showcase', 'how', 'solutions', 'pricing', 'testimonials', 'faq', 'cta' );
?><!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo esc_html( $title ); ?></title>
<meta name="description" content="<?php echo esc_attr( $description ); ?>">
<link rel="canonical" href="<?php echo esc_url( $canonical ); ?>">
<meta name="theme-color" content="#F3724C">
<meta property="og:type" content="website">
<meta property="og:site_name" content="ShootPilot">
<meta property="og:title" content="ShootPilot | Your Photography Business. On Autopilot.">
<meta property="og:description" content="<?php echo esc_attr( $description ); ?>">
<meta property="og:url" content="<?php echo esc_url( $canonical ); ?>">
<meta property="og:image" content="<?php echo esc_url( $og_image ); ?>">
<meta name="twitter:card" content="summary">
<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( PCRM_URL . 'assets/brand/icon.svg' ); ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( PCRM_URL . 'assets/brand/favicon-32.png' ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( PCRM_URL . 'assets/brand/apple-touch-icon.png' ); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script>
(function(){try{var t=localStorage.getItem('pcrm-theme');var d=t?t==='dark':window.matchMedia('(prefers-color-scheme: dark)').matches;if(d)document.documentElement.classList.add('dark');}catch(e){}document.documentElement.classList.add('js');})();
</script>
<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', $ver, $css ) ); ?>">
<script type="application/ld+json"><?php echo wp_json_encode( array(
	'@context'            => 'https://schema.org',
	'@type'               => 'SoftwareApplication',
	'name'                => 'ShootPilot',
	'applicationCategory' => 'BusinessApplication',
	'operatingSystem'     => 'Web',
	'description'         => $description,
	'url'                 => $canonical,
) ); ?></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>

<header class="nav" id="top" data-nav>
	<div class="wrap nav-in">
		<a class="brand" href="<?php echo esc_url( $u['home'] ); ?>" aria-label="ShootPilot home">
			<?php echo Landing::logo(); // phpcs:ignore ?>
			<span>ShootPilot</span>
		</a>
		<nav class="nav-links" aria-label="Main">
			<a href="#features">Features</a>
			<a href="#solutions">Solutions</a>
			<a href="#how-it-works">How It Works</a>
			<a href="#pricing">Pricing</a>
			<a href="#faq">Resources</a>
		</nav>
		<div class="nav-cta">
			<button class="icon-btn" type="button" data-theme-toggle aria-label="Switch light or dark theme">
				<?php echo Landing::icon( 'moon', 'only-light' ) . Landing::icon( 'sun', 'only-dark' ); // phpcs:ignore ?>
			</button>
			<?php if ( $u['logged'] ) : ?>
				<a class="btn btn-primary btn-sm" href="<?php echo esc_url( $u['app'] ); ?>">Open dashboard</a>
			<?php else : ?>
				<a class="link-login" href="<?php echo esc_url( $u['login'] ); ?>">Login</a>
				<a class="btn btn-primary btn-sm" href="<?php echo esc_url( $u['register'] ); ?>">Get Started Free</a>
			<?php endif; ?>
			<button class="icon-btn menu-btn" type="button" data-menu-toggle aria-expanded="false" aria-controls="mobile-menu" aria-label="Open menu">
				<?php echo Landing::icon( 'menu', 'i-open' ) . Landing::icon( 'x', 'i-close' ); // phpcs:ignore ?>
			</button>
		</div>
	</div>
	<div class="mobile-menu" id="mobile-menu" hidden>
		<nav class="wrap" aria-label="Mobile">
			<a href="#features">Features</a>
			<a href="#solutions">Solutions</a>
			<a href="#how-it-works">How It Works</a>
			<a href="#pricing">Pricing</a>
			<a href="#faq">Resources</a>
			<div class="mm-cta">
				<?php if ( $u['logged'] ) : ?>
					<a class="btn btn-primary" href="<?php echo esc_url( $u['app'] ); ?>">Open dashboard</a>
				<?php else : ?>
					<a class="btn btn-ghost" href="<?php echo esc_url( $u['login'] ); ?>">Login</a>
					<a class="btn btn-primary" href="<?php echo esc_url( $u['register'] ); ?>">Get Started Free</a>
				<?php endif; ?>
			</div>
		</nav>
	</div>
</header>

<main id="main">
<?php
foreach ( $sections as $section ) {
	require __DIR__ . '/sections/' . $section . '.php';
}
?>
</main>

<footer class="footer">
	<div class="wrap">
		<div class="foot-top">
			<div class="foot-brand">
				<a class="brand" href="<?php echo esc_url( $u['home'] ); ?>"><?php echo Landing::logo(); // phpcs:ignore ?><span>ShootPilot</span></a>
				<p>Made for Photographers. Built for Growth.</p>
				<?php $socials = Landing::socials(); ?>
				<div class="socials">
					<?php foreach ( $socials as $key => $sc ) : ?>
						<?php if ( $sc[1] ) : ?>
						<a href="<?php echo esc_url( $sc[1] ); ?>" target="_blank" rel="noopener" aria-label="ShootPilot on <?php echo esc_attr( $sc[0] ); ?>"><?php echo Landing::icon( $key ); // phpcs:ignore ?></a>
						<?php else : ?>
						<a class="off" role="link" aria-disabled="true" title="<?php echo esc_attr( $sc[0] ); ?>: coming soon" aria-label="<?php echo esc_attr( $sc[0] ); ?> (coming soon)"><?php echo Landing::icon( $key ); // phpcs:ignore ?></a>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php foreach ( Landing::footer() as $col => $links ) : ?>
			<div class="foot-col">
				<h3><?php echo esc_html( $col ); ?></h3>
				<ul>
					<?php foreach ( $links as $l ) : ?>
					<li>
						<?php if ( $l[1] ) : ?>
							<a href="<?php echo esc_url( $l[1] ); ?>"><?php echo esc_html( $l[0] ); ?></a>
						<?php else : ?>
							<span class="soon"><?php echo esc_html( $l[0] ); ?> <em>Soon</em></span>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="foot-bot">
			<p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> ShootPilot. All rights reserved.</p>
			<p>Prices in INR (₹). Made in India for photographers everywhere.</p>
		</div>
	</div>
</footer>

<script src="<?php echo esc_url( add_query_arg( 'ver', $ver, $js ) ); ?>" defer></script>
</body>
</html>
