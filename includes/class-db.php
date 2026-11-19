<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Tenant-scoped database helpers. Every read and write is limited to the
 * current studio unless an explicit studio id is passed (super admin / seeder).
 */
class Db {

	public static function t( $name ) {
		return Installer::table( $name );
	}

	/**
	 * The studio's own time zone (default Asia/Kolkata), so "today" matches the
	 * studio even when the WordPress site is left on UTC.
	 */
	public static function tz() {
		static $cache = array();
		$tz  = null;
		$sid = class_exists( 'PCRM\\Tenant' ) ? Tenant::studio_id() : 0;
		if ( isset( $cache[ $sid ] ) ) {
			return $cache[ $sid ];
		}
		$name = $sid ? Tenant::setting( 'timezone' ) : null;
		if ( ! $name ) {
			$site = wp_timezone_string();
			$name = ( 'UTC' === $site || '+00:00' === $site ) ? 'Asia/Kolkata' : $site;
		}
		try {
			$tz = new \DateTimeZone( $name );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'Asia/Kolkata' );
		}
		return $cache[ $sid ] = $tz;
	}

	public static function date( $format ) {
		return ( new \DateTime( 'now', self::tz() ) )->format( $format );
	}

	/** Local "wall clock" timestamp, like current_time( 'timestamp' ). */
	public static function ts() {
		return time() + self::tz()->getOffset( new \DateTime( 'now' ) );
	}

	public static function now() {
		return self::date( 'Y-m-d H:i:s' );
	}

	public static function today() {
		return self::date( 'Y-m-d' );
	}

	public static function sid( $studio_id = null ) {
		return null === $studio_id ? Tenant::studio_id() : (int) $studio_id;
	}

	public static function find( $table, $id, $studio_id = null ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( $table ) . ' WHERE id=%d AND studio_id=%d', $id, self::sid( $studio_id ) ) );
	}

	public static function exists( $table, $id, $studio_id = null ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( $table ) . ' WHERE id=%d AND studio_id=%d', $id, self::sid( $studio_id ) ) );
	}

	public static function insert( $table, array $data, $studio_id = null ) {
		global $wpdb;
		$data['studio_id'] = self::sid( $studio_id );
		$cols              = self::columns( $table );
		if ( in_array( 'created_at', $cols, true ) && empty( $data['created_at'] ) ) {
			$data['created_at'] = self::now();
		}
		if ( in_array( 'updated_at', $cols, true ) ) {
			$data['updated_at'] = self::now();
		}
		$data = array_intersect_key( $data, array_flip( $cols ) );
		$ok   = $wpdb->insert( self::t( $table ), $data );
		if ( false === $ok ) {
			return new \WP_Error( 'db_error', 'Could not save. ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( $table, $id, array $data, $studio_id = null ) {
		global $wpdb;
		$cols = self::columns( $table );
		if ( in_array( 'updated_at', $cols, true ) ) {
			$data['updated_at'] = self::now();
		}
		unset( $data['id'], $data['studio_id'] );
		$data = array_intersect_key( $data, array_flip( $cols ) );
		if ( ! $data ) {
			return true;
		}
		$ok = $wpdb->update( self::t( $table ), $data, array( 'id' => (int) $id, 'studio_id' => self::sid( $studio_id ) ) );
		if ( false === $ok ) {
			return new \WP_Error( 'db_error', 'Could not save. ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
		return true;
	}

	public static function delete( $table, $id, $studio_id = null ) {
		global $wpdb;
		return $wpdb->delete( self::t( $table ), array( 'id' => (int) $id, 'studio_id' => self::sid( $studio_id ) ) );
	}

	public static function delete_where( $table, array $where, $studio_id = null ) {
		global $wpdb;
		$where['studio_id'] = self::sid( $studio_id );
		return $wpdb->delete( self::t( $table ), $where );
	}

	public static function columns( $table ) {
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			global $wpdb;
			$cache[ $table ] = $wpdb->get_col( 'SHOW COLUMNS FROM ' . self::t( $table ) );
		}
		return $cache[ $table ];
	}

	public static function begin() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	public static function commit() {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	public static function rollback() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Next document number like QT-2026-0007, per studio and prefix.
	 */
	public static function next_number( $table, $prefix, $studio_id = null ) {
		global $wpdb;
		$sid  = self::sid( $studio_id );
		$year = self::date( 'Y' );
		$like = $wpdb->esc_like( $prefix . '-' . $year . '-' ) . '%';
		$col  = 'payments' === $table ? 'receipt_no' : 'number';
		$last = $wpdb->get_var( $wpdb->prepare( 'SELECT ' . $col . ' FROM ' . self::t( $table ) . " WHERE studio_id=%d AND $col LIKE %s ORDER BY id DESC LIMIT 1", $sid, $like ) );
		$n    = $last ? ( (int) substr( $last, strrpos( $last, '-' ) + 1 ) ) + 1 : 1;
		return sprintf( '%s-%s-%04d', $prefix, $year, $n );
	}

	/**
	 * Casts numeric-looking columns on a row object for clean JSON output.
	 */
	public static function cast( $row ) {
		if ( ! $row ) {
			return $row;
		}
		$ints   = array( 'id', 'studio_id', 'client_id', 'lead_id', 'booking_id', 'quotation_id', 'assigned_to', 'member_id', 'user_id', 'service_id', 'installment_id', 'booking_event_id', 'created_by', 'position', 'sort', 'attachment_id', 'size', 'is_active', 'uploaded_by', 'owner_user_id', 'progress', 'booking_service_id', 'template_id', 'days_after_event', 'view_count', 'failed_attempts', 'updated_by', 'on_site', 'is_enabled', 'pin_version', 'payment_id', 'crew_member_id', 'schedule_id', 'assignment_id', 'crew_expense_id', 'conflict_override', 'is_core', 'is_required', 'form_id', 'duplicate_lead_id', 'handled_by', 'enquiry_submission_id', 'assign_member_id', 'entity_id', 'updated_by' );
		$floats = array( 'budget', 'price', 'subtotal', 'discount_value', 'discount_amount', 'gst_rate', 'gst_amount', 'total', 'qty', 'rate', 'amount', 'total_amount', 'paid', 'pending', 'paid_amount', 'expenses', 'package_amount', 'daily_rate', 'agreed_rate', 'advance_paid', 'remaining' );
		foreach ( $row as $k => $v ) {
			if ( null === $v ) {
				continue;
			}
			if ( in_array( $k, $ints, true ) ) {
				$row->$k = (int) $v;
			} elseif ( in_array( $k, $floats, true ) ) {
				$row->$k = (float) $v;
			}
		}
		return $row;
	}

	public static function cast_all( $rows ) {
		return array_map( array( __CLASS__, 'cast' ), $rows ? $rows : array() );
	}

	/**
	 * Logs an activity on the timeline.
	 */
	public static function log( $type, $message, array $refs = array(), array $meta = array(), $studio_id = null ) {
		return self::insert(
			'activities',
			array(
				'type'       => $type,
				'message'    => $message,
				'lead_id'    => isset( $refs['lead_id'] ) ? $refs['lead_id'] : null,
				'client_id'  => isset( $refs['client_id'] ) ? $refs['client_id'] : null,
				'booking_id' => isset( $refs['booking_id'] ) ? $refs['booking_id'] : null,
				'meta'       => $meta ? wp_json_encode( $meta ) : null,
				'user_id'    => get_current_user_id() ? get_current_user_id() : null,
			),
			$studio_id
		);
	}

	/**
	 * Writes the audit log (crew, schedules, crew payments, enquiry form).
	 */
	public static function audit( $entity, $entity_id, $action, $summary, $changes = null, $studio_id = null ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return self::insert(
			'audit_logs',
			array(
				'entity'    => $entity,
				'entity_id' => $entity_id ? (int) $entity_id : null,
				'action'    => $action,
				'summary'   => mb_substr( $summary, 0, 500 ),
				'changes'   => $changes ? wp_json_encode( $changes ) : null,
				'user_id'   => get_current_user_id() ? get_current_user_id() : null,
				'ip_hash'   => $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ) : null,
			),
			$studio_id
		);
	}
}
