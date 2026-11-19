<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;
?>
<section class="final" aria-labelledby="cta-title">
	<div class="wrap">
		<div class="final-card">
			<div class="final-glow" aria-hidden="true"></div>
			<?php echo Landing::logo( 'final-logo' ); // phpcs:ignore ?>
			<h2 id="cta-title">Ready to Take Control of Your Photography Business?</h2>
			<p>Join the smarter way to manage your photography studio. Spend less time on operations and more time creating unforgettable memories.</p>
			<div class="hero-cta">
				<a class="btn btn-primary btn-lg" href="<?php echo esc_url( $u['primary'] ); ?>"><?php echo $u['logged'] ? 'Open dashboard' : 'Start Free Today'; ?> <?php echo Landing::icon( 'arrow' ); // phpcs:ignore ?></a>
				<a class="btn btn-onDark btn-lg" href="#product">Explore ShootPilot</a>
			</div>
			<p class="final-note">15-day free trial · No card required · Your data stays in your studio</p>
		</div>
	</div>
</section>
