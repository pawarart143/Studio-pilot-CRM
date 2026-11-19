<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;
?>
<section class="section features" id="features" aria-labelledby="feat-title">
	<div class="wrap">
		<?php Landing::heading( 'Features', 'Built around how photography studios actually work.', 'Ten connected modules, one login. Every feature below is live in ShootPilot today.', 'feat-title' ); ?>
		<div class="bento">

			<article class="fc fc-wide">
				<div class="fc-text"><span class="fc-ic"><?php echo Landing::icon( 'contact' ); // phpcs:ignore ?></span><h3>Smart Lead Management</h3><p>Capture, organize and track every photography enquiry, with source, event type, budget and priority.</p></div>
				<div class="fc-ui ui-leads" aria-hidden="true">
					<div class="ul-row"><span class="av av-1">RK</span><b>Riya &amp; Karan</b><span class="tag">Wedding</span><span class="tag t-src">Instagram</span><span class="pill pill-hot">Hot</span></div>
					<div class="ul-row"><span class="av av-2">AS</span><b>Ananya Shah</b><span class="tag">Pre-wedding</span><span class="tag t-src">Website</span><span class="pill">Medium</span></div>
					<div class="ul-row"><span class="av av-3">VP</span><b>Vikram Patil</b><span class="tag">Birthday</span><span class="tag t-src">Referral</span><span class="pill">Low</span></div>
				</div>
			</article>

			<article class="fc fc-wide">
				<div class="fc-text"><span class="fc-ic"><?php echo Landing::icon( 'columns' ); // phpcs:ignore ?></span><h3>Visual Sales Pipeline</h3><p>Track leads from new enquiry to confirmed booking on a drag-and-drop Kanban board.</p></div>
				<div class="fc-ui ui-kanban" aria-hidden="true">
					<div><small>New</small><i><b>Vikram</b>₹25k</i><i><b>Pooja</b>₹4L</i><i class="s"></i></div>
					<div><small>Quotation</small><i><b>Ananya</b>₹60k</i><i class="s"></i></div>
					<div><small>Negotiation</small><i class="c"><b>Riya</b>₹3.5L</i></div>
					<div><small>Booked</small><i class="g"><b>TechCorp</b>₹80k</i><i class="g s"></i></div>
				</div>
			</article>

			<article class="fc">
				<span class="fc-ic"><?php echo Landing::icon( 'message' ); // phpcs:ignore ?></span><h3>WhatsApp Integration</h3>
				<p>Send quotations, payment links and reminders on WhatsApp in one tap using ready templates. On Professional and above, connect the WhatsApp Cloud API to send straight from the CRM.</p>
				<div class="fc-ui ui-wa" aria-hidden="true"><p>Hi Riya, here is your quotation for the wedding on 22-11-2026 ✨</p><small>Template · Quotation</small></div>
			</article>

			<article class="fc">
				<span class="fc-ic"><?php echo Landing::icon( 'briefcase' ); // phpcs:ignore ?></span><h3>Booking Management</h3>
				<p>Organize wedding dates, multiple events and venues, crew and deliverables under one booking.</p>
				<div class="fc-ui ui-book" aria-hidden="true"><span>Mehndi · 20 Nov</span><span>Haldi · 21 Nov</span><span>Wedding · 22 Nov</span></div>
			</article>

			<article class="fc">
				<span class="fc-ic"><?php echo Landing::icon( 'file' ); // phpcs:ignore ?></span><h3>Quotation Builder</h3>
				<p>Create professional GST-ready quotations from your service list, download the PDF and share a link with clients.</p>
				<div class="fc-ui ui-quote" aria-hidden="true"><p><span>Candid Photography</span><b>₹85,000</b></p><p><span>Cinematography</span><b>₹1,20,000</b></p><p class="tot"><span>Total incl. GST</span><b>₹2,41,900</b></p></div>
			</article>

			<article class="fc">
				<span class="fc-ic"><?php echo Landing::icon( 'card' ); // phpcs:ignore ?></span><h3>Payment Tracking</h3>
				<p>Monitor advances, installments, outstanding balances and receipts. Collect online with Razorpay payment links.</p>
				<div class="fc-ui ui-pay" aria-hidden="true"><div class="pay-top"><small>Paid ₹1,50,000 of ₹2,41,900</small><b>62%</b></div><i><em style="width:62%"></em></i></div>
			</article>

			<article class="fc">
				<span class="fc-ic"><?php echo Landing::icon( 'users' ); // phpcs:ignore ?></span><h3>Team Management</h3>
				<p>Assign photographers, cinematographers, editors and album designers, with role-based access for each.</p>
				<div class="fc-ui ui-team" aria-hidden="true"><span class="av av-1">AD</span><span class="av av-2">RS</span><span class="av av-3">NK</span><span class="av av-4">+4</span><small>5 crew on Sharma–Mehta</small></div>
			</article>

			<article class="fc">
				<span class="fc-ic"><?php echo Landing::icon( 'calendar' ); // phpcs:ignore ?></span><h3>Calendar &amp; Events</h3>
				<p>See every shoot, wedding and client meeting in one calendar. Never miss a date again.</p>
				<div class="fc-ui ui-cal" aria-hidden="true"><?php for ( $d = 16; $d <= 29; $d++ ) : ?><span class="<?php echo in_array( $d, array( 20, 21, 22 ), true ) ? 'ev' : ( 24 === $d ? 'ev2' : '' ); ?>"><?php echo (int) $d; ?></span><?php endfor; ?></div>
			</article>

			<article class="fc">
				<span class="fc-ic"><?php echo Landing::icon( 'checkbox' ); // phpcs:ignore ?></span><h3>Task &amp; Follow-up Management</h3>
				<p>Daily call lists, follow-up reminders and team tasks, so nothing slips through the cracks.</p>
				<div class="fc-ui ui-task" aria-hidden="true"><p class="done"><i></i>Call Riya about album</p><p><i></i>Send Ananya's quotation</p><p><i></i>Edit Haldi highlights</p></div>
			</article>

			<article class="fc">
				<span class="fc-ic"><?php echo Landing::icon( 'chart' ); // phpcs:ignore ?></span><h3>Reports &amp; Analytics</h3>
				<p>Understand revenue, bookings, lead sources and conversion rates at a glance.</p>
				<div class="fc-ui ui-chart" aria-hidden="true"><?php foreach ( array( 35, 52, 44, 68, 60, 82, 74 ) as $h ) : ?><i style="height:<?php echo (int) $h; ?>%"></i><?php endforeach; ?></div>
			</article>

		</div>
	</div>
</section>
