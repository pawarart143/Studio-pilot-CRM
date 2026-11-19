<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

class Format {

	/**
	 * Indian digit grouping: 1234567.5 → 12,34,567.50 (decimals only when needed).
	 */
	public static function inr( $n, $decimals = null ) {
		$n    = (float) $n;
		$neg  = $n < 0;
		$n    = abs( $n );
		$dec  = null === $decimals ? ( floor( $n ) == $n ? 0 : 2 ) : $decimals; // phpcs:ignore
		$str  = number_format( $n, $dec, '.', '' );
		$int  = strstr( $str, '.', true );
		$frac = strstr( $str, '.' );
		$int  = false === $int ? $str : $int;
		$frac = false === $frac ? '' : $frac;
		if ( strlen( $int ) > 3 ) {
			$last = substr( $int, -3 );
			$rest = substr( $int, 0, -3 );
			$rest = preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', $rest );
			$int  = $rest . ',' . $last;
		}
		return ( $neg ? '-' : '' ) . $int . $frac;
	}

	/**
	 * +919876543210 → +91 98765 43210 (other numbers are returned as stored).
	 */
	public static function phone( $p ) {
		$p = (string) $p;
		if ( preg_match( '/^\+91(\d{5})(\d{5})$/', $p, $m ) ) {
			return '+91 ' . $m[1] . ' ' . $m[2];
		}
		return $p;
	}

	public static function date( $d ) {
		return $d ? date( 'd-m-Y', strtotime( $d ) ) : '';
	}

	public static function time( $t ) {
		return $t ? date( 'h:i A', strtotime( $t ) ) : '';
	}

	/**
	 * Amount in words (Indian system) for receipts.
	 */
	public static function words( $n ) {
		$n     = (int) round( $n );
		if ( 0 === $n ) {
			return 'Zero Rupees Only';
		}
		$ones  = array( '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen' );
		$tens  = array( '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety' );
		$two   = function ( $x ) use ( $ones, $tens ) {
			return $x < 20 ? $ones[ $x ] : trim( $tens[ intval( $x / 10 ) ] . ' ' . $ones[ $x % 10 ] );
		};
		$three = function ( $x ) use ( $two, $ones ) {
			$h = intval( $x / 100 );
			$r = $x % 100;
			return trim( ( $h ? $ones[ $h ] . ' Hundred ' : '' ) . ( $r ? $two( $r ) : '' ) );
		};
		$parts = array();
		$crore = intval( $n / 10000000 );
		$n    %= 10000000;
		$lakh  = intval( $n / 100000 );
		$n    %= 100000;
		$thou  = intval( $n / 1000 );
		$n    %= 1000;
		if ( $crore ) {
			$parts[] = $three( $crore ) . ' Crore';
		}
		if ( $lakh ) {
			$parts[] = $two( $lakh ) . ' Lakh';
		}
		if ( $thou ) {
			$parts[] = $two( $thou ) . ' Thousand';
		}
		if ( $n ) {
			$parts[] = $three( $n );
		}
		return implode( ' ', $parts ) . ' Rupees Only';
	}
}
