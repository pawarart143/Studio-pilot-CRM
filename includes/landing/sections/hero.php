<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;
?>
<section class="hero" aria-labelledby="hero-title">
	<div class="hero-glow" aria-hidden="true"></div>
	<div class="wrap hero-grid">
		<div class="hero-copy">
			<p class="badge"><span class="dot" aria-hidden="true"></span>The all-in-one CRM built for photographers</p>
			<h1 id="hero-title">Run Your Photography Business <span class="hl">Like a Pro.</span></h1>
			<p class="lead">From the first enquiry to final photo delivery, manage your entire photography business in one powerful workspace. Less admin, better client experiences, more time behind the camera.</p>
			<div class="hero-cta">
				<a class="btn btn-primary btn-lg" href="<?php echo esc_url( $u['primary'] ); ?>"><?php echo $u['logged'] ? 'Open dashboard' : 'Start Free'; ?> <?php echo Landing::icon( 'arrow' ); // phpcs:ignore ?></a>
				<a class="btn btn-ghost btn-lg" href="#features">Explore Features</a>
			</div>
			<p class="trust"><?php echo Landing::icon( 'shield' ); // phpcs:ignore ?> Built for photographers. Designed for growth.</p>
		</div>

		<div class="hero-visual">
			<?php require __DIR__ . '/../mock/dashboard.php'; ?>

			<div class="float f-follow" aria-hidden="true">
				<div class="f-head"><span class="f-ic"><?php echo Landing::icon( 'phone' ); // phpcs:ignore ?></span><div><b>Today's follow-ups</b><small>4 due · 1 overdue</small></div></div>
				<ul>
					<li><span class="av av-1">RK</span><div><b>Riya &amp; Karan</b><small>Wedding · Udaipur</small></div><span class="pill pill-red">Overdue</span></li>
					<li><span class="av av-2">AS</span><div><b>Ananya S.</b><small>Pre-wedding · Goa</small></div><span class="pill">11:30</span></li>
					<li><span class="av av-3">MJ</span><div><b>Mehta Jewellers</b><small>Product shoot</small></div><span class="pill">15:00</span></li>
				</ul>
			</div>

			<div class="float f-pay" aria-hidden="true">
				<span class="f-ic f-ic-green"><?php echo Landing::icon( 'check' ); // phpcs:ignore ?></span>
				<div><b>Advance received</b><small>₹50,000 · Sharma–Mehta wedding</small></div>
			</div>
		</div>
	</div>
</section>
