<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$cols = array(
	array( 'New Lead', 's-new', array( array( 'Vikram Patil', 'Birthday · 07-12', '₹25,000' ), array( 'Pooja & Rahul', 'Wedding · 10-01', '₹4,00,000' ) ) ),
	array( 'Contacted', 's-con', array( array( 'Neha & Siddharth', 'Engagement · 14-12', '₹1,20,000' ) ) ),
	array( 'Quotation Sent', 's-quo', array( array( 'Ananya Shah', 'Pre-Wedding · 24-11', '₹60,000' ), array( 'Kapoor Family', 'Baby shoot · 05-12', '₹18,000' ) ) ),
	array( 'Negotiation', 's-neg', array( array( 'Riya & Karan', 'Wedding · 22-11', '₹3,50,000' ) ) ),
	array( 'Booking Confirmed', 's-bkd', array( array( 'TechCorp Annual Day', 'Corporate · 20-12', '₹80,000' ) ) ),
);
?>
<div class="mock" role="img" aria-label="Preview of the ShootPilot sales pipeline Kanban board with lead cards in each stage">
	<div class="mock-bar"><i></i><i></i><i></i><span class="mock-url">yourstudio.in/crm/pipeline</span></div>
	<div class="mock-pad">
		<div class="mx-head"><div><b class="m-h">Pipeline</b><small>Drag cards to move a lead to the next stage</small></div><span class="mx-btn"><?php echo Landing::icon( 'plus' ); // phpcs:ignore ?>New lead</span></div>
		<div class="kb">
			<?php foreach ( $cols as $i => $c ) : ?>
			<div class="kb-col">
				<p class="kb-h"><em class="stage <?php echo esc_attr( $c[1] ); ?>"><?php echo esc_html( $c[0] ); ?></em><small><?php echo count( $c[2] ); ?></small></p>
				<?php foreach ( $c[2] as $j => $card ) : ?>
				<div class="kb-card<?php echo ( 3 === $i && 0 === $j ) ? ' lift' : ''; ?>"><b><?php echo esc_html( $card[0] ); ?></b><small><?php echo esc_html( $card[1] ); ?></small><span><?php echo esc_html( $card[2] ); ?></span></div>
				<?php endforeach; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>
