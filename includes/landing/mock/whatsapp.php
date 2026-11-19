<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;
?>
<div class="mock" role="img" aria-label="Preview of WhatsApp messaging in ShootPilot: message templates and the message history for a lead">
	<div class="mock-bar"><i></i><i></i><i></i><span class="mock-url">yourstudio.in/crm/leads/riya-karan</span></div>
	<div class="mock-pad wa">
		<div class="wa-list">
			<p class="wa-lh">Templates</p>
			<span class="wa-t on"><?php echo Landing::icon( 'file' ); // phpcs:ignore ?>Quotation share</span>
			<span class="wa-t"><?php echo Landing::icon( 'rupee' ); // phpcs:ignore ?>Payment reminder</span>
			<span class="wa-t"><?php echo Landing::icon( 'card' ); // phpcs:ignore ?>Payment link</span>
			<span class="wa-t"><?php echo Landing::icon( 'calendar' ); // phpcs:ignore ?>Booking confirmed</span>
			<span class="wa-t"><?php echo Landing::icon( 'package' ); // phpcs:ignore ?>Delivery ready</span>
		</div>
		<div class="wa-chat">
			<div class="wa-ch"><span class="av av-1">RK</span><div><b>Riya &amp; Karan</b><small>+91 98XXX XX210 · Wedding 22-11-2026</small></div></div>
			<div class="wa-msgs">
				<p class="bub">Hi Riya, thank you for your enquiry! Sharing our wedding packages for 22-11-2026. <small>10:02 · Sent</small></p>
				<p class="bub">Here is your quotation QT-0142 (₹2,41,900 incl. GST): yourstudio.in/crm/q/… <small>10:05 · Sent</small></p>
				<p class="bub">Advance of ₹50,000 received. Thank you! Your booking is confirmed 🎉 <small>16:40 · Sent</small></p>
			</div>
			<div class="wa-compose"><span>Message preview with lead details filled in…</span><span class="mx-btn"><?php echo Landing::icon( 'send' ); // phpcs:ignore ?>Send on WhatsApp</span></div>
		</div>
	</div>
</div>
