<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/*
 * PLACEHOLDER testimonials. Replace each entry with a real quote, name, studio
 * and city once you have written permission from the customer. Keep
 * 'placeholder' => true until then so the page labels it clearly.
 */
$quotes = apply_filters(
	'pcrm_landing_testimonials',
	array(
		array( 'placeholder' => true, 'quote' => 'Your customer\'s words about how ShootPilot changed the way they handle enquiries and follow-ups will go here.', 'name' => 'Customer name', 'role' => 'Studio name, City' ),
		array( 'placeholder' => true, 'quote' => 'A short quote about managing bookings, team assignments and payments in one place will go here.', 'name' => 'Customer name', 'role' => 'Studio name, City' ),
		array( 'placeholder' => true, 'quote' => 'A quote about saving time on quotations and WhatsApp updates for clients will go here.', 'name' => 'Customer name', 'role' => 'Studio name, City' ),
	)
);
?>
<section class="section testimonials" aria-labelledby="test-title">
	<div class="wrap">
		<?php Landing::heading( 'Testimonials', 'Stories from studios using ShootPilot.', 'Real reviews from photographers will appear here soon.', 'test-title' ); ?>
		<div class="t-grid">
			<?php foreach ( $quotes as $q ) : ?>
			<figure class="t-card<?php echo ! empty( $q['placeholder'] ) ? ' is-placeholder' : ''; ?>">
				<?php if ( ! empty( $q['placeholder'] ) ) : ?><span class="ph-tag">Placeholder</span><?php endif; ?>
				<?php echo Landing::icon( 'quote', 't-q' ); // phpcs:ignore ?>
				<blockquote><p><?php echo esc_html( $q['quote'] ); ?></p></blockquote>
				<figcaption><span class="av av-ph"><?php echo Landing::icon( 'user' ); // phpcs:ignore ?></span><span><b><?php echo esc_html( $q['name'] ); ?></b><small><?php echo esc_html( $q['role'] ); ?></small></span></figcaption>
			</figure>
			<?php endforeach; ?>
		</div>
	</div>
</section>
