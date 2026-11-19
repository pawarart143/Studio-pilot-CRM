<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$who = array(
	array( 'heart', 'Wedding Photography Studios', 'Plan multi-day weddings with Mehndi, Haldi, Wedding and Reception under one booking, each with its own venue, crew and payment schedule.' ),
	array( 'camera', 'Pre-Wedding Photographers', 'Turn Instagram enquiries into booked shoots fast, with quick quotations, location details and reminders before travel.' ),
	array( 'party', 'Event Photography Businesses', 'Handle birthdays, corporate events and parties at volume, with a daily call list and a calendar that stops double bookings.' ),
	array( 'building', 'Commercial Photography Studios', 'Quote product, fashion and brand shoots with GST-ready PDFs and track every installment against the project.' ),
	array( 'user', 'Freelance Photographers', 'Start on the Free plan and replace your notebook and spreadsheet with one simple dashboard on your phone.' ),
	array( 'layers', 'Photography Agencies', 'Run several teams with role-based access, so sales, crew and editors each see exactly what they need.' ),
);
?>
<section class="section solutions" id="solutions" aria-labelledby="who-title">
	<div class="wrap">
		<?php Landing::heading( 'Who it is for', 'Made for every kind of photography business.', 'Whether you shoot weddings every weekend or run a multi-team agency, ShootPilot fits the way you work.', 'who-title' ); ?>
		<div class="who-grid">
			<?php foreach ( $who as $w ) : ?>
			<article class="who">
				<span class="fc-ic"><?php echo Landing::icon( $w[0] ); // phpcs:ignore ?></span>
				<h3><?php echo esc_html( $w[1] ); ?></h3>
				<p><?php echo esc_html( $w[2] ); ?></p>
			</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
