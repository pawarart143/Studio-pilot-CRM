<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

// November 2026 starts on a Sunday. Week starts Monday, so 6 leading blanks.
$events = array(
	5  => array( 'Corporate · TechCorp', 'c-blue' ),
	12 => array( 'Maternity · Kapoor', 'c-green' ),
	20 => array( 'Mehndi · Sharma', 'c-coral' ),
	21 => array( 'Haldi · Sharma', 'c-coral' ),
	22 => array( 'Wedding · Sharma', 'c-coral' ),
	24 => array( 'Pre-wedding · Ananya', 'c-violet' ),
	28 => array( 'Album review', 'c-green' ),
);
?>
<div class="mock" role="img" aria-label="Preview of the ShootPilot booking calendar for November with weddings, pre-wedding shoots and corporate events">
	<div class="mock-bar"><i></i><i></i><i></i><span class="mock-url">yourstudio.in/crm/calendar</span></div>
	<div class="mock-pad">
		<div class="mx-head"><div><b class="m-h">November 2026</b><small>7 events · 12 crew assignments</small></div><span class="chip-group"><span class="chip on">Month</span><span class="chip">Week</span><span class="chip">List</span></span></div>
		<div class="cal">
			<?php foreach ( array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ) as $d ) : ?><span class="cal-d"><?php echo esc_html( $d ); ?></span><?php endforeach; ?>
			<?php for ( $b = 0; $b < 6; $b++ ) : ?><span class="cal-c muted"></span><?php endfor; ?>
			<?php for ( $d = 1; $d <= 30; $d++ ) : ?>
			<span class="cal-c<?php echo 18 === $d ? ' today' : ''; ?>"><i><?php echo (int) $d; ?></i><?php if ( isset( $events[ $d ] ) ) : ?><em class="<?php echo esc_attr( $events[ $d ][1] ); ?>"><?php echo esc_html( $events[ $d ][0] ); ?></em><?php endif; ?></span>
			<?php endfor; ?>
			<?php for ( $b = 0; $b < 6; $b++ ) : ?><span class="cal-c muted"></span><?php endfor; ?>
		</div>
	</div>
</div>
