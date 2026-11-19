<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$rows = array(
	array( 'sheet', 'Leads scattered across WhatsApp and spreadsheets.', 'Every enquiry lands in one lead list with source, budget, event date and owner. Import your old Excel sheet in a minute.' ),
	array( 'bell', 'Missed client follow-ups.', 'A daily calling list shows who to call today and who is overdue, with reminders and a full call history.' ),
	array( 'calendar', 'Confusing booking schedules.', 'Multi-event bookings with dates, venues and crew in one calendar, plus a warning before you double-book a photographer.' ),
	array( 'rupee', 'Payment tracking problems.', 'Installment plans, advances, balances and receipts per booking. Overdue payments surface on the dashboard.' ),
	array( 'users', 'Disorganized team assignments.', 'Assign photographers, cinematographers and editors to each event. Each role sees only what it needs.' ),
	array( 'package', 'Difficulty tracking projects and deliveries.', 'Track every project from shoot to editing, album design and final delivery, with tasks for your team.' ),
);
?>
<section class="section problems" id="why" aria-labelledby="prob-title">
	<div class="wrap">
		<?php Landing::heading( 'Why ShootPilot', 'Stop Juggling Tools. Start Growing Your Studio.', 'Most studios run on WhatsApp chats, Excel sheets and memory. ShootPilot replaces the juggling with one system your whole team can trust.', 'prob-title' ); ?>
		<div class="ps-grid">
			<?php foreach ( $rows as $r ) : ?>
			<article class="ps">
				<p class="ps-problem"><span class="ps-x"><?php echo Landing::icon( 'x' ); // phpcs:ignore ?></span><?php echo esc_html( $r[1] ); ?></p>
				<div class="ps-fix">
					<span class="ps-ic"><?php echo Landing::icon( $r[0] ); // phpcs:ignore ?></span>
					<p><b>With ShootPilot</b><?php echo esc_html( $r[2] ); ?></p>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
