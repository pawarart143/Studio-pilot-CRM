<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$cards = Landing::pricing();
?>
<section class="section pricing soft" id="pricing" aria-labelledby="price-title">
	<div class="wrap">
		<?php Landing::heading( 'Pricing', 'Simple Plans. Powerful Growth.', 'Start free, upgrade when your studio grows. All prices in INR.', 'price-title' ); ?>
		<div class="bill" role="group" aria-label="Billing period">
			<button type="button" class="on" data-bill="monthly" aria-pressed="true">Monthly</button>
			<button type="button" data-bill="yearly" aria-pressed="false">Yearly <span class="save">2 months free</span></button>
		</div>
		<div class="price-grid">
			<?php foreach ( $cards as $c ) : ?>
			<?php
			$m = (int) $c['monthly'];
			$y = (int) round( $m * 10 / 12 ); // Yearly = 10 months, shown per month.
			?>
			<article class="price<?php echo $c['featured'] ? ' featured' : ''; ?>">
				<?php if ( $c['featured'] ) : ?><span class="ribbon">Most popular</span><?php endif; ?>
				<h3><?php echo esc_html( $c['name'] ); ?></h3>
				<p class="for"><?php echo esc_html( $c['for'] ); ?></p>
				<p class="amt">
					<span class="cur">₹</span><span class="val" data-monthly="<?php echo esc_attr( Landing::inr( $m ) ); ?>" data-yearly="<?php echo esc_attr( Landing::inr( $y ) ); ?>"><?php echo esc_html( Landing::inr( $m ) ); ?></span><span class="per">/month</span>
				</p>
				<p class="billed" data-monthly="Billed monthly" data-yearly="Billed yearly at ₹<?php echo esc_attr( Landing::inr( $m * 10 ) ); ?>">Billed monthly</p>
				<a class="btn <?php echo $c['featured'] ? 'btn-primary' : 'btn-ghost'; ?> btn-block" href="<?php echo esc_url( add_query_arg( 'plan', $c['key'], $u['register'] ) ); ?>">Get Started</a>
				<ul>
					<?php foreach ( $c['items'] as $it ) : ?>
					<li><?php echo Landing::icon( 'check' ); // phpcs:ignore ?><?php echo esc_html( $it ); ?></li>
					<?php endforeach; ?>
				</ul>
			</article>
			<?php endforeach; ?>
		</div>
		<p class="price-note"><strong>Placeholder pricing.</strong> Final plans and prices will be announced soon. A Free plan (3 team logins, 50 new leads a month) is available today. Every account starts on Free; paid plans are activated by our team.</p>
	</div>
</section>
