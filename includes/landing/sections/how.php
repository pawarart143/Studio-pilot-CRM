<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$steps = array(
	array( 'inbox', 'Capture Leads', 'Bring all your enquiries into one organized CRM, from Instagram, your website, referrals or an Excel import.' ),
	array( 'trend', 'Convert Clients', 'Follow up on time, share quotations on WhatsApp and confirm bookings with an advance.' ),
	array( 'briefcase', 'Manage Projects', 'Organize shoots, teams, events and payments for every booking, across every date and venue.' ),
	array( 'package', 'Deliver & Grow', 'Track editing, album design and delivery, then see what is working in your reports.' ),
);
?>
<section class="section how soft" id="how-it-works" aria-labelledby="how-title">
	<div class="wrap">
		<?php Landing::heading( 'How it works', 'From Enquiry to Delivery. Made Simple.', 'One flow for your whole team, matching the way photography projects really run.', 'how-title' ); ?>
		<ol class="steps">
			<?php foreach ( $steps as $i => $s ) : ?>
			<li class="step">
				<span class="step-n">Step <?php echo (int) $i + 1; ?></span>
				<span class="step-ic"><?php echo Landing::icon( $s[0] ); // phpcs:ignore ?></span>
				<h3><?php echo esc_html( $s[1] ); ?></h3>
				<p><?php echo esc_html( $s[2] ); ?></p>
			</li>
			<?php endforeach; ?>
		</ol>
		<p class="flow" aria-label="Project stages tracked in ShootPilot">
			<?php foreach ( array( 'New Enquiry', 'First Call', 'Quotation Sent', 'Booking Confirmed', 'Advance Paid', 'Event Executed', 'Editing', 'Album Design', 'Final Delivery' ) as $f ) : ?><span><?php echo esc_html( $f ); ?></span><?php endforeach; ?>
		</p>
	</div>
</section>
