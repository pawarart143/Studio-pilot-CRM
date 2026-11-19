<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Professional PDF documents (quotation, payment receipt) using Dompdf.
 */
class Pdf {

	/**
	 * Turns an uploaded image URL into a data URI so Dompdf never fetches remote URLs.
	 */
	private static function local_image( $url ) {
		$up = wp_upload_dir();
		if ( ! $url || 0 !== strpos( $url, $up['baseurl'] ) ) {
			return '';
		}
		$path = realpath( $up['basedir'] . substr( $url, strlen( $up['baseurl'] ) ) );
		if ( ! $path || 0 !== strpos( $path, realpath( $up['basedir'] ) ) || ! is_file( $path ) || filesize( $path ) > 2 * MB_IN_BYTES ) {
			return '';
		}
		$type = wp_check_filetype( $path );
		if ( ! in_array( $type['type'], array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
			return '';
		}
		return 'data:' . $type['type'] . ';base64,' . base64_encode( file_get_contents( $path ) ); // phpcs:ignore
	}


	private static function esc( $s ) {
		return esc_html( (string) $s );
	}

	private static function money( $n ) {
		return '₹' . Format::inr( $n, 2 );
	}

	private static function css() {
		return '
		@page { margin: 28px 34px; }
		body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #1f2937; }
		.brand { width: 100%; border-bottom: 2px solid #F3724C; padding-bottom: 12px; margin-bottom: 16px; }
		.brand td { vertical-align: top; }
		.studio { font-size: 18px; font-weight: bold; color: #111827; }
		.muted { color: #6b7280; }
		.doc-title { font-size: 20px; font-weight: bold; color: #C2471F; text-align: right; letter-spacing: 1px; }
		.meta { text-align: right; line-height: 1.6; }
		.box { width: 100%; margin-bottom: 16px; }
		.box td { vertical-align: top; width: 50%; }
		.label { font-size: 9px; text-transform: uppercase; color: #6b7280; letter-spacing: .6px; margin-bottom: 3px; }
		table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
		table.items th { background: #FEEEE8; color: #9A3412; text-align: left; padding: 7px 8px; font-size: 9.5px; text-transform: uppercase; letter-spacing: .4px; }
		table.items td { padding: 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
		.r { text-align: right; }
		.cat { font-size: 8.5px; color: #C2471F; text-transform: uppercase; letter-spacing: .4px; }
		.desc { color: #6b7280; font-size: 9.5px; margin-top: 2px; }
		table.totals { width: 46%; margin-left: 54%; margin-top: 12px; border-collapse: collapse; }
		table.totals td { padding: 5px 8px; }
		table.totals .grand td { font-size: 13px; font-weight: bold; color: #111827; border-top: 2px solid #111827; padding-top: 8px; }
		.section { margin-top: 20px; }
		.section h4 { margin: 0 0 6px; font-size: 11px; color: #111827; }
		.pre { white-space: pre-line; line-height: 1.55; color: #374151; }
		.footer { position: fixed; bottom: -8px; left: 0; right: 0; text-align: center; font-size: 8.5px; color: #9ca3af; }
		.stamp { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 9px; font-weight: bold; background: #dcfce7; color: #166534; }
		.amount-box { background: #FFF5F1; border: 1px solid #FBCDBD; padding: 14px; margin: 14px 0; }
		.amount-box .big { font-size: 22px; font-weight: bold; color: #C2471F; }
		';
	}

	private static function header( $studio, $title, array $meta ) {
		$logo = '';
		if ( $studio->logo_url ) {
			$src = self::local_image( $studio->logo_url );
			$logo = $src ? '<img src="' . $src . '" style="max-height:52px;max-width:160px;margin-bottom:6px"><br>' : '';
		}
		$contact = array_filter( array( $studio->address, trim( $studio->city . ( $studio->state ? ', ' . $studio->state : '' ), ', ' ), Format::phone( $studio->phone ), $studio->email ) );
		$html    = '<table class="brand"><tr><td>' . $logo . '<div class="studio">' . self::esc( $studio->name ) . '</div><div class="muted" style="line-height:1.5">' . nl2br( self::esc( implode( "\n", $contact ) ) ) . ( $studio->gstin ? '<br>GSTIN: ' . self::esc( $studio->gstin ) : '' ) . '</div></td>';
		$html   .= '<td><div class="doc-title">' . self::esc( $title ) . '</div><div class="meta">';
		foreach ( $meta as $k => $v ) {
			$html .= '<span class="muted">' . self::esc( $k ) . ':</span> <b>' . self::esc( $v ) . '</b><br>';
		}
		return $html . '</div></td></tr></table>';
	}

	/**
	 * Premium quotation PDF (v0.6.0): brand band in the studio colour, event
	 * days with crew, grouped investment, GST split, payment plan, deliverables,
	 * terms and an acceptance block.
	 */
	public static function quotation_html( $q, $studio ) {
		$cats   = Config::service_categories();
		$types  = Config::event_types();
		$acc    = Quote_View::accent( $studio );
		$ink    = Quote_View::accent_ink( $acc );
		$soft   = Quote_View::accent_soft( $acc, 0.9 );
		$events = Quote_View::events( $q );
		$groups = Quote_View::groups( $q );
		$prices = (int) $q->show_prices;
		$m      = array( __CLASS__, 'money' );
		$css    = self::css() . '
		@page { margin: 0 0 40px 0; }
		body { margin: 0; }
		.band { background: ' . $acc . '; color: #fff; padding: 26px 40px 22px; }
		.band td { vertical-align: top; color: #fff; }
		.band .studio { color: #fff; font-size: 17px; }
		.band .sub { color: #fff; opacity: .85; font-size: 9.5px; line-height: 1.5; }
		.band .doc { font-size: 26px; font-weight: bold; letter-spacing: 3px; text-align: right; }
		.band .num { text-align: right; font-size: 10px; line-height: 1.6; }
		.logo-box { background: #fff; padding: 6px 8px; display: inline-block; margin-bottom: 8px; }
		.body { padding: 22px 40px 0; }
		.hello { font-size: 15px; font-weight: bold; color: #111827; margin: 0 0 2px; }
		.k { font-size: 8.5px; text-transform: uppercase; letter-spacing: .8px; color: ' . $ink . '; font-weight: bold; margin: 18px 0 6px; }
		table.grid { width: 100%; border-collapse: collapse; }
		table.grid th { background: ' . $soft . '; color: ' . $ink . '; text-align: left; padding: 6px 8px; font-size: 8.5px; text-transform: uppercase; letter-spacing: .4px; }
		table.grid td { padding: 7px 8px; border-bottom: 1px solid #eceae6; vertical-align: top; }
		table.grid tr.sub td { background: #fafaf9; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; color: ' . $ink . '; font-weight: bold; padding: 5px 8px; }
		.chip { display: inline-block; border: 1px solid #e5e7eb; padding: 1px 6px; margin: 2px 3px 0 0; font-size: 8.5px; color: #374151; }
		table.totals .grand td { color: ' . $ink . '; border-top: 2px solid ' . $acc . '; font-size: 14px; }
		.cols td { vertical-align: top; width: 50%; }
		.li { padding: 2px 0 2px 12px; }
		.tick { color: ' . $ink . '; font-weight: bold; }
		.accept { border: 1px solid ' . $acc . '; background: ' . $soft . '; padding: 10px 12px; margin-top: 18px; }
		.footer { bottom: -28px; } .pagenum:before { content: counter(page); }
		';
		$html  = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';
		$html .= '<div class="footer">' . self::esc( $studio->name ) . ' · Quotation ' . self::esc( $q->number ) . ' · Page <span class="pagenum"></span></div>';

		// Brand band.
		$logo = $studio->logo_url ? self::local_image( $studio->logo_url ) : '';
		$contact = array_filter( array( trim( $studio->city . ( $studio->state ? ', ' . $studio->state : '' ), ', ' ), Format::phone( $studio->phone ), $studio->email ) );
		$html .= '<div class="band"><table style="width:100%"><tr><td style="width:60%">';
		$html .= $logo ? '<div class="logo-box"><img src="' . $logo . '" style="max-height:40px;max-width:150px"></div><br>' : '';
		$html .= '<div class="studio"><b>' . self::esc( $studio->name ) . '</b></div><div class="sub">' . self::esc( implode( '  ·  ', $contact ) ) . ( $studio->gstin ? '<br>GSTIN ' . self::esc( $studio->gstin ) : '' ) . '</div></td>';
		$html .= '<td><div class="doc">QUOTATION</div><div class="num">' . self::esc( $q->number ) . '<br>Issued ' . Format::date( $q->created_at ) . '<br>Valid until ' . Format::date( $q->valid_until ) . '</div></td></tr></table></div>';

		$html .= '<div class="body">';
		$html .= '<table class="box"><tr><td><div class="label">Prepared for</div><div class="hello">' . self::esc( $q->customer_name ) . '</div><span class="muted">' . self::esc( Format::phone( $q->customer_phone ) ) . ( $q->customer_email ? ' · ' . self::esc( $q->customer_email ) : '' ) . '</span></td>';
		$html .= '<td><div class="label">For</div><b>' . self::esc( $q->title ) . '</b><br><span class="muted">' . ( $q->event_type && isset( $types[ $q->event_type ] ) ? self::esc( $types[ $q->event_type ] ) : '' ) . ( $events ? ( $q->event_type ? ' · ' : '' ) . count( $events ) . ' event' . ( count( $events ) > 1 ? 's' : '' ) : '' ) . '</span></td></tr></table>';
		if ( $q->intro ) {
			$html .= '<div class="pre" style="margin-bottom:4px">' . self::esc( $q->intro ) . '</div>';
		}

		// Events.
		if ( $events ) {
			$html .= '<div class="k">Event schedule &amp; coverage</div><table class="grid"><thead><tr><th style="width:5%">Day</th><th style="width:28%">Event</th><th style="width:30%">Date &amp; venue</th><th>Team</th></tr></thead><tbody>';
			foreach ( $events as $i => $ev ) {
				$cov = Quote_View::coverage_text( $ev->coverage );
				$html .= '<tr><td><b>' . ( $i + 1 ) . '</b></td><td><b>' . self::esc( $ev->name ) . '</b>' . ( $ev->notes ? '<div class="desc">' . self::esc( $ev->notes ) . '</div>' : '' ) . '</td><td>' . self::esc( Quote_View::event_when( $ev ) ) . ( Quote_View::event_where( $ev ) ? '<div class="desc">' . self::esc( Quote_View::event_where( $ev ) ) . '</div>' : '' ) . '</td><td>';
				foreach ( $cov as $c ) {
					$html .= '<span class="chip">' . self::esc( $c ) . '</span>';
				}
				$html .= ( $cov ? '' : '<span class="muted">—</span>' ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}

		// Investment.
		$html .= '<div class="k">Investment</div><table class="grid"><thead><tr><th style="width:4%">#</th><th>Service</th><th class="r" style="width:11%">Qty</th>' . ( $prices ? '<th class="r" style="width:15%">Rate</th><th class="r" style="width:16%">Amount</th>' : '' ) . '</tr></thead><tbody>';
		$n = 0;
		foreach ( $groups as $g ) {
			if ( count( $groups ) > 1 ) {
				$html .= '<tr class="sub"><td colspan="' . ( $prices ? 5 : 3 ) . '">' . self::esc( $g['label'] ) . '</td></tr>';
			}
			foreach ( $g['items'] as $it ) {
				++$n;
				$html .= '<tr><td>' . $n . '</td><td>' . ( $it->category && isset( $cats[ $it->category ] ) ? '<div class="cat">' . self::esc( $cats[ $it->category ] ) . '</div>' : '' ) . '<b>' . self::esc( $it->name ) . '</b>' . ( $it->description ? '<div class="desc">' . self::esc( $it->description ) . '</div>' : '' ) . '</td><td class="r">' . self::esc( Quote_View::qty( $it->qty ) . ( $it->unit ? ' ' . $it->unit : '' ) ) . '</td>' . ( $prices ? '<td class="r">' . call_user_func( $m, $it->rate ) . '</td><td class="r">' . call_user_func( $m, $it->amount ) . '</td>' : '' ) . '</tr>';
			}
		}
		$html .= '</tbody></table><table class="totals"><tr><td>Subtotal</td><td class="r">' . call_user_func( $m, $q->subtotal ) . '</td></tr>';
		if ( $q->discount_amount > 0 ) {
			$html .= '<tr><td>Discount' . ( 'percent' === $q->discount_type ? ' (' . (float) $q->discount_value . '%)' : '' ) . '</td><td class="r">- ' . call_user_func( $m, $q->discount_amount ) . '</td></tr>';
		}
		foreach ( Quote_View::gst_lines( $q ) as $gl ) {
			$html .= '<tr><td class="muted">' . self::esc( $gl[0] ) . '</td><td class="r muted">' . call_user_func( $m, $gl[1] ) . '</td></tr>';
		}
		$html .= '<tr class="grand"><td>Total</td><td class="r">' . call_user_func( $m, $q->total ) . '</td></tr></table>';
		$html .= '<div class="muted" style="text-align:right;margin-top:4px;font-size:9px">' . self::esc( Format::words( $q->total ) ) . '</div>';

		// Payment plan.
		if ( $q->payment_schedule ) {
			$html .= '<div class="k">Payment plan</div><table class="grid"><thead><tr><th style="width:4%">#</th><th>Milestone</th><th>When</th><th class="r" style="width:10%">Share</th><th class="r" style="width:16%">Amount</th></tr></thead><tbody>';
			foreach ( $q->payment_schedule as $i => $ms ) {
				$html .= '<tr><td>' . ( $i + 1 ) . '</td><td><b>' . self::esc( $ms['label'] ) . '</b></td><td class="muted">' . self::esc( $ms['due'] ) . '</td><td class="r">' . self::esc( $ms['percent'] + 0 ) . '%</td><td class="r"><b>' . call_user_func( $m, $ms['amount'] ) . '</b></td></tr>';
			}
			$html .= '</tbody></table>';
		}

		// Deliverables / exclusions side by side.
		if ( $q->deliverables || $q->exclusions ) {
			$html .= '<table class="cols" style="width:100%;margin-top:6px"><tr>';
			if ( $q->deliverables ) {
				$html .= '<td style="padding-right:14px"><div class="k">What you will receive</div>';
				foreach ( $q->deliverables as $d ) {
					$html .= '<div class="li"><span class="tick">&#10003;</span> ' . self::esc( $d ) . '</div>';
				}
				$html .= '</td>';
			}
			if ( $q->exclusions ) {
				$html .= '<td><div class="k">Not included</div>';
				foreach ( $q->exclusions as $d ) {
					$html .= '<div class="li muted">&ndash; ' . self::esc( $d ) . '</div>';
				}
				$html .= '</td>';
			}
			$html .= '</tr></table>';
		}

		if ( $q->notes ) {
			$html .= '<div class="k">Notes</div><div class="pre">' . self::esc( $q->notes ) . '</div>';
		}
		if ( $q->terms ) {
			$html .= '<div class="k">Terms &amp; conditions</div><div class="pre">' . self::esc( $q->terms ) . '</div>';
		}
		$bank = trim( (string) $studio->settings['bank_details'] );
		$upi  = trim( (string) $studio->settings['upi_id'] );
		if ( $bank || $upi ) {
			$html .= '<div class="k">Payment details</div><div class="pre">' . self::esc( $bank ) . ( $upi ? ( $bank ? "\n" : '' ) . 'UPI: ' . self::esc( $upi ) : '' ) . '</div>';
		}
		if ( $q->closing ) {
			$html .= '<div class="pre" style="margin-top:16px;font-style:italic">' . self::esc( $q->closing ) . '</div>';
		}
		if ( 'accepted' === $q->status && $q->accepted_name ) {
			$html .= '<div class="accept"><b>Accepted online</b> by ' . self::esc( $q->accepted_name ) . ( $q->responded_at ? ' on ' . Format::date( $q->responded_at ) . ', ' . date( 'h:i A', strtotime( $q->responded_at ) ) : '' ) . '.</div>';
		}
		$html .= '<div class="section" style="margin-top:34px"><table style="width:100%"><tr><td><div style="border-top:1px solid #9ca3af;display:inline-block;padding-top:4px;width:180px;text-align:center" class="muted">Client signature</div></td><td class="r"><div style="border-top:1px solid #9ca3af;display:inline-block;padding-top:4px;width:180px;text-align:center">For ' . self::esc( $studio->name ) . '</div></td></tr></table></div>';
		$html .= '</div>';
		return $html . '</body></html>';
	}

	public static function receipt_html( $p, $booking, $studio ) {
		$methods = Config::payment_methods();
		$client  = $booking->client;
		$sum     = $booking->payment;
		$inst    = '';
		if ( $p->installment_id ) {
			foreach ( $sum['installments'] as $i ) {
				if ( (int) $i->id === (int) $p->installment_id ) {
					$inst = $i->label;
				}
			}
		}
		$paid_upto = 0;
		foreach ( $booking->payments as $x ) {
			if ( 'received' === $x->status && ( $x->paid_on < $p->paid_on || ( $x->paid_on === $p->paid_on && $x->id <= $p->id ) ) ) {
				$paid_upto += $x->amount;
			}
		}
		$html  = '<html><head><meta charset="utf-8"><style>' . self::css() . '</style></head><body>';
		$html .= '<div class="footer">' . self::esc( $studio->name ) . ' · Receipt ' . self::esc( $p->receipt_no ) . ' · This is a computer-generated receipt.</div>';
		$html .= self::header( $studio, 'PAYMENT RECEIPT', array( 'Receipt No' => $p->receipt_no, 'Date' => Format::date( $p->paid_on ), 'Booking' => $booking->number ) );
		$html .= '<table class="box"><tr><td><div class="label">Received from</div><b style="font-size:12px">' . self::esc( $client->name ) . '</b><br>' . self::esc( Format::phone( $client->phone ) ) . ( $client->email ? '<br>' . self::esc( $client->email ) : '' ) . ( $client->address ? '<br>' . self::esc( $client->address ) : '' ) . '</td>';
		$html .= '<td><div class="label">For</div><b>' . self::esc( $booking->title ) . '</b>';
		foreach ( $booking->events as $e ) {
			$html .= '<br><span class="muted">' . self::esc( $e->name ) . ' · ' . Format::date( $e->event_date ) . '</span>';
		}
		$html .= '</td></tr></table>';
		$html .= '<div class="amount-box"><div class="label">Amount received</div><div class="big">' . self::money( $p->amount ) . '</div><div class="muted">' . self::esc( Format::words( $p->amount ) ) . '</div></div>';
		$html .= '<table class="items"><tbody>';
		$rows  = array(
			'Payment for'        => $inst ? $inst : 'Booking payment',
			'Payment method'     => $methods[ $p->method ],
			'Transaction ID'     => $p->transaction_ref ? $p->transaction_ref : '—',
			'Total package'      => self::money( $booking->total_amount ),
			'Total paid to date' => self::money( $paid_upto ),
			'Balance due'        => self::money( max( 0, $booking->total_amount - $paid_upto ) ),
		);
		foreach ( $rows as $k => $v ) {
			$html .= '<tr><td class="muted" style="width:40%">' . self::esc( $k ) . '</td><td><b>' . self::esc( $v ) . '</b></td></tr>';
		}
		$html .= '</tbody></table>';
		if ( $p->notes ) {
			$html .= '<div class="section"><h4>Notes</h4><div class="pre">' . self::esc( $p->notes ) . '</div></div>';
		}
		$html .= '<div class="section" style="margin-top:50px"><table style="width:100%"><tr><td>' . ( $booking->total_amount - $paid_upto <= 0.009 ? '<span class="stamp">FULLY PAID</span>' : '' ) . '</td><td class="r"><div style="border-top:1px solid #9ca3af;display:inline-block;padding-top:4px;width:180px;text-align:center">Authorised Signatory</div></td></tr></table></div>';
		return $html . '</body></html>';
	}

	private static function stream( $html, $filename, $download ) {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_die( 'PDF library missing. Please reinstall the plugin.' );
		}
		$opts = new \Dompdf\Options();
		$opts->set( 'isRemoteEnabled', false ); // Images are embedded as data URIs; no outbound fetches.
		$opts->set( 'defaultFont', 'DejaVu Sans' );
		$opts->set( 'tempDir', get_temp_dir() );
		$opts->set( 'fontCache', get_temp_dir() );
		$opts->setChroot( array( PCRM_DIR, ABSPATH, WP_CONTENT_DIR ) );
		$pdf = new \Dompdf\Dompdf( $opts );
		$pdf->loadHtml( $html, 'UTF-8' );
		$pdf->setPaper( 'A4' );
		$pdf->render();
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . $filename . '"' );
		header( 'Cache-Control: private, max-age=0' );
		echo $pdf->output(); // phpcs:ignore
	}

	public static function quotation( $q, $studio, $download = false ) {
		self::stream( self::quotation_html( $q, $studio ), $q->number . '.pdf', $download );
	}

	public static function receipt( $p, $booking, $studio, $download = false ) {
		self::stream( self::receipt_html( $p, $booking, $studio ), $p->receipt_no . '.pdf', $download );
	}
}
