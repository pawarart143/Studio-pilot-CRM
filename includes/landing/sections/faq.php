<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$pl    = Plans::all();
$price = function ( $k ) use ( $pl ) {
	return isset( $pl[ $k ] ) ? sprintf( '₹%s/month or ₹%s/year', Landing::inr( $pl[ $k ]['price'] ), Landing::inr( $pl[ $k ]['price_yearly'] ) ) : '';
};
$logins = function ( $k ) use ( $pl ) {
	if ( ! isset( $pl[ $k ] ) ) {
		return '';
	}
	return $pl[ $k ]['members'] < 0 ? 'unlimited logins' : ( 1 === $pl[ $k ]['members'] ? '1 login' : $pl[ $k ]['members'] . ' logins' );
};
$trial_days = isset( $pl['starter'] ) ? (int) $pl['starter']['trial_days'] : 15;
$faqs = array(
	array( 'what', 'What is ShootPilot?', 'ShootPilot is an all-in-one CRM and studio management platform for photographers. It brings leads, calls and follow-ups, quotations, bookings, calendar, payments, team, tasks and reports into one dashboard.' ),
	array( 'photographers', 'Is ShootPilot designed specifically for photographers?', 'Yes. Stages, event types, services and roles are built for photography: multi-event weddings, pre-wedding shoots, candid and traditional photography, cinematography, albums, reels, and roles like photographer, cinematographer, editor and album designer.' ),
	array( 'team', 'Can I manage multiple team members?', 'Yes. Invite your team with their own login and role: ' . $logins( 'starter' ) . ' on Starter, ' . $logins( 'professional' ) . ' on Professional and ' . $logins( 'business' ) . ' on Business. Each role sees only what it needs; for example, creative roles do not see payments or lead details. Freelance crew without a login are unlimited on every plan and can be scheduled in Crew Scheduling.' ),
	array( 'whatsapp', 'Can I connect my WhatsApp Business account?', 'On Professional and Business, ShootPilot opens WhatsApp with the message already written from ready templates (quotations, payment links, portal PIN, reminders), and you press send. You can also connect the WhatsApp Cloud API with your own Meta business account to send directly from the CRM. Every plan has one-tap WhatsApp chat buttons. Receiving messages inside ShootPilot is not available.' ),
	array( 'events', 'Can I manage multiple wedding events?', 'Yes. One booking can hold many events, such as Mehndi, Haldi, Wedding and Reception, each with its own date, venue and crew. ShootPilot warns you before a team member is double-booked.' ),
	array( 'quotes', 'Can I create and send quotations?', 'Yes. Build quotations from your service list with GST, download a branded PDF, and share a link with your client on WhatsApp or email.' ),
	array( 'payments', 'Can I track advance and pending payments?', 'Yes. Set an installment plan per booking, record advances and payments with receipts, and see pending and overdue amounts on the dashboard. On Professional and Business you can also send Razorpay payment links using your own Razorpay account, and the payment is recorded on the booking automatically.' ),
	array( 'mobile', 'Is ShootPilot accessible on mobile?', 'Yes. ShootPilot runs in the browser and is fully responsive, so it works on your phone, tablet and laptop without installing anything.' ),
	array( 'studios', 'Can multiple studios use ShootPilot independently?', 'Yes. Each studio gets its own separate workspace with its own leads, clients, bookings, payments, team and settings. Data is never shared between studios.' ),
	array( 'trial', 'Is there a free trial?', sprintf( 'Yes. Every studio starts on Starter, free for %d days, with no card needed. When the trial ends, choose Professional (%s) or Business (%s), paid securely through Razorpay. Your data is kept either way.', $trial_days, $price( 'professional' ), $price( 'business' ) ) ),
	array( 'portal', 'Can my clients track their album and photos?', 'Yes. Every confirmed booking gets a private client portal protected by a PIN. Clients see their event dates and the progress of each deliverable, such as edited photos, highlight film and album, without seeing your internal notes or payments.' ),
	array( 'enquiry', 'Can clients send enquiries from my website or Instagram?', 'Yes. Each studio gets a public enquiry form link. Every submission becomes a lead with its source, services, dates and budget, and possible duplicates are flagged for you to review.' ),
);
?>
<section class="section faq" id="faq" aria-labelledby="faq-title">
	<div class="wrap faq-wrap">
		<div class="faq-side">
			<?php Landing::heading( 'Resources', 'Frequently asked questions', 'Everything you need to know before getting started.', 'faq-title' ); ?>
			<a class="btn btn-ghost" href="<?php echo esc_url( $u['register'] ); ?>">Create your free studio <?php echo Landing::icon( 'arrow' ); // phpcs:ignore ?></a>
		</div>
		<div class="acc">
			<?php foreach ( $faqs as $i => $f ) : ?>
			<div class="acc-item" id="faq-<?php echo esc_attr( $f[0] ); ?>">
				<h3>
					<button type="button" class="acc-btn" aria-expanded="<?php echo 0 === $i ? 'true' : 'false'; ?>" aria-controls="faq-p-<?php echo (int) $i; ?>" id="faq-b-<?php echo (int) $i; ?>">
						<span><?php echo esc_html( $f[1] ); ?></span><?php echo Landing::icon( 'chevron' ); // phpcs:ignore ?>
					</button>
				</h3>
				<div class="acc-panel" id="faq-p-<?php echo (int) $i; ?>" role="region" aria-labelledby="faq-b-<?php echo (int) $i; ?>"<?php echo 0 === $i ? '' : ' hidden'; ?>>
					<p><?php echo esc_html( $f[2] ); ?></p>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<script type="application/ld+json"><?php
echo wp_json_encode(
	array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array_map(
			function ( $f ) {
				return array( '@type' => 'Question', 'name' => $f[1], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f[2] ) );
			},
			$faqs
		),
	)
);
?></script>
