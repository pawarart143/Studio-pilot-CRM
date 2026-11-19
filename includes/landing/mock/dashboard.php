<?php
/**
 * Product preview: main dashboard. Pure HTML/CSS recreation of the app, with sample data.
 */
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$nav = array(
	array( 'home', 'Dashboard', true ),
	array( 'contact', 'Leads', false ),
	array( 'columns', 'Pipeline', false ),
	array( 'phone', 'Calling', false ),
	array( 'file', 'Quotations', false ),
	array( 'briefcase', 'Bookings', false ),
	array( 'calendar', 'Calendar', false ),
	array( 'card', 'Payments', false ),
	array( 'chart', 'Reports', false ),
	array( 'users', 'Team', false ),
);
$pipe = array(
	array( 'New Lead', 24, 100 ),
	array( 'Contacted', 18, 75 ),
	array( 'Quotation Sent', 11, 46 ),
	array( 'Negotiation', 6, 25 ),
	array( 'Booking Confirmed', 9, 38 ),
);
?>
<div class="mock mock-dash" role="img" aria-label="Preview of the ShootPilot dashboard showing leads, bookings, revenue, pending payments, sales pipeline, upcoming events and recent activity">
	<div class="mock-bar"><i></i><i></i><i></i><span class="mock-url">yourstudio.in/crm</span></div>
	<div class="mock-body">
		<aside class="m-side">
			<div class="m-brand"><?php echo Landing::logo( 'm-logo' ); // phpcs:ignore ?><div><b>ShootPilot</b><small>Lumière Studios</small></div></div>
			<?php foreach ( $nav as $n ) : ?>
				<span class="m-nav<?php echo $n[2] ? ' on' : ''; ?>"><?php echo Landing::icon( $n[0] ); // phpcs:ignore ?><?php echo esc_html( $n[1] ); ?></span>
			<?php endforeach; ?>
		</aside>
		<div class="m-main">
			<div class="m-top">
				<div><b class="m-h">Good morning, Aditi</b><small>Wednesday, 18-11-2026</small></div>
				<div class="m-top-r"><span class="m-search"><?php echo Landing::icon( 'search' ); // phpcs:ignore ?>Search leads, clients…</span><span class="m-bell"><?php echo Landing::icon( 'bell' ); // phpcs:ignore ?></span><span class="av av-1">AD</span></div>
			</div>
			<div class="m-kpis">
				<div class="m-kpi"><small><?php echo Landing::icon( 'contact' ); // phpcs:ignore ?>Total leads</small><b>128</b><span class="up">+12 this month</span></div>
				<div class="m-kpi"><small><?php echo Landing::icon( 'briefcase' ); // phpcs:ignore ?>Confirmed bookings</small><b>34</b><span class="up">+5 this month</span></div>
				<div class="m-kpi"><small><?php echo Landing::icon( 'rupee' ); // phpcs:ignore ?>Monthly revenue</small><b>₹6,84,500</b>
					<svg class="spark" viewBox="0 0 100 24" preserveAspectRatio="none" aria-hidden="true"><path d="M0 20 L12 17 L24 18 L36 12 L48 14 L60 9 L72 10 L84 5 L100 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/></svg>
				</div>
				<div class="m-kpi"><small><?php echo Landing::icon( 'clock' ); // phpcs:ignore ?>Pending payments</small><b>₹2,15,000</b><span class="warn">7 installments due</span></div>
			</div>
			<div class="m-grid">
				<div class="m-card m-pipe">
					<div class="m-ch"><b>Sales pipeline</b><small>68 open leads</small></div>
					<?php foreach ( $pipe as $p ) : ?>
					<div class="m-bar"><span><?php echo esc_html( $p[0] ); ?></span><i><em style="width:<?php echo (int) $p[2]; ?>%"></em></i><b><?php echo (int) $p[1]; ?></b></div>
					<?php endforeach; ?>
				</div>
				<div class="m-card m-events">
					<div class="m-ch"><b>Upcoming events</b><small>Next 7 days</small></div>
					<div class="m-ev"><span class="m-date"><b>21</b>NOV</span><div><b>Haldi · Sharma–Mehta</b><small><?php echo Landing::icon( 'pin' ); // phpcs:ignore ?>Jaipur · 3 crew</small></div></div>
					<div class="m-ev"><span class="m-date"><b>22</b>NOV</span><div><b>Wedding · Sharma–Mehta</b><small><?php echo Landing::icon( 'pin' ); // phpcs:ignore ?>Jaipur · 5 crew</small></div></div>
					<div class="m-ev"><span class="m-date"><b>24</b>NOV</span><div><b>Pre-wedding · Ananya</b><small><?php echo Landing::icon( 'pin' ); // phpcs:ignore ?>Goa · 2 crew</small></div></div>
				</div>
				<div class="m-card m-act">
					<div class="m-ch"><b>Recent activity</b></div>
					<div class="m-a"><span class="m-dot d-green"></span><p><b>Payment</b> ₹50,000 recorded for Sharma–Mehta</p><small>2m</small></div>
					<div class="m-a"><span class="m-dot d-coral"></span><p><b>Quotation</b> QT-0142 sent on WhatsApp</p><small>18m</small></div>
					<div class="m-a"><span class="m-dot d-blue"></span><p><b>Lead</b> moved to Negotiation</p><small>1h</small></div>
				</div>
			</div>
		</div>
	</div>
</div>
