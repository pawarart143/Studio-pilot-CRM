<?php
namespace PCRM;

use PCRM\Rest\Quotations;

defined( 'ABSPATH' ) || exit;

/**
 * Public quotation page shared with clients (/crm/q/{token}).
 * v0.6.0: branded layout, event days with crew, deliverables, payment
 * milestones, view tracking and accept (typed name) / decline (reason).
 */
class Public_Quote {

	public static function render( $token, $pdf = false ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', $token );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, studio_id FROM ' . Db::t( 'quotations' ) . ' WHERE public_token=%s', $token ) );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		do_action( 'litespeed_control_set_nocache', 'ShootPilot quotation' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! $row ) {
			status_header( 404 );
			self::page( 'Quotation not found', '<div class="card pad"><h1>Quotation not found</h1><p class="muted">This link is invalid, was replaced with a new one, or the quotation was removed. Please ask the studio for the latest link.</p></div>', '#F3724C' );
			return;
		}
		$sid    = (int) $row->studio_id;
		$studio = Tenant::load_studio( $sid );
		if ( ! $studio || 'active' !== $studio->status ) {
			status_header( 404 );
			self::page( 'Unavailable', '<div class="card pad"><h1>Unavailable</h1></div>', '#F3724C' );
			return;
		}
		$q     = Quotations::load( (int) $row->id, $sid );
		$staff = is_user_logged_in();
		if ( $pdf ) {
			if ( ! $staff ) {
				Db::audit( 'quotation', $q->id, 'pdf_download', 'Client downloaded the PDF', null, $sid );
			}
			Pdf::quotation( $q, $studio, true );
			return;
		}

		$msg     = '';
		$err     = '';
		$open    = in_array( $q->status, array( 'sent', 'viewed', 'draft' ), true );
		$expired = Quote_View::is_expired( $q );
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pcrm_action'] ) ) { // phpcs:ignore
			$act  = sanitize_key( wp_unslash( $_POST['pcrm_action'] ) ); // phpcs:ignore
			$name = isset( $_POST['accepted_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['accepted_name'] ) ) ) : ''; // phpcs:ignore
			$note = isset( $_POST['client_note'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['client_note'] ) ) ) : ''; // phpcs:ignore
			$trap = isset( $_POST['website'] ) ? trim( (string) wp_unslash( $_POST['website'] ) ) : ''; // phpcs:ignore
			if ( ! $open || $expired ) {
				$err = 'This quotation can no longer be changed online. Please contact the studio.';
			} elseif ( '' !== $trap ) {
				$err = 'Something went wrong. Please try again.';
			} elseif ( 'accept' === $act && ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 190 ) ) {
				$err = 'Please type your full name to accept.';
			} elseif ( 'accept' === $act && empty( $_POST['agree'] ) ) { // phpcs:ignore
				$err = 'Please tick the box to confirm you agree to the terms.';
			} elseif ( mb_strlen( $note ) > 2000 ) {
				$err = 'Your message is too long (2000 characters at most).';
			} elseif ( in_array( $act, array( 'accept', 'reject' ), true ) ) {
				$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
				Db::update(
					'quotations',
					$q->id,
					array(
						'accepted_name'    => 'accept' === $act ? $name : null,
						'client_note'      => '' !== $note ? $note : null,
						'response_ip_hash' => $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ) : null,
					),
					$sid
				);
				Quotations::change_status( $q, 'accept' === $act ? 'accepted' : 'rejected', $sid );
				if ( '' !== $note ) {
					Db::audit( 'quotation', $q->id, 'client_note', 'Client wrote: ' . mb_substr( $note, 0, 400 ), null, $sid );
				}
				$q   = Quotations::load( $q->id, $sid );
				$msg = 'accept' === $act ? 'Thank you, ' . $name . '! Your acceptance has been recorded. ' . $studio->name . ' will contact you shortly to confirm the booking.' : 'Thank you for letting us know. We have shared your message with the studio.';
				$open = false;
			}
		} elseif ( ! $staff ) {
			Db::update(
				'quotations',
				$q->id,
				array(
					'view_count'     => (int) $q->view_count + 1,
					'last_viewed_at' => Db::now(),
				),
				$sid
			);
			if ( 'sent' === $q->status ) {
				Quotations::change_status( $q, 'viewed', $sid );
			}
			$q = Quotations::load( $q->id, $sid );
		}

		$accent = Quote_View::accent( $studio );
		$e      = 'esc_html';
		$m      = array( 'PCRM\\Quote_View', 'money' );
		$types  = Config::event_types();
		$events = Quote_View::events( $q );
		$groups = Quote_View::groups( $q );
		$cats   = Config::service_categories();
		$prices = (int) $q->show_prices;
		$wa     = Quote_View::wa_number( $studio );
		$can    = $open && ! $expired;
		$bank   = trim( (string) $studio->settings['bank_details'] );
		$upi    = trim( (string) $studio->settings['upi_id'] );
		ob_start();
		?>
		<header class="hero">
			<div class="wrap">
				<div class="brand">
					<?php if ( $studio->logo_url ) : ?><img src="<?php echo esc_url( $studio->logo_url ); ?>" class="logo" alt="<?php echo esc_attr( $studio->name ); ?>"><?php endif; ?>
					<div>
						<div class="studio"><?php echo $e( $studio->name ); ?></div>
						<div class="muted small"><?php echo $e( trim( $studio->city . ( $studio->phone ? ' · ' . Format::phone( $studio->phone ) : '' ), ' ·' ) ); ?></div>
					</div>
				</div>
				<div class="kicker">Quotation <?php echo $e( $q->number ); ?></div>
				<h1><?php echo $e( $q->title ); ?></h1>
				<p class="lead">Prepared for <b><?php echo $e( $q->customer_name ); ?></b><?php echo $q->event_type && isset( $types[ $q->event_type ] ) ? ' · ' . $e( $types[ $q->event_type ] ) : ''; ?></p>
				<div class="facts">
					<div><span>Total investment</span><b><?php echo $e( call_user_func( $m, $q->total ) ); ?></b></div>
					<div><span>Issued</span><b><?php echo $e( Format::date( $q->created_at ) ); ?></b></div>
					<div><span>Valid until</span><b><?php echo $e( Format::date( $q->valid_until ) ); ?></b></div>
					<?php if ( $events ) : ?><div><span>Events</span><b><?php echo count( $events ); ?></b></div><?php endif; ?>
				</div>
			</div>
		</header>

		<main class="wrap">
			<?php if ( $msg ) : ?><div class="alert ok" role="status"><?php echo $e( $msg ); ?></div><?php endif; ?>
			<?php if ( $err ) : ?><div class="alert bad" role="alert"><?php echo $e( $err ); ?></div><?php endif; ?>
			<?php if ( 'accepted' === $q->status && ! $msg ) : ?><div class="alert ok">Accepted<?php echo $q->accepted_name ? ' by ' . $e( $q->accepted_name ) : ''; ?><?php echo $q->responded_at ? ' on ' . $e( Format::date( $q->responded_at ) ) : ''; ?>. Thank you!</div><?php endif; ?>
			<?php if ( 'rejected' === $q->status && ! $msg ) : ?><div class="alert">This quotation was declined. Reach out to the studio if you would like a revised quote.</div><?php endif; ?>
			<?php if ( $expired && ! in_array( $q->status, array( 'accepted', 'rejected' ), true ) ) : ?><div class="alert">This quotation expired on <?php echo $e( Format::date( $q->valid_until ) ); ?>. Please contact us for an updated quote.</div><?php endif; ?>

			<?php if ( $q->intro ) : ?><section class="card pad intro"><p class="pre"><?php echo $e( $q->intro ); ?></p></section><?php endif; ?>

			<?php if ( $events ) : ?>
			<section>
				<h2>Your events</h2>
				<div class="events">
					<?php foreach ( $events as $i => $ev ) : $cov = Quote_View::coverage_text( $ev->coverage ); ?>
					<article class="card event">
						<div class="day">Day <?php echo (int) $i + 1; ?></div>
						<h3><?php echo $e( $ev->name ); ?></h3>
						<?php if ( Quote_View::event_when( $ev ) ) : ?><p class="muted small"><?php echo $e( Quote_View::event_when( $ev ) ); ?></p><?php endif; ?>
						<?php if ( Quote_View::event_where( $ev ) ) : ?><p class="small"><?php echo $e( Quote_View::event_where( $ev ) ); ?></p><?php endif; ?>
						<?php if ( $cov ) : ?><div class="chips"><?php foreach ( $cov as $c ) : ?><span class="chip"><?php echo $e( $c ); ?></span><?php endforeach; ?></div><?php endif; ?>
						<?php if ( $ev->notes ) : ?><p class="muted small pre"><?php echo $e( $ev->notes ); ?></p><?php endif; ?>
					</article>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>

			<section>
				<h2>Investment</h2>
				<div class="card">
					<?php foreach ( $groups as $g ) : ?>
					<div class="group">
						<div class="ghead"><span><?php echo $e( $g['label'] ); ?></span><?php if ( $prices && count( $groups ) > 1 ) : ?><span class="muted"><?php echo $e( call_user_func( $m, $g['total'] ) ); ?></span><?php endif; ?></div>
						<?php foreach ( $g['items'] as $it ) : ?>
						<div class="line">
							<div class="what">
								<?php if ( $it->category && isset( $cats[ $it->category ] ) ) : ?><div class="cat"><?php echo $e( $cats[ $it->category ] ); ?></div><?php endif; ?>
								<b><?php echo $e( $it->name ); ?></b>
								<?php if ( $it->description ) : ?><div class="muted small"><?php echo $e( $it->description ); ?></div><?php endif; ?>
							</div>
							<div class="qty muted"><?php echo $e( Quote_View::qty( $it->qty ) . ( $it->unit ? ' ' . $it->unit . ( $it->qty > 1 ? 's' : '' ) : '' ) ); ?><?php if ( $prices ) : ?> × <?php echo $e( call_user_func( $m, $it->rate ) ); ?><?php endif; ?></div>
							<?php if ( $prices ) : ?><div class="amt"><?php echo $e( call_user_func( $m, $it->amount ) ); ?></div><?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>
					<?php endforeach; ?>
					<div class="totals">
						<div><span>Subtotal</span><span><?php echo $e( call_user_func( $m, $q->subtotal ) ); ?></span></div>
						<?php if ( $q->discount_amount > 0 ) : ?><div class="good"><span>Discount<?php echo 'percent' === $q->discount_type ? ' (' . $e( (float) $q->discount_value ) . '%)' : ''; ?></span><span>− <?php echo $e( call_user_func( $m, $q->discount_amount ) ); ?></span></div><?php endif; ?>
						<?php foreach ( Quote_View::gst_lines( $q ) as $gl ) : ?><div class="muted"><span><?php echo $e( $gl[0] ); ?></span><span><?php echo $e( call_user_func( $m, $gl[1] ) ); ?></span></div><?php endforeach; ?>
						<div class="grand"><span>Total</span><span><?php echo $e( call_user_func( $m, $q->total ) ); ?></span></div>
						<div class="words muted small"><?php echo $e( Format::words( $q->total ) ); ?></div>
					</div>
				</div>
			</section>

			<?php if ( $q->payment_schedule ) : ?>
			<section>
				<h2>Payment plan</h2>
				<div class="card">
					<?php foreach ( $q->payment_schedule as $i => $ms ) : ?>
					<div class="line milestone">
						<div class="step"><?php echo (int) $i + 1; ?></div>
						<div class="what"><b><?php echo $e( $ms['label'] ); ?></b><?php if ( $ms['due'] ) : ?><div class="muted small"><?php echo $e( $ms['due'] ); ?></div><?php endif; ?></div>
						<div class="qty muted"><?php echo $e( $ms['percent'] + 0 ); ?>%</div>
						<div class="amt"><?php echo $e( call_user_func( $m, $ms['amount'] ) ); ?></div>
					</div>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>

			<?php if ( $q->deliverables || $q->exclusions ) : ?>
			<section class="cols">
				<?php if ( $q->deliverables ) : ?>
				<div>
					<h2>What you will receive</h2>
					<ul class="card pad ticks"><?php foreach ( $q->deliverables as $d ) : ?><li><?php echo $e( $d ); ?></li><?php endforeach; ?></ul>
				</div>
				<?php endif; ?>
				<?php if ( $q->exclusions ) : ?>
				<div>
					<h2>Not included</h2>
					<ul class="card pad crosses"><?php foreach ( $q->exclusions as $d ) : ?><li><?php echo $e( $d ); ?></li><?php endforeach; ?></ul>
				</div>
				<?php endif; ?>
			</section>
			<?php endif; ?>

			<?php if ( $q->notes ) : ?><section><h2>Notes</h2><div class="card pad"><p class="pre"><?php echo $e( $q->notes ); ?></p></div></section><?php endif; ?>
			<?php if ( $q->terms ) : ?><section><h2>Terms &amp; conditions</h2><div class="card pad"><p class="pre muted"><?php echo $e( $q->terms ); ?></p></div></section><?php endif; ?>
			<?php if ( $bank || $upi ) : ?><section><h2>Payment details</h2><div class="card pad"><p class="pre"><?php echo $e( $bank ); ?><?php echo $upi ? ( $bank ? "\n" : '' ) . 'UPI: ' . $e( $upi ) : ''; ?></p></div></section><?php endif; ?>
			<?php if ( $q->closing ) : ?><section class="closing"><p class="pre"><?php echo $e( $q->closing ); ?></p><p class="sign">— <?php echo $e( $studio->name ); ?></p></section><?php endif; ?>

			<section id="respond" class="card pad respond">
				<div class="row">
					<a class="btn ghost" href="<?php echo esc_url( home_url( '/crm/q/' . $token . '/pdf' ) ); ?>">Download PDF</a>
					<?php if ( $wa ) : ?><a class="btn ghost" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://wa.me/' . $wa . '?text=' . rawurlencode( 'Hi ' . $studio->name . ', I have a question about quotation ' . $q->number . '.' ) ); ?>">Ask on WhatsApp</a><?php endif; ?>
				</div>
				<?php if ( $can ) : ?>
				<h2>Ready to go ahead?</h2>
				<p class="muted small">Accepting tells the studio you would like to book. They will confirm your dates and share the advance payment details.</p>
				<form method="post" class="form" id="accept-form">
					<input type="hidden" name="pcrm_action" value="accept">
					<div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
					<label class="lbl" for="accepted_name">Your full name</label>
					<input id="accepted_name" name="accepted_name" required minlength="2" maxlength="190" autocomplete="name" value="<?php echo esc_attr( $q->customer_name ); ?>">
					<label class="lbl" for="client_note">Message for the studio (optional)</label>
					<textarea id="client_note" name="client_note" rows="2" maxlength="2000" placeholder="Anything you would like to add"></textarea>
					<label class="check"><input type="checkbox" name="agree" value="1" required> I have read the quotation and agree to the terms above.</label>
					<div class="row end">
						<button class="btn ghost" type="button" onclick="document.getElementById('decline').hidden=false;this.form.hidden=true">Decline</button>
						<button class="btn" type="submit">Accept quotation</button>
					</div>
				</form>
				<form method="post" class="form" id="decline" hidden>
					<input type="hidden" name="pcrm_action" value="reject">
					<div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
					<label class="lbl" for="reason">Tell the studio why (optional)</label>
					<textarea id="reason" name="client_note" rows="3" maxlength="2000" placeholder="For example: budget, dates or services you would like changed"></textarea>
					<div class="row end">
						<button class="btn ghost" type="button" onclick="document.getElementById('accept-form').hidden=false;this.form.hidden=true">Back</button>
						<button class="btn dark" type="submit">Decline quotation</button>
					</div>
				</form>
				<?php endif; ?>
			</section>
			<p class="foot muted small"><?php echo $e( $studio->name ); ?><?php echo $studio->gstin ? ' · GSTIN ' . $e( $studio->gstin ) : ''; ?></p>
		</main>
		<?php if ( $can ) : ?>
		<div class="bar"><div><span class="muted small">Total</span><b><?php echo $e( call_user_func( $m, $q->total ) ); ?></b></div><a class="btn" href="#respond">Review &amp; accept</a></div>
		<?php endif; ?>
		<script>document.querySelectorAll('form').forEach(function(f){f.addEventListener('submit',function(){var b=f.querySelector('[type=submit]');if(b){b.disabled=true;b.textContent='Sending…';}});});</script>
		<?php
		self::page( $q->number . ' – ' . $studio->name, ob_get_clean(), $accent );
	}

	private static function page( $title, $body, $accent ) {
		$ink  = Quote_View::accent_ink( $accent );
		$soft = Quote_View::accent_soft( $accent );
		?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo esc_html( $title ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
<style>
:root{--bg:#f7f6f4;--card:#fff;--text:#14161b;--muted:#667085;--line:#e7e5e1;--accent:<?php echo esc_html( $accent ); ?>;--ink:<?php echo esc_html( $ink ); ?>;--soft:<?php echo esc_html( $soft ); ?>;--ok:#067647;--okbg:#e7f6ec}
@media (prefers-color-scheme:dark){:root{--bg:#0d0f13;--card:#151820;--text:#eceef2;--muted:#98a2b3;--line:#262a35;--soft:#221a17;--ink:<?php echo esc_html( $accent ); ?>;--okbg:#0f2a1c;--ok:#6ce9a6}}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 Inter,system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased;padding-bottom:84px}
.wrap{max-width:860px;margin:0 auto;padding:0 16px}
.hero{background:linear-gradient(180deg,var(--soft),var(--bg));border-bottom:1px solid var(--line);padding:28px 0 32px;margin-bottom:28px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}.logo{max-height:44px;max-width:140px;object-fit:contain}.studio{font-weight:600}
.kicker{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--ink);font-weight:600}
h1{font-family:Fraunces,Georgia,serif;font-weight:600;font-size:clamp(26px,5vw,38px);line-height:1.15;margin:6px 0 8px;letter-spacing:-.01em}
.lead{margin:0 0 20px;color:var(--muted)}.lead b{color:var(--text)}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.facts div{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px 14px}.facts span{display:block;font-size:12px;color:var(--muted)}.facts b{font-size:16px;font-variant-numeric:tabular-nums}
h2{font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600;margin:28px 0 10px}
h3{margin:2px 0 2px;font-size:16px}
section{margin-bottom:8px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;overflow:hidden}.pad{padding:18px 20px}
.muted{color:var(--muted)}.small{font-size:13px}.pre{white-space:pre-line;margin:0}
.events{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}.event{padding:16px 18px}.event p{margin:2px 0}
.day{display:inline-block;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--ink);background:var(--soft);border-radius:999px;padding:2px 9px;margin-bottom:6px}
.chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.chip{font-size:12px;border:1px solid var(--line);border-radius:999px;padding:2px 9px}
.group+.group{border-top:1px solid var(--line)}.ghead{display:flex;justify-content:space-between;padding:12px 20px 4px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--ink)}
.line{display:grid;grid-template-columns:1fr auto auto;gap:4px 16px;align-items:baseline;padding:10px 20px;border-top:1px solid var(--line)}.ghead+.line{border-top:0}
.cat{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}.qty{font-size:13px;white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums}.amt{font-weight:600;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;min-width:96px}
.milestone{grid-template-columns:auto 1fr auto auto}.step{width:26px;height:26px;border-radius:50%;background:var(--soft);color:var(--ink);font-size:12px;font-weight:700;display:grid;place-items:center}
.totals{border-top:1px solid var(--line);padding:14px 20px 18px;background:linear-gradient(0deg,var(--soft),transparent 70%)}.totals div{display:flex;justify-content:space-between;padding:3px 0;font-variant-numeric:tabular-nums}.totals .good{color:var(--ok)}
.totals .grand{font-size:22px;font-weight:700;border-top:1px solid var(--line);margin-top:8px;padding-top:12px}.totals .words{display:block;text-align:right}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:640px){.cols{grid-template-columns:1fr}.line{grid-template-columns:1fr auto}.line .qty{grid-column:1;text-align:left}.milestone{grid-template-columns:auto 1fr auto}.milestone .qty{grid-column:2}}
ul.ticks,ul.crosses{list-style:none;margin:0}ul li{padding:5px 0 5px 26px;position:relative}ul.ticks li:before{content:"";position:absolute;left:2px;top:11px;width:12px;height:6px;border-left:2px solid var(--ink);border-bottom:2px solid var(--ink);transform:rotate(-45deg)}ul.crosses li{color:var(--muted)}ul.crosses li:before{content:"–";position:absolute;left:4px;top:4px}
.closing{text-align:center;padding:24px 8px}.closing .pre{font-family:Fraunces,Georgia,serif;font-size:18px}.sign{color:var(--muted);margin:8px 0 0}
.respond{margin-top:20px}.respond h2{margin-top:18px}.row{display:flex;gap:10px;flex-wrap:wrap}.row.end{justify-content:flex-end;margin-top:6px}
.form{display:grid;gap:8px;margin-top:6px}.form[hidden]{display:none}.lbl{font-size:13px;font-weight:500}.form input:not([type=checkbox]),.form textarea{width:100%;font:inherit;color:inherit;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:10px 12px}.form input:focus,.form textarea:focus{outline:2px solid var(--accent);outline-offset:1px}
.check{display:flex;gap:10px;align-items:flex-start;font-size:14px}.check input{margin-top:4px;accent-color:var(--accent)}.hp{position:absolute;left:-9999px}
.btn{display:inline-flex;align-items:center;justify-content:center;background:var(--accent);color:#fff;border:0;border-radius:10px;padding:11px 18px;font:600 14px Inter,sans-serif;cursor:pointer;text-decoration:none}.btn:disabled{opacity:.6}
.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--line)}.btn.dark{background:var(--text);color:var(--card)}
.alert{background:var(--soft);border-radius:12px;padding:12px 16px;margin-bottom:16px}.alert.ok{background:var(--okbg);color:var(--ok)}.alert.bad{background:#fdecea;color:#b42318}
.bar{position:fixed;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:color-mix(in srgb,var(--card) 92%,transparent);backdrop-filter:blur(8px);border-top:1px solid var(--line)}.bar b{display:block;font-size:18px;font-variant-numeric:tabular-nums}
.foot{text-align:center;margin:24px 0}
@media(min-width:900px){.bar{display:none}body{padding-bottom:24px}}
@media print{.bar,.respond,.hero .facts div:nth-child(n+2){display:none}body{background:#fff;padding:0}.card{break-inside:avoid}}
</style></head><body><?php echo $body; // phpcs:ignore ?></body></html>
		<?php
	}
}
