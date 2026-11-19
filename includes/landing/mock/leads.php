<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$leads = array(
	array( 'RK', 'Riya & Karan', '+91 98XXX XX210', 'Wedding', '22-11-2026', 'Instagram', '₹3,50,000', 'Negotiation', 's-neg', 'Hot' ),
	array( 'AS', 'Ananya Shah', '+91 97XXX XX034', 'Pre-Wedding', '24-11-2026', 'Website', '₹60,000', 'Quotation Sent', 's-quo', 'Medium' ),
	array( 'MJ', 'Mehta Jewellers', '+91 99XXX XX881', 'Product', '02-12-2026', 'Google Ads', '₹45,000', 'Interested', 's-int', 'Medium' ),
	array( 'VP', 'Vikram Patil', '+91 90XXX XX457', 'Birthday', '07-12-2026', 'Referral', '₹25,000', 'New Lead', 's-new', 'Low' ),
	array( 'NS', 'Neha & Siddharth', '+91 88XXX XX602', 'Engagement', '14-12-2026', 'WhatsApp', '₹1,20,000', 'Contacted', 's-con', 'High' ),
	array( 'TC', 'TechCorp Annual Day', '+91 80XXX XX119', 'Corporate', '20-12-2026', 'Facebook Ads', '₹80,000', 'Booking Confirmed', 's-bkd', 'High' ),
);
?>
<div class="mock" role="img" aria-label="Preview of the ShootPilot leads table with filters, sources, budgets and stages">
	<div class="mock-bar"><i></i><i></i><i></i><span class="mock-url">yourstudio.in/crm/leads</span></div>
	<div class="mock-pad">
		<div class="mx-head"><div><b class="m-h">Leads</b><small>128 leads · 68 open</small></div><span class="mx-btn"><?php echo Landing::icon( 'plus' ); // phpcs:ignore ?>New lead</span></div>
		<div class="mx-filters"><span class="m-search"><?php echo Landing::icon( 'search' ); // phpcs:ignore ?>Search name or phone</span><span class="chip on">All stages</span><span class="chip">Wedding</span><span class="chip">This month</span><span class="chip"><?php echo Landing::icon( 'filter' ); // phpcs:ignore ?>More filters</span></div>
		<div class="mx-table">
			<div class="tr th"><span>Lead</span><span>Event</span><span class="hide-sm">Date</span><span class="hide-sm">Source</span><span class="hide-xs">Budget</span><span>Stage</span></div>
			<?php foreach ( $leads as $l ) : ?>
			<div class="tr">
				<span class="who"><span class="av av-<?php echo esc_attr( ( crc32( $l[0] ) % 4 ) + 1 ); ?>"><?php echo esc_html( $l[0] ); ?></span><span><b><?php echo esc_html( $l[1] ); ?></b><small><?php echo esc_html( $l[2] ); ?></small></span></span>
				<span><?php echo esc_html( $l[3] ); ?></span>
				<span class="hide-sm"><?php echo esc_html( $l[4] ); ?></span>
				<span class="hide-sm"><?php echo esc_html( $l[5] ); ?></span>
				<span class="hide-xs num"><?php echo esc_html( $l[6] ); ?></span>
				<span><em class="stage <?php echo esc_attr( $l[8] ); ?>"><?php echo esc_html( $l[7] ); ?></em></span>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>
