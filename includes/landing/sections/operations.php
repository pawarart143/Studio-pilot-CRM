<?php
/**
 * Studio operations built since v0.4.0: crew scheduling, enquiry form, client
 * portal, team directory with service-wise assignment, deliverables and
 * admin-controlled payments. Each claim matches a shipped module.
 */
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$ops = array(
	array( 'calendar', 'Crew Scheduling', 'Schedule freelance photographers, cinematographers, drone operators and editors for each event day. ShootPilot warns you about clashing bookings, opens WhatsApp with the event details ready for each person, and tracks what you owe them.', array( 'Month, week, day and list views', 'Clash check before you save', 'Crew payments and advances' ) ),
	array( 'contact', 'Public Enquiry Form', 'Share one link on Instagram, your website or WhatsApp. Clients fill a 4-step, mobile-friendly form and every enquiry lands in Leads with its source, services, dates and budget.', array( 'Questions change by event type', 'Possible duplicates flagged for review', 'Spam protection built in' ) ),
	array( 'heart', 'Client Portal with PIN', 'Every confirmed booking gets a private page for the client, protected by a PIN. They see event dates and the live progress of every deliverable, without seeing your notes, payments or team.', array( 'Share link and PIN on WhatsApp', 'No client login needed', 'Lock-out after wrong PINs' ) ),
	array( 'users', 'Team Directory & Service-wise Crew', 'Keep your team with roles, specialization, city and availability. Add services to a booking, such as candid, cinematography or drone, and assign the right people to each one.', array( 'On-site services block shoot dates', 'Double-booking warnings', 'Profiles with upcoming work' ) ),
	array( 'checkbox', 'Deliverables Tracking', 'Track edited photos, highlights, films and albums per booking with status, progress, expected date and delivery link. Start from templates like Wedding Package or Pre-Wedding.', array( 'Overdue filter across bookings', 'Editors update their own items', 'Progress shown in the client portal' ) ),
	array( 'rupee', 'Admin-controlled Payments', 'Only owners and admins can add, edit or delete payment records. Every change is written to a payment activity log with who changed what, so your books stay trustworthy.', array( 'Advance, installment and final', 'Received, pending, failed, refunded', 'Full audit trail' ) ),
);
?>
<section class="section features ops" id="operations" aria-labelledby="ops-title">
	<div class="wrap">
		<?php Landing::heading( 'Studio operations', 'From the first enquiry to the final album.', 'Run the shoot day, your crew and every delivery from the same place you sell.', 'ops-title' ); ?>
		<div class="bento ops-grid">
			<?php foreach ( $ops as $o ) : ?>
			<article class="fc">
				<span class="fc-ic"><?php echo Landing::icon( $o[0] ); // phpcs:ignore ?></span>
				<h3><?php echo esc_html( $o[1] ); ?></h3>
				<p><?php echo esc_html( $o[2] ); ?></p>
				<ul class="ops-list">
					<?php foreach ( $o[3] as $it ) : ?>
					<li><?php echo Landing::icon( 'check' ); // phpcs:ignore ?><?php echo esc_html( $it ); ?></li>
					<?php endforeach; ?>
				</ul>
			</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
