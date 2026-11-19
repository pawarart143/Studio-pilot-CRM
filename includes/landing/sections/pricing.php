<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$cards = Landing::pricing();
$save  = Landing::yearly_saving();
$trial = 0;
foreach ( $cards as $c ) {
	$trial = max( $trial, (int) $c['trial_days'] );
}
?>
<section class="section pricing soft" id="pricing" aria-labelledby="price-title">
	<div class="wrap">
		<?php Landing::heading( 'Pricing', 'Simple Plans. Powerful Growth.', $trial ? sprintf( 'Start with a %d-day free trial. No card needed. Upgrade when your studio grows. All prices in INR.', $trial ) : 'Upgrade when your studio grows. All prices in INR.', 'price-title' ); ?>
		<div class="bill" role="group" aria-label="Billing period">
			<button type="button" class="on" data-bill="monthly" aria-pressed="true">Monthly</button>
			<button type="button" data-bill="yearly" aria-pressed="false">Yearly<?php if ( $save > 0 ) : ?> <span class="save">Save up to <?php echo (int) $save; ?>%</span><?php endif; ?></button>
		</div>
		<div class="price-grid">
			<?php foreach ( $cards as $c ) : ?>
			<?php
			$m    = (int) $c['monthly'];
			$y    = (int) $c['yearly'];
			$free = $m <= 0 && $y <= 0;
			$ypm  = $y > 0 ? (int) round( $y / 12 ) : $m;
			?>
			<article class="price<?php echo $c['featured'] ? ' featured' : ''; ?>">
				<?php if ( $c['featured'] ) : ?><span class="ribbon">Most popular</span><?php endif; ?>
				<h3><?php echo esc_html( $c['name'] ); ?></h3>
				<p class="for"><?php echo esc_html( $c['for'] ); ?></p>
				<?php if ( $free ) : ?>
				<p class="amt"><span class="cur">₹</span><span class="val">0</span><span class="per"><?php echo $c['trial_days'] ? esc_html( sprintf( '/ %d days', $c['trial_days'] ) ) : ''; ?></span></p>
				<p class="billed"><?php echo $c['trial_days'] ? esc_html( sprintf( '%d-day free trial · No card needed', $c['trial_days'] ) ) : 'Free'; ?></p>
				<?php else : ?>
				<p class="amt">
					<span class="cur">₹</span><span class="val" data-monthly="<?php echo esc_attr( Landing::inr( $m ) ); ?>" data-yearly="<?php echo esc_attr( Landing::inr( $ypm ) ); ?>"><?php echo esc_html( Landing::inr( $m ) ); ?></span><span class="per">/month</span>
				</p>
				<p class="billed" data-monthly="Billed monthly · or ₹<?php echo esc_attr( Landing::inr( $y ) ); ?>/year" data-yearly="Billed yearly at ₹<?php echo esc_attr( Landing::inr( $y ) ); ?>">Billed monthly · or ₹<?php echo esc_html( Landing::inr( $y ) ); ?>/year</p>
				<?php endif; ?>
				<a class="btn <?php echo $c['featured'] ? 'btn-primary' : 'btn-ghost'; ?> btn-block" href="<?php echo esc_url( add_query_arg( 'plan', $c['key'], $u['register'] ) ); ?>"><?php echo $free ? 'Start Free Trial' : 'Get Started'; ?></a>
				<ul>
					<?php foreach ( $c['items'] as $it ) : ?>
					<li><?php echo Landing::icon( 'check' ); // phpcs:ignore ?><?php echo esc_html( $it ); ?></li>
					<?php endforeach; ?>
				</ul>
			</article>
			<?php endforeach; ?>
		</div>
		<p class="price-note">Every studio starts on a free trial. Pay securely with UPI, cards or net banking through Razorpay, monthly or yearly, and cancel auto-renew any time from Billing. Crew without a login are unlimited on every plan.</p>
	</div>
</section>
