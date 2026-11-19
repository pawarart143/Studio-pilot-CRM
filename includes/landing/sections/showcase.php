<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

$tabs = array(
	'dashboard' => array( 'home', 'Dashboard', 'Your studio at a glance: leads, bookings, revenue, dues and today\'s work.' ),
	'leads'     => array( 'contact', 'Leads', 'Search, filter and sort every enquiry. Import your Excel sheet or add a new lead in seconds.' ),
	'pipeline'  => array( 'columns', 'Pipeline', 'Drag leads across stages from first call to booking confirmed.' ),
	'whatsapp'  => array( 'message', 'WhatsApp', 'Ready templates filled with each client\'s details (Professional and Business), and a log of every message sent.' ),
	'calendar'  => array( 'calendar', 'Calendar', 'Every event, venue and crew assignment on one calendar.' ),
	'payments'  => array( 'card', 'Payments', 'Advances, installments, overdue dues, receipts and expenses in one place.' ),
);
?>
<section class="section showcase" id="product" aria-labelledby="show-title">
	<div class="wrap">
		<?php Landing::heading( 'Product tour', 'Your Entire Studio. One Powerful Dashboard.', 'Click through the main screens. These previews are built from the real ShootPilot interface with sample data.', 'show-title' ); ?>
		<div class="tabs" role="tablist" aria-label="ShootPilot screens">
			<?php $first = true; foreach ( $tabs as $k => $t ) : ?>
			<button type="button" role="tab" id="tab-<?php echo esc_attr( $k ); ?>" aria-controls="panel-<?php echo esc_attr( $k ); ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>"><?php echo Landing::icon( $t[0] ); // phpcs:ignore ?><?php echo esc_html( $t[1] ); ?></button>
			<?php $first = false; endforeach; ?>
		</div>
		<div class="stage-wrap">
			<?php $first = true; foreach ( $tabs as $k => $t ) : ?>
			<div class="panel" role="tabpanel" id="panel-<?php echo esc_attr( $k ); ?>" aria-labelledby="tab-<?php echo esc_attr( $k ); ?>"<?php echo $first ? '' : ' hidden'; ?>>
				<p class="panel-cap"><?php echo esc_html( $t[2] ); ?></p>
				<?php require __DIR__ . '/../mock/' . $k . '.php'; ?>
			</div>
			<?php $first = false; endforeach; ?>
		</div>
	</div>
</section>
