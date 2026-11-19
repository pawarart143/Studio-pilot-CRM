<?php
namespace PCRM;

use PCRM\Rest\Quotations;

defined( 'ABSPATH' ) || exit;

/**
 * Public quotation page shared with clients (/crm/q/{token}).
 */
class Public_Quote {

	public static function render( $token, $pdf = false ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', $token );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, studio_id FROM ' . Db::t( 'quotations' ) . ' WHERE public_token=%s', $token ) );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( ! $row ) {
			status_header( 404 );
			self::page( 'Quotation not found', '<div class="card"><h1>Quotation not found</h1><p class="muted">This link is invalid or the quotation was removed.</p></div>' );
			return;
		}
		$sid    = (int) $row->studio_id;
		$studio = Tenant::load_studio( $sid );
		$q      = Quotations::load( (int) $row->id, $sid );
		if ( ! $studio || 'active' !== $studio->status ) {
			status_header( 404 );
			self::page( 'Unavailable', '<div class="card"><h1>Unavailable</h1></div>' );
			return;
		}
		if ( $pdf ) {
			Pdf::quotation( $q, $studio, true );
			return;
		}

		$msg = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pcrm_action'] ) && in_array( $q->status, array( 'sent', 'viewed', 'draft' ), true ) ) { // phpcs:ignore
			$act = sanitize_key( wp_unslash( $_POST['pcrm_action'] ) ); // phpcs:ignore
			if ( in_array( $act, array( 'accept', 'reject' ), true ) ) {
				Quotations::change_status( $q, 'accept' === $act ? 'accepted' : 'rejected', $sid );
				$q   = Quotations::load( $q->id, $sid );
				$msg = 'accept' === $act ? 'Thank you! We have received your confirmation. Our team will contact you shortly.' : 'Thank you for letting us know. Feel free to reach out if you would like any changes.';
			}
		} elseif ( 'sent' === $q->status && ! is_user_logged_in() ) {
			Quotations::change_status( $q, 'viewed', $sid );
			$q = Quotations::load( $q->id, $sid );
		}

		$cats   = Config::service_categories();
		$types  = Config::event_types();
		$money  = function ( $n ) {
			return '₹' . Format::inr( $n, 2 );
		};
		$e      = 'esc_html';
		$expired = 'expired' === $q->status || ( $q->valid_until && $q->valid_until < Db::today() && in_array( $q->status, array( 'sent', 'viewed', 'draft' ), true ) );
		ob_start();
		?>
		<div class="card">
			<div class="head">
				<div>
					<?php if ( $studio->logo_url ) : ?><img src="<?php echo esc_url( $studio->logo_url ); ?>" class="logo" alt=""><?php endif; ?>
					<div class="studio"><?php echo $e( $studio->name ); ?></div>
					<div class="muted"><?php echo $e( trim( $studio->city . ' · ' . $studio->phone, ' ·' ) ); ?></div>
				</div>
				<div class="right">
					<div class="tag">Quotation</div>
					<div class="num"><?php echo $e( $q->number ); ?></div>
					<div class="muted">Valid until <?php echo $e( Format::date( $q->valid_until ) ); ?></div>
				</div>
			</div>
			<?php if ( $msg ) : ?><div class="alert ok"><?php echo $e( $msg ); ?></div><?php endif; ?>
			<?php if ( 'accepted' === $q->status && ! $msg ) : ?><div class="alert ok">You accepted this quotation. Thank you!</div><?php endif; ?>
			<?php if ( 'rejected' === $q->status && ! $msg ) : ?><div class="alert">You declined this quotation.</div><?php endif; ?>
			<?php if ( $expired && ! in_array( $q->status, array( 'accepted', 'rejected' ), true ) ) : ?><div class="alert">This quotation has expired. Please contact us for an updated quote.</div><?php endif; ?>
			<div class="grid">
				<div><div class="label">Prepared for</div><b><?php echo $e( $q->customer_name ); ?></b></div>
				<div><div class="label">Event</div><b><?php echo $e( $q->title ); ?></b><div class="muted"><?php echo $e( trim( ( $q->event_type ? $types[ $q->event_type ] : '' ) . ( $q->event_date ? ' · ' . Format::date( $q->event_date ) : '' ) . ( $q->venue ? ' · ' . $q->venue : '' ), ' ·' ) ); ?></div></div>
			</div>
			<table>
				<thead><tr><th>Service</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
				<tbody>
				<?php foreach ( $q->items as $it ) : ?>
					<tr>
						<td><?php if ( $it->category ) : ?><div class="cat"><?php echo $e( $cats[ $it->category ] ); ?></div><?php endif; ?><b><?php echo $e( $it->name ); ?></b><?php if ( $it->description ) : ?><div class="muted small"><?php echo $e( $it->description ); ?></div><?php endif; ?></td>
						<td class="r"><?php echo $e( rtrim( rtrim( number_format( $it->qty, 2 ), '0' ), '.' ) ); ?></td>
						<td class="r"><?php echo $e( $money( $it->rate ) ); ?></td>
						<td class="r"><?php echo $e( $money( $it->amount ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="totals">
				<div><span>Subtotal</span><span><?php echo $e( $money( $q->subtotal ) ); ?></span></div>
				<?php if ( $q->discount_amount > 0 ) : ?><div><span>Discount</span><span>- <?php echo $e( $money( $q->discount_amount ) ); ?></span></div><?php endif; ?>
				<?php if ( $q->gst_rate > 0 ) : ?><div><span>GST (<?php echo $e( (float) $q->gst_rate ); ?>%)</span><span><?php echo $e( $money( $q->gst_amount ) ); ?></span></div><?php endif; ?>
				<div class="grand"><span>Total</span><span><?php echo $e( $money( $q->total ) ); ?></span></div>
			</div>
			<?php if ( $q->notes ) : ?><h4>Notes</h4><p class="pre"><?php echo $e( $q->notes ); ?></p><?php endif; ?>
			<?php if ( $q->terms ) : ?><h4>Terms &amp; Conditions</h4><p class="pre"><?php echo $e( $q->terms ); ?></p><?php endif; ?>
			<div class="actions">
				<a class="btn ghost" href="<?php echo esc_url( home_url( '/crm/q/' . $token . '/pdf' ) ); ?>">Download PDF</a>
				<?php if ( in_array( $q->status, array( 'sent', 'viewed', 'draft' ), true ) && ! $expired ) : ?>
					<form method="post"><input type="hidden" name="pcrm_action" value="reject"><button class="btn ghost" type="submit">Decline</button></form>
					<form method="post"><input type="hidden" name="pcrm_action" value="accept"><button class="btn" type="submit">Accept Quotation</button></form>
				<?php endif; ?>
			</div>
		</div>
		<?php
		self::page( $q->number . ' – ' . $studio->name, ob_get_clean() );
	}

	private static function page( $title, $body ) {
		?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?php echo esc_html( $title ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f6f7f9;--card:#fff;--text:#0f172a;--muted:#64748b;--line:#e2e8f0;--accent:#C2471F;--soft:#FEEEE8}
@media (prefers-color-scheme:dark){:root{--bg:#0b0d12;--card:#12151c;--text:#e5e7eb;--muted:#94a3b8;--line:#232836;--soft:#1e1b4b}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 Inter,system-ui,sans-serif;padding:24px 16px}
.card{max-width:820px;margin:0 auto;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px}
.head{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:1px solid var(--line);padding-bottom:18px;margin-bottom:18px}
.logo{max-height:48px;margin-bottom:6px}.studio{font-size:20px;font-weight:700}.right{text-align:right}
.tag{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--accent);font-weight:600}.num{font-size:18px;font-weight:700}
.muted{color:var(--muted)}.small{font-size:12px}.label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:2px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}@media(max-width:600px){.grid{grid-template-columns:1fr}.right{text-align:left}}
table{width:100%;border-collapse:collapse}th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:left;padding:8px;border-bottom:1px solid var(--line)}
td{padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:top}.r{text-align:right;white-space:nowrap}.cat{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--accent)}
.totals{max-width:320px;margin:14px 0 0 auto}.totals div{display:flex;justify-content:space-between;padding:4px 8px}.totals .grand{font-size:18px;font-weight:700;border-top:2px solid var(--text);margin-top:6px;padding-top:10px}
h4{margin:22px 0 4px}.pre{white-space:pre-line;color:var(--muted);margin:0}
.actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:26px}.actions form{margin:0}
.btn{display:inline-block;background:var(--accent);color:#fff;border:0;border-radius:10px;padding:10px 18px;font:600 14px Inter,sans-serif;cursor:pointer;text-decoration:none}
.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--line)}
.alert{background:var(--soft);border-radius:10px;padding:12px 14px;margin-bottom:16px}.alert.ok{background:#dcfce7;color:#166534}
</style></head><body><?php echo $body; // phpcs:ignore ?></body></html>
		<?php
	}
}
