<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Schema-based validation and sanitising for REST input.
 *
 * Field rule keys: type (string|text|email|phone|date|datetime|time|money|int|decimal|bool|enum|ref),
 * required, max, options (enum keys), table (ref table, must belong to studio), label.
 */
class Validator {

	public static function validate( array $input, array $schema, $partial = false ) {
		$out    = array();
		$errors = array();

		foreach ( $schema as $field => $rule ) {
			$label   = isset( $rule['label'] ) ? $rule['label'] : ucwords( str_replace( '_', ' ', $field ) );
			$present = array_key_exists( $field, $input );
			if ( $partial && ! $present ) {
				continue;
			}
			$raw = $present ? $input[ $field ] : null;
			if ( is_string( $raw ) ) {
				$raw = trim( $raw );
			}
			$empty = ( null === $raw || '' === $raw || ( is_array( $raw ) && ! $raw ) );

			if ( $empty ) {
				if ( ! empty( $rule['required'] ) ) {
					$errors[ $field ] = $label . ' is required.';
				} elseif ( $present || ! $partial ) {
					if ( array_key_exists( 'default', $rule ) ) {
						$out[ $field ] = $rule['default'];
					} elseif ( $present ) {
						$out[ $field ] = null;
					}
				}
				continue;
			}

			$type  = isset( $rule['type'] ) ? $rule['type'] : 'string';
			$value = null;
			$err   = null;

			switch ( $type ) {
				case 'string':
					$value = sanitize_text_field( (string) $raw );
					$max   = isset( $rule['max'] ) ? $rule['max'] : 190;
					if ( mb_strlen( $value ) > $max ) {
						$err = "$label must be at most $max characters.";
					}
					break;
				case 'text':
					$value = sanitize_textarea_field( (string) $raw );
					if ( mb_strlen( $value ) > 20000 ) {
						$err = "$label is too long.";
					}
					break;
				case 'email':
					$value = sanitize_email( (string) $raw );
					if ( ! is_email( $value ) ) {
						$err = "$label is not a valid email address.";
					}
					break;
				case 'phone':
					$value = self::phone( (string) $raw );
					if ( ! $value ) {
						$err = "$label is not a valid phone number.";
					}
					break;
				case 'date':
					$value = self::date( (string) $raw );
					if ( ! $value ) {
						$err = "$label must be a valid date.";
					}
					break;
				case 'datetime':
					$value = self::datetime( (string) $raw );
					if ( ! $value ) {
						$err = "$label must be a valid date and time.";
					}
					break;
				case 'time':
					if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', (string) $raw ) ) {
						$value = strlen( $raw ) === 5 ? $raw . ':00' : $raw;
					} else {
						$err = "$label must be a valid time (HH:MM).";
					}
					break;
				case 'money':
				case 'decimal':
					if ( ! is_numeric( $raw ) ) {
						$err = "$label must be a number.";
					} else {
						$value = round( (float) $raw, 2 );
						$min   = isset( $rule['min'] ) ? $rule['min'] : 0;
						if ( $value < $min ) {
							$err = "$label cannot be less than $min.";
						}
						if ( isset( $rule['maxval'] ) && $value > $rule['maxval'] ) {
							$err = "$label cannot be more than {$rule['maxval']}.";
						}
						if ( $value > 9999999999 ) {
							$err = "$label is too large.";
						}
					}
					break;
				case 'int':
					if ( ! is_numeric( $raw ) || (int) $raw != $raw ) { // phpcs:ignore
						$err = "$label must be a whole number.";
					} else {
						$value = (int) $raw;
					}
					break;
				case 'bool':
					$value = filter_var( $raw, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
					break;
				case 'enum':
					$value = (string) $raw;
					if ( ! array_key_exists( $value, $rule['options'] ) ) {
						$err = "$label has an invalid value.";
					}
					break;
				case 'ref':
					$value = (int) $raw;
					if ( $value <= 0 || ! Db::exists( $rule['table'], $value ) ) {
						$err = "$label was not found.";
					}
					break;
			}

			if ( $err ) {
				$errors[ $field ] = $err;
			} else {
				$out[ $field ] = $value;
			}
		}

		if ( $errors ) {
			return new \WP_Error(
				'validation_failed',
				reset( $errors ),
				array(
					'status' => 422,
					'errors' => $errors,
				)
			);
		}
		return $out;
	}

	/**
	 * Normalises a phone number. 10-digit Indian numbers get +91.
	 */
	public static function phone( $raw ) {
		$raw    = trim( $raw );
		$plus   = strpos( $raw, '+' ) === 0;
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( ! $plus && strlen( $digits ) === 11 && $digits[0] === '0' ) {
			$digits = substr( $digits, 1 );
		}
		if ( ! $plus && strlen( $digits ) === 10 ) {
			return preg_match( '/^[6-9]/', $digits ) ? '+91' . $digits : null;
		}
		if ( ! $plus && strlen( $digits ) === 12 && substr( $digits, 0, 2 ) === '91' ) {
			return '+' . $digits;
		}
		if ( $plus && strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) {
			if ( substr( $digits, 0, 2 ) === '91' && strlen( $digits ) !== 12 ) {
				return null;
			}
			return '+' . $digits;
		}
		return null;
	}

	/**
	 * Accepts YYYY-MM-DD or DD-MM-YYYY, returns YYYY-MM-DD.
	 */
	public static function date( $raw ) {
		$raw = substr( trim( $raw ), 0, 10 );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '/^(\d{2})[-\/](\d{2})[-\/](\d{4})$/', $raw, $m ) ) {
			list( , $d, $mo, $y ) = $m;
		} else {
			return null;
		}
		return checkdate( (int) $mo, (int) $d, (int) $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}

	public static function datetime( $raw ) {
		$raw = trim( str_replace( 'T', ' ', $raw ) );
		if ( preg_match( '/^(\S+)\s+(\d{2}:\d{2})(:\d{2})?/', $raw, $m ) ) {
			$d = self::date( $m[1] );
			return $d ? $d . ' ' . $m[2] . ( isset( $m[3] ) && $m[3] ? $m[3] : ':00' ) : null;
		}
		$d = self::date( $raw );
		return $d ? $d . ' 00:00:00' : null;
	}
}
