<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

class Documents extends Base {

	public function register() {
		$this->route( '/documents', 'POST', array( $this, 'upload' ), array( 'clients.edit', 'bookings.edit' ) );
		$this->route( '/documents/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), array( 'clients.edit', 'bookings.edit' ) );
	}

	public static function list_for( array $opts ) {
		global $wpdb;
		$sid   = Tenant::studio_id();
		$conds = array();
		if ( ! empty( $opts['client_id'] ) ) {
			$conds[] = $wpdb->prepare( 'd.client_id=%d', $opts['client_id'] );
		}
		if ( ! empty( $opts['booking_ids'] ) ) {
			$conds[] = 'd.booking_id IN (' . implode( ',', array_map( 'intval', $opts['booking_ids'] ) ) . ')';
		}
		if ( ! $conds ) {
			return array();
		}
		$rows = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT d.*, u.display_name AS uploaded_by_name FROM ' . Db::t( 'documents' ) . ' d LEFT JOIN ' . $wpdb->users . ' u ON u.ID=d.uploaded_by WHERE d.studio_id=%d AND (' . implode( ' OR ', $conds ) . ') ORDER BY d.created_at DESC', $sid ) ) );
		foreach ( $rows as $r ) {
			$r->url = wp_get_attachment_url( $r->attachment_id );
		}
		return $rows;
	}

	public static function delete_for( array $where ) {
		global $wpdb;
		$col  = isset( $where['client_id'] ) ? 'client_id' : 'booking_id';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, attachment_id FROM ' . Db::t( 'documents' ) . " WHERE studio_id=%d AND $col=%d", Tenant::studio_id(), $where[ $col ] ) );
		foreach ( $rows as $r ) {
			wp_delete_attachment( (int) $r->attachment_id, true );
			Db::delete( 'documents', $r->id );
		}
	}

	public function upload( $req ) {
		$files = $req->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'validation_failed', 'Choose a file to upload.', array( 'status' => 422 ) );
		}
		$client_id  = (int) $req->get_param( 'client_id' );
		$booking_id = (int) $req->get_param( 'booking_id' );
		if ( ( $client_id && ! Db::exists( 'clients', $client_id ) ) || ( $booking_id && ! Db::exists( 'bookings', $booking_id ) ) || ( ! $client_id && ! $booking_id ) ) {
			return new \WP_Error( 'validation_failed', 'Attach the file to a client or booking.', array( 'status' => 422 ) );
		}
		if ( $files['file']['size'] > 15 * MB_IN_BYTES ) {
			return new \WP_Error( 'validation_failed', 'Files must be 15 MB or smaller.', array( 'status' => 422 ) );
		}
		$allowed = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'pdf'      => 'application/pdf',
			'doc'      => 'application/msword',
			'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'      => 'application/vnd.ms-excel',
			'xlsx'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'txt'      => 'text/plain',
		);
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$_FILES['file'] = $files['file'];
		$aid            = media_handle_upload(
			'file',
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => $allowed,
			)
		);
		if ( is_wp_error( $aid ) ) {
			return new \WP_Error( 'upload_failed', $aid->get_error_message(), array( 'status' => 422 ) );
		}
		update_post_meta( $aid, '_pcrm_studio', $this->sid() );
		$id = Db::insert(
			'documents',
			array(
				'client_id'     => $client_id ? $client_id : null,
				'booking_id'    => $booking_id ? $booking_id : null,
				'attachment_id' => $aid,
				'name'          => sanitize_file_name( $files['file']['name'] ),
				'mime'          => get_post_mime_type( $aid ),
				'size'          => (int) $files['file']['size'],
				'uploaded_by'   => get_current_user_id(),
			)
		);
		Db::log( 'document', 'Document uploaded: ' . sanitize_file_name( $files['file']['name'] ), array( 'client_id' => $client_id ? $client_id : null, 'booking_id' => $booking_id ? $booking_id : null ) );
		$doc      = Db::cast( Db::find( 'documents', $id ) );
		$doc->url = wp_get_attachment_url( $aid );
		return $this->ok( $doc, 201 );
	}

	public function destroy( $req ) {
		$d = Db::find( 'documents', (int) $req['id'] );
		if ( ! $d ) {
			return $this->not_found( 'Document' );
		}
		wp_delete_attachment( (int) $d->attachment_id, true );
		Db::delete( 'documents', $d->id );
		return $this->ok( array( 'deleted' => true ) );
	}
}
