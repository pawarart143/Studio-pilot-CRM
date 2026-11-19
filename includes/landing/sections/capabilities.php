<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$caps = array(
	array( 'contact', 'Lead Management' ),
	array( 'users', 'Client Management' ),
	array( 'briefcase', 'Booking Management' ),
	array( 'message', 'WhatsApp Integration' ),
	array( 'rupee', 'Payment Tracking' ),
	array( 'layers', 'Team Collaboration' ),
);
?>
<section class="caps" aria-labelledby="caps-title">
	<div class="wrap">
		<h2 id="caps-title" class="caps-title">Everything Your Studio Needs. <span>All in One Place.</span></h2>
		<ul class="caps-row">
			<?php foreach ( $caps as $c ) : ?>
			<li><span class="cap-ic"><?php echo Landing::icon( $c[0] ); // phpcs:ignore ?></span><?php echo esc_html( $c[1] ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
