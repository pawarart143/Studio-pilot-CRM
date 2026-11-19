<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$rows = array(
	array( 'Sharma–Mehta wedding', 'Advance', '18-11-2026', '₹50,000', 'Paid', 'p-paid', 'UPI' ),
	array( 'Sharma–Mehta wedding', 'Installment 2', '22-11-2026', '₹1,00,000', 'Due', 'p-due', '—' ),
	array( 'Ananya pre-wedding', 'Advance', '15-11-2026', '₹20,000', 'Overdue', 'p-over', '—' ),
	array( 'TechCorp Annual Day', 'Full payment', '10-11-2026', '₹80,000', 'Paid', 'p-paid', 'Razorpay' ),
	array( 'Kapoor maternity', 'Balance', '30-11-2026', '₹9,000', 'Link sent', 'p-link', 'Razorpay' ),
);
?>
<div class="mock" role="img" aria-label="Preview of ShootPilot payment management showing received, pending and overdue payments">
	<div class="mock-bar"><i></i><i></i><i></i><span class="mock-url">yourstudio.in/crm/payments</span></div>
	<div class="mock-pad">
		<div class="mx-head"><div><b class="m-h">Payments</b><small>November 2026</small></div><span class="mx-btn"><?php echo Landing::icon( 'receipt' ); // phpcs:ignore ?>Record payment</span></div>
		<div class="pay-kpis">
			<div><small>Received</small><b>₹4,69,500</b></div>
			<div><small>Pending</small><b>₹2,15,000</b></div>
			<div><small>Overdue</small><b class="red">₹20,000</b></div>
			<div><small>Expenses</small><b>₹62,300</b></div>
		</div>
		<div class="mx-table">
			<div class="tr th"><span>Booking</span><span class="hide-xs">For</span><span class="hide-sm">Due date</span><span class="num">Amount</span><span class="hide-sm">Mode</span><span>Status</span></div>
			<?php foreach ( $rows as $r ) : ?>
			<div class="tr"><span><b><?php echo esc_html( $r[0] ); ?></b></span><span class="hide-xs"><?php echo esc_html( $r[1] ); ?></span><span class="hide-sm"><?php echo esc_html( $r[2] ); ?></span><span class="num"><?php echo esc_html( $r[3] ); ?></span><span class="hide-sm"><?php echo esc_html( $r[6] ); ?></span><span><em class="stage <?php echo esc_attr( $r[5] ); ?>"><?php echo esc_html( $r[4] ); ?></em></span></div>
			<?php endforeach; ?>
		</div>
	</div>
</div>
