<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * One place that turns a loaded quotation into what the client sees, so the
 * public page and the PDF always show the same numbers and sections (v0.6.0).
 */
class Quote_View {

	public static function accent( $studio ) {
		$c = isset( $studio->settings['quote_accent'] ) ? (string) $studio->settings['quote_accent'] : '';
		return preg_match( '/^#[0-9A-Fa-f]{6}$/', $c ) ? strtoupper( $c ) : '#F3724C';
	}

	/** A darker shade of the accent for text on white (keeps contrast readable). */
	public static function accent_ink( $hex ) {
		$r = hexdec( substr( $hex, 1, 2 ) );
		$g = hexdec( substr( $hex, 3, 2 ) );
		$b = hexdec( substr( $hex, 5, 2 ) );
		$f = 0.72;
		return sprintf( '#%02X%02X%02X', (int) ( $r * $f ), (int) ( $g * $f ), (int) ( $b * $f ) );
	}

	/** A very light tint of the accent for backgrounds. */
	public static function accent_soft( $hex, $mix = 0.9 ) {
		$out = '#';
		for ( $i = 1; $i <= 5; $i += 2 ) {
			$c    = hexdec( substr( $hex, $i, 2 ) );
			$out .= sprintf( '%02X', (int) round( $c + ( 255 - $c ) * $mix ) );
		}
		return $out;
	}

	public static function qty( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
	}

	public static function money( $n ) {
		return '₹' . Format::inr( $n, 2 );
	}

	/** Items grouped as Packages, Services, Add-ons (empty groups dropped). */
	public static function groups( $q ) {
		$kinds = Config::quote_item_kinds();
		$out   = array();
		foreach ( $kinds as $k => $label ) {
			$out[ $k ] = array(
				'label' => 'package' === $k ? 'Packages' : ( 'addon' === $k ? 'Add-ons' : 'Services' ),
				'items' => array(),
				'total' => 0,
			);
		}
		foreach ( $q->items as $it ) {
			$k = isset( $it->kind ) && isset( $out[ $it->kind ] ) ? $it->kind : 'service';
			$out[ $k ]['items'][] = $it;
			$out[ $k ]['total']  += (float) $it->amount;
		}
		return array_filter(
			$out,
			function ( $g ) {
				return ! empty( $g['items'] );
			}
		);
	}

	/** GST lines: CGST + SGST within the state, IGST across states. */
	public static function gst_lines( $q ) {
		if ( $q->gst_rate <= 0 ) {
			return array();
		}
		$rate = (float) $q->gst_rate;
		if ( 'inter' === $q->gst_type ) {
			return array( array( 'IGST (' . ( $rate + 0 ) . '%)', (float) $q->gst_amount ) );
		}
		$half = round( $q->gst_amount / 2, 2 );
		return array(
			array( 'CGST (' . ( $rate / 2 ) . '%)', $half ),
			array( 'SGST (' . ( $rate / 2 ) . '%)', round( $q->gst_amount - $half, 2 ) ),
		);
	}

	/** "Traditional Photographer × 2, Cinematographer" */
	public static function coverage_text( $coverage ) {
		$roles = Config::quote_coverage_roles();
		$parts = array();
		foreach ( (array) $coverage as $role => $n ) {
			if ( isset( $roles[ $role ] ) && $n > 0 ) {
				$parts[] = $roles[ $role ] . ( $n > 1 ? ' × ' . (int) $n : '' );
			}
		}
		return $parts;
	}

	public static function event_when( $e ) {
		$bits = array();
		if ( $e->event_date ) {
			$bits[] = date( 'D', strtotime( $e->event_date ) ) . ', ' . Format::date( $e->event_date );
		}
		if ( $e->start_time ) {
			$bits[] = Format::time( $e->start_time ) . ( $e->end_time ? ' – ' . Format::time( $e->end_time ) : '' );
		}
		return implode( ' · ', $bits );
	}

	public static function event_where( $e ) {
		return trim( $e->venue . ( $e->city ? ( $e->venue ? ', ' : '' ) . $e->city : '' ) );
	}

	/** Events to show; quotations from before v0.6.0 may only have the single date/venue. */
	public static function events( $q ) {
		if ( ! empty( $q->events ) ) {
			return $q->events;
		}
		if ( $q->event_date || $q->venue ) {
			$types = Config::event_types();
			return array(
				(object) array(
					'name'       => $q->event_type && isset( $types[ $q->event_type ] ) ? $types[ $q->event_type ] : 'Event',
					'event_type' => $q->event_type,
					'event_date' => $q->event_date,
					'start_time' => null,
					'end_time'   => null,
					'venue'      => $q->venue,
					'city'       => '',
					'coverage'   => array(),
					'notes'      => '',
				),
			);
		}
		return array();
	}

	public static function is_expired( $q ) {
		return 'expired' === $q->status || ( $q->valid_until && $q->valid_until < Db::today() && in_array( $q->status, array( 'sent', 'viewed', 'draft' ), true ) );
	}

	public static function wa_number( $studio ) {
		$d = preg_replace( '/\D/', '', (string) $studio->phone );
		if ( 10 === strlen( $d ) ) {
			$d = '91' . $d;
		}
		return strlen( $d ) >= 11 ? $d : '';
	}
}
