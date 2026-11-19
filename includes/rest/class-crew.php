<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Crew as CrewModel;
use PCRM\Db;
use PCRM\Tenant;
use PCRM\Validator;
use PCRM\Whatsapp as WA;

defined( 'ABSPATH' ) || exit;

/**
 * Crew Scheduling (Operations): crew directory, event crew schedules,
 * conflict checks and crew payments.
 *
 * Crew members live in pcrm_crew_members and are fully separate from the Team
 * module (pcrm_members). Money (rates, payments) is only visible to roles with
 * crew.payments; everyone else gets the records without amounts.
 */
class Crew extends Base {

	public function register() {
		// Directory.
		$this->route( '/crew', 'GET', array( $this, 'index' ), 'crew.view' );
		$this->route( '/crew', 'POST', array( $this, 'create' ), 'crew.edit' );
		$this->route( '/crew/(?P<id>\d+)', 'GET', array( $this, 'show' ), 'crew.view' );
		$this->route( '/crew/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'crew.edit' );
		$this->route( '/crew/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'crew.edit' );
		$this->route( '/crew/(?P<id>\d+)/photo', 'POST', array( $this, 'photo' ), 'crew.edit' );
		$this->route( '/crew/(?P<id>\d+)/photo', 'DELETE', array( $this, 'remove_photo' ), 'crew.edit' );
		// Schedules.
		$this->route( '/crew-schedules', 'GET', array( $this, 'schedules' ), 'crew.view' );
		$this->route( '/crew-schedules', 'POST', array( $this, 'create_schedule' ), 'crew.edit' );
		$this->route( '/crew-schedules/conflicts', 'POST', array( $this, 'check_conflicts' ), 'crew.edit' );
		$this->route( '/crew-schedules/(?P<id>\d+)', 'GET', array( $this, 'show_schedule' ), 'crew.view' );
		$this->route( '/crew-schedules/(?P<id>\d+)', 'PUT', array( $this, 'update_schedule' ), 'crew.edit' );
		$this->route( '/crew-schedules/(?P<id>\d+)', 'DELETE', array( $this, 'destroy_schedule' ), 'crew.edit' );
		$this->route( '/crew-assignments/(?P<id>\d+)/status', 'POST', array( $this, 'set_status' ), 'crew.edit' );
		$this->route( '/crew-assignments/(?P<id>\d+)/reassign', 'POST', array( $this, 'reassign' ), 'crew.edit' );
		// Crew payments (separate from client payments).
		$this->route( '/crew-payments', 'GET', array( $this, 'payments' ), 'crew.payments' );
		$this->route( '/crew-payments', 'POST', array( $this, 'create_expense' ), 'crew.payments' );
		$this->route( '/crew-payments/(?P<id>\d+)', 'PUT', array( $this, 'update_expense' ), 'crew.payments' );
		$this->route( '/crew-payments/(?P<id>\d+)', 'DELETE', array( $this, 'destroy_expense' ), 'crew.payments' );
		$this->route( '/crew-payments/(?P<id>\d+)/pay', 'POST', array( $this, 'pay' ), 'crew.payments' );
		$this->route( '/crew-payments/entries/(?P<id>\d+)', 'DELETE', array( $this, 'destroy_payment' ), 'crew.payments' );
	}

	private function money() {
		return Tenant::can( 'crew.payments' );
	}

	/* =========================================================
	 * Crew directory
	 * ======================================================= */

	private function schema() {
		return array(
			'name'           => array( 'type' => 'string', 'required' => true, 'label' => 'Full name' ),
			'phone'          => array( 'type' => 'phone', 'required' => true, 'label' => 'Phone number' ),
			'whatsapp'       => array( 'type' => 'phone', 'label' => 'WhatsApp number' ),
			'email'          => array( 'type' => 'email' ),
			'category'       => array( 'type' => 'enum', 'options' => Config::crew_categories(), 'required' => true, 'label' => 'Crew category' ),
			'specialization' => array( 'type' => 'string', 'max' => 190 ),
			'city'           => array( 'type' => 'string', 'max' => 100 ),
			'daily_rate'     => array( 'type' => 'money', 'label' => 'Daily rate' ),
			'availability'   => array( 'type' => 'enum', 'options' => Config::crew_availability(), 'default' => 'available' ),
			'notes'          => array( 'type' => 'text' ),
		);
	}

	private function present( $c ) {
		$c = Db::cast( $c );
		if ( ! $this->money() ) {
			unset( $c->daily_rate );
		}
		return $c;
	}

	public function index( $req ) {
		global $wpdb;
		$sid = $this->sid();
		$w   = array( $wpdb->prepare( 'c.studio_id=%d', $sid ), 'c.deleted_at IS NULL' );
		$q   = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$l     = $this->like( $q );
			$digit = preg_replace( '/\D/', '', $q );
			$w[]   = $wpdb->prepare( '(c.name LIKE %s OR c.city LIKE %s OR c.specialization LIKE %s OR c.email LIKE %s' . ( strlen( $digit ) >= 4 ? $wpdb->prepare( ' OR c.phone LIKE %s OR c.whatsapp LIKE %s', '%' . $digit . '%', '%' . $digit . '%' ) : '' ) . ')', $l, $l, $l, $l );
		}
		$cat = (string) $req->get_param( 'category' );
		if ( $cat && isset( Config::crew_categories()[ $cat ] ) ) {
			$w[] = $wpdb->prepare( 'c.category=%s', $cat );
		}
		$av = (string) $req->get_param( 'availability' );
		if ( $av && isset( Config::crew_availability()[ $av ] ) ) {
			$w[] = $wpdb->prepare( 'c.availability=%s', $av );
		}
		$city = trim( (string) $req->get_param( 'city' ) );
		if ( '' !== $city ) {
			$w[] = $wpdb->prepare( 'c.city=%s', $city );
		}
		$where = implode( ' AND ', $w );
		$skip  = "'" . implode( "','", Config::crew_inactive_statuses() ) . "'";
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.*,
					(SELECT COUNT(*) FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a WHERE a.studio_id=c.studio_id AND a.crew_member_id=c.id) AS total_assignments,
					(SELECT COUNT(*) FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a JOIN ' . Db::t( 'crew_schedules' ) . " s ON s.id=a.schedule_id WHERE a.studio_id=c.studio_id AND a.crew_member_id=c.id AND a.status NOT IN ($skip) AND s.event_date >= %s) AS upcoming
				FROM " . Db::t( 'crew_members' ) . " c WHERE $where ORDER BY c.name LIMIT 1000",
				Db::today()
			)
		);
		$out = array();
		foreach ( $rows as $r ) {
			$r                    = $this->present( $r );
			$r->total_assignments = (int) $r->total_assignments;
			$r->upcoming          = (int) $r->upcoming;
			$out[]                = $r;
		}
		$cities = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT city FROM ' . Db::t( 'crew_members' ) . " WHERE studio_id=%d AND deleted_at IS NULL AND city IS NOT NULL AND city<>'' ORDER BY city", $sid ) );
		return $this->ok( array( 'items' => $out, 'cities' => $cities ) );
	}

	private function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'crew_members' ) . ' WHERE id=%d AND studio_id=%d AND deleted_at IS NULL', $id, $this->sid() ) );
	}

	private function phone_taken( $phone, $exclude = 0 ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, name FROM ' . Db::t( 'crew_members' ) . ' WHERE studio_id=%d AND deleted_at IS NULL AND phone=%s AND id<>%d LIMIT 1', $this->sid(), $phone, $exclude ) );
	}

	public function create( $req ) {
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! $this->money() ) {
			unset( $data['daily_rate'] );
		}
		$dup = $this->phone_taken( $data['phone'] );
		if ( $dup ) {
			return new \WP_Error( 'duplicate', sprintf( '%s already uses this phone number in your crew directory.', $dup->name ), array( 'status' => 409, 'errors' => array( 'phone' => 'Already in your crew directory.' ) ) );
		}
		if ( empty( $data['whatsapp'] ) ) {
			$data['whatsapp'] = $data['phone'];
		}
		$data['created_by'] = get_current_user_id();
		$id                 = Db::insert( 'crew_members', $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Db::audit( 'crew_member', $id, 'created', 'Crew member added: ' . $data['name'] );
		return $this->ok( $this->present( $this->find( $id ) ), 201 );
	}

	public function update( $req ) {
		$id  = (int) $req['id'];
		$old = $this->find( $id );
		if ( ! $old ) {
			return $this->not_found( 'Crew member' );
		}
		$data = $this->validate( $req, $this->schema(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! $this->money() ) {
			unset( $data['daily_rate'] );
		}
		if ( ! empty( $data['phone'] ) ) {
			$dup = $this->phone_taken( $data['phone'], $id );
			if ( $dup ) {
				return new \WP_Error( 'duplicate', sprintf( '%s already uses this phone number in your crew directory.', $dup->name ), array( 'status' => 409, 'errors' => array( 'phone' => 'Already in your crew directory.' ) ) );
			}
		}
		$r = Db::update( 'crew_members', $id, $data );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$changes = array();
		foreach ( $data as $k => $v ) {
			if ( (string) $old->$k !== (string) $v && 'notes' !== $k ) {
				$changes[ $k ] = array( 'from' => $old->$k, 'to' => $v );
			}
		}
		if ( $changes ) {
			Db::audit( 'crew_member', $id, 'updated', 'Crew member updated: ' . $old->name, $changes );
		}
		return $this->ok( $this->present( $this->find( $id ) ) );
	}

	/**
	 * Removes a crew member from the directory. Past schedules and payments keep
	 * their name. Upcoming active assignments must be handled first, or are
	 * cancelled when "cancel_upcoming" is sent.
	 */
	public function destroy( $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		$c  = $this->find( $id );
		if ( ! $c ) {
			return $this->not_found( 'Crew member' );
		}
		$skip     = "'" . implode( "','", Config::crew_inactive_statuses() ) . "'";
		$upcoming = $wpdb->get_col( $wpdb->prepare( 'SELECT a.id FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a JOIN ' . Db::t( 'crew_schedules' ) . " s ON s.id=a.schedule_id WHERE a.studio_id=%d AND a.crew_member_id=%d AND a.status NOT IN ($skip) AND s.event_date >= %s", $this->sid(), $id, Db::today() ) );
		$b        = $this->body( $req );
		if ( $upcoming && empty( $b['cancel_upcoming'] ) && empty( $req['cancel_upcoming'] ) ) {
			return new \WP_Error( 'has_upcoming', sprintf( '%s has %d upcoming assignment%s. Reassign them first, or confirm to cancel them.', $c->name, count( $upcoming ), 1 === count( $upcoming ) ? '' : 's' ), array( 'status' => 409, 'upcoming' => count( $upcoming ) ) );
		}
		foreach ( $upcoming as $aid ) {
			Db::update( 'crew_schedule_assignments', (int) $aid, array( 'status' => 'cancelled', 'updated_by' => get_current_user_id() ) );
			CrewModel::sync_expense( (int) $aid );
		}
		Db::update( 'crew_members', $id, array( 'deleted_at' => Db::now() ) );
		Db::audit( 'crew_member', $id, 'deleted', 'Crew member deleted: ' . $c->name . ( $upcoming ? ' (' . count( $upcoming ) . ' upcoming assignments cancelled)' : '' ) );
		return $this->ok( array( 'deleted' => true ) );
	}

	public function photo( $req ) {
		$id = (int) $req['id'];
		if ( ! $this->find( $id ) ) {
			return $this->not_found( 'Crew member' );
		}
		$files = $req->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'validation_failed', 'Choose a photo to upload.', array( 'status' => 422 ) );
		}
		if ( (int) $files['file']['size'] > 3 * MB_IN_BYTES ) {
			return new \WP_Error( 'validation_failed', 'Photo must be smaller than 3 MB.', array( 'status' => 422 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$_FILES['file'] = $files['file'];
		$aid            = media_handle_upload( 'file', 0, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
		if ( is_wp_error( $aid ) ) {
			return new \WP_Error( 'upload_failed', $aid->get_error_message(), array( 'status' => 422 ) );
		}
		$src = wp_get_attachment_image_src( $aid, 'thumbnail' );
		$url = $src ? $src[0] : wp_get_attachment_url( $aid );
		Db::update( 'crew_members', $id, array( 'photo_url' => $url ) );
		return $this->ok( array( 'photo_url' => $url ) );
	}

	public function remove_photo( $req ) {
		$id = (int) $req['id'];
		if ( ! $this->find( $id ) ) {
			return $this->not_found( 'Crew member' );
		}
		Db::update( 'crew_members', $id, array( 'photo_url' => null ) );
		return $this->ok( array( 'photo_url' => null ) );
	}

	/** Profile with assignment history and (for money roles) payments. */
	public function show( $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		$c  = $this->find( $id );
		if ( ! $c ) {
			return $this->not_found( 'Crew member' );
		}
		$sid  = $this->sid();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, s.event_name, s.client_name, s.event_date, s.start_time, s.reporting_time, s.end_time, s.venue, s.city, s.booking_id, b.number AS booking_number FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a JOIN ' . Db::t( 'crew_schedules' ) . ' s ON s.id=a.schedule_id AND s.studio_id=a.studio_id LEFT JOIN ' . Db::t( 'bookings' ) . ' b ON b.id=s.booking_id AND b.studio_id=s.studio_id WHERE a.studio_id=%d AND a.crew_member_id=%d ORDER BY s.event_date DESC, s.start_time DESC LIMIT 500', $sid, $id ) );
		$hist = array();
		foreach ( $rows as $r ) {
			$r = Db::cast( $r );
			if ( ! $this->money() ) {
				unset( $r->rate );
			}
			$hist[] = $r;
		}
		$out          = $this->present( $c );
		$out->history = $hist;
		$today        = Db::today();
		$active       = array_filter( $hist, function ( $h ) {
			return ! in_array( $h->status, Config::crew_inactive_statuses(), true );
		} );
		$out->stats   = array(
			'total'     => count( $active ),
			'upcoming'  => count( array_filter( $active, function ( $h ) use ( $today ) {
				return $h->event_date >= $today;
			} ) ),
			'completed' => count( array_filter( $hist, function ( $h ) {
				return 'completed' === $h->status;
			} ) ),
		);
		if ( $this->money() ) {
			$x = $wpdb->get_results( $wpdb->prepare( 'SELECT x.*, s.event_name, s.event_date, s.client_name FROM ' . Db::t( 'crew_expenses' ) . ' x LEFT JOIN ' . Db::t( 'crew_schedules' ) . ' s ON s.id=x.schedule_id AND s.studio_id=x.studio_id WHERE x.studio_id=%d AND x.crew_member_id=%d ORDER BY s.event_date DESC, x.id DESC', $sid, $id ) );
			$x = CrewModel::with_totals( Db::cast_all( $x ) );
			$out->payments        = $x;
			$out->payment_summary = array(
				'agreed'    => array_sum( wp_list_pluck( $x, 'agreed_rate' ) ),
				'paid'      => array_sum( wp_list_pluck( $x, 'paid' ) ),
				'remaining' => array_sum( wp_list_pluck( $x, 'remaining' ) ),
			);
		}
		$out->audit = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT l.*, u.display_name AS user_name FROM ' . Db::t( 'audit_logs' ) . ' l LEFT JOIN ' . $wpdb->users . " u ON u.ID=l.user_id WHERE l.studio_id=%d AND l.entity='crew_member' AND l.entity_id=%d ORDER BY l.id DESC LIMIT 50", $sid, $id ) ) );
		return $this->ok( $out );
	}

	/* =========================================================
	 * Schedules
	 * ======================================================= */

	private function schedule_schema() {
		return array(
			'booking_id'       => array( 'type' => 'ref', 'table' => 'bookings', 'label' => 'Booking' ),
			'booking_event_id' => array( 'type' => 'ref', 'table' => 'booking_events', 'label' => 'Booking event' ),
			'client_name'      => array( 'type' => 'string', 'max' => 190, 'label' => 'Client name' ),
			'event_name'       => array( 'type' => 'string', 'required' => true, 'max' => 190, 'label' => 'Event name' ),
			'event_type'       => array( 'type' => 'enum', 'options' => Config::event_types(), 'label' => 'Event type' ),
			'event_date'       => array( 'type' => 'date', 'required' => true, 'label' => 'Event date' ),
			'start_time'       => array( 'type' => 'time', 'label' => 'Event start time' ),
			'reporting_time'   => array( 'type' => 'time', 'label' => 'Reporting time' ),
			'end_time'         => array( 'type' => 'time', 'label' => 'Event end time' ),
			'venue'            => array( 'type' => 'string', 'max' => 255, 'label' => 'Event venue' ),
			'city'             => array( 'type' => 'string', 'max' => 100, 'label' => 'Event city' ),
			'transport'        => array( 'type' => 'text', 'label' => 'Transportation details' ),
			'accommodation'    => array( 'type' => 'text', 'label' => 'Accommodation details' ),
			'instructions'     => array( 'type' => 'text', 'label' => 'Special instructions' ),
		);
	}

	private function assignment_schema() {
		return array(
			'crew_member_id' => array( 'type' => 'ref', 'table' => 'crew_members', 'required' => true, 'label' => 'Crew member' ),
			'role'           => array( 'type' => 'enum', 'options' => Config::crew_categories(), 'required' => true, 'label' => 'Crew role' ),
			'service'        => array( 'type' => 'string', 'max' => 190, 'label' => 'Assigned service' ),
			'rate'           => array( 'type' => 'money', 'label' => 'Daily payment / rate' ),
			'status'         => array( 'type' => 'enum', 'options' => Config::crew_assignment_statuses(), 'default' => 'scheduled', 'label' => 'Assignment status' ),
			'notes'          => array( 'type' => 'text' ),
		);
	}

	/** Schedules with their assignments and crew details. */
	public static function load_schedules( $where_sql, $money, $sid ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT s.*, b.number AS booking_number, b.title AS booking_title FROM ' . Db::t( 'crew_schedules' ) . ' s LEFT JOIN ' . Db::t( 'bookings' ) . " b ON b.id=s.booking_id AND b.studio_id=s.studio_id WHERE $where_sql ORDER BY s.event_date, COALESCE(s.reporting_time, s.start_time), s.id LIMIT 2000" );
		if ( ! $rows ) {
			return array();
		}
		$ids    = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		$assign = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, c.name AS crew_name, c.phone AS crew_phone, c.whatsapp AS crew_whatsapp, c.photo_url AS crew_photo, c.category AS crew_category, c.deleted_at AS crew_deleted FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a LEFT JOIN ' . Db::t( 'crew_members' ) . " c ON c.id=a.crew_member_id AND c.studio_id=a.studio_id WHERE a.studio_id=%d AND a.schedule_id IN ($ids) ORDER BY a.id", $sid ) );
		$studio = Tenant::load_studio( $sid );
		$by     = array();
		foreach ( $assign as $a ) {
			$by[ (int) $a->schedule_id ][] = $a;
		}
		$out = array();
		foreach ( $rows as $s ) {
			$s              = Db::cast( $s );
			$s->assignments = array();
			foreach ( isset( $by[ $s->id ] ) ? $by[ $s->id ] : array() as $a ) {
				$a        = Db::cast( $a );
				$phone    = $a->crew_whatsapp ? $a->crew_whatsapp : $a->crew_phone;
				$a->wa_link = $phone ? WA::link( $phone, CrewModel::wa_text( $studio ? $studio->name : '', $a->crew_name, $s, $a ) ) : null;
				if ( ! $money ) {
					unset( $a->rate );
				}
				$s->assignments[] = $a;
			}
			$out[] = $s;
		}
		return $out;
	}

	public function schedules( $req ) {
		global $wpdb;
		$sid  = $this->sid();
		$w    = array( $wpdb->prepare( 's.studio_id=%d', $sid ) );
		$from = Validator::date( (string) $req->get_param( 'from' ) );
		$to   = Validator::date( (string) $req->get_param( 'to' ) );
		if ( $from ) {
			$w[] = $wpdb->prepare( 's.event_date >= %s', $from );
		}
		if ( $to ) {
			$w[] = $wpdb->prepare( 's.event_date <= %s', $to );
		}
		if ( $req->get_param( 'booking_id' ) ) {
			$w[] = $wpdb->prepare( 's.booking_id=%d', $req->get_param( 'booking_id' ) );
		}
		$city = trim( (string) $req->get_param( 'city' ) );
		if ( '' !== $city ) {
			$w[] = $wpdb->prepare( 's.city=%s', $city );
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$l   = $this->like( $q );
			$w[] = $wpdb->prepare( '(s.event_name LIKE %s OR s.client_name LIKE %s OR s.venue LIKE %s)', $l, $l, $l );
		}
		// Filters on assignments: crew member, category and status.
		$aw = array();
		if ( $req->get_param( 'crew_member_id' ) ) {
			$aw[] = $wpdb->prepare( 'a.crew_member_id=%d', $req->get_param( 'crew_member_id' ) );
		}
		$cat = (string) $req->get_param( 'category' );
		if ( $cat && isset( Config::crew_categories()[ $cat ] ) ) {
			$aw[] = $wpdb->prepare( 'a.role=%s', $cat );
		}
		$st = (string) $req->get_param( 'status' );
		if ( $st && isset( Config::crew_assignment_statuses()[ $st ] ) ) {
			$aw[] = $wpdb->prepare( 'a.status=%s', $st );
		}
		if ( $aw ) {
			$w[] = 'EXISTS (SELECT 1 FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a WHERE a.studio_id=s.studio_id AND a.schedule_id=s.id AND ' . implode( ' AND ', $aw ) . ')';
		}
		$items  = self::load_schedules( implode( ' AND ', $w ), $this->money(), $sid );
		$cities = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT city FROM ' . Db::t( 'crew_schedules' ) . " WHERE studio_id=%d AND city IS NOT NULL AND city<>'' ORDER BY city", $sid ) );
		return $this->ok( array( 'items' => $items, 'cities' => $cities ) );
	}

	public function show_schedule( $req ) {
		global $wpdb;
		$id   = (int) $req['id'];
		$rows = self::load_schedules( $wpdb->prepare( 's.studio_id=%d AND s.id=%d', $this->sid(), $id ), $this->money(), $this->sid() );
		if ( ! $rows ) {
			return $this->not_found( 'Crew schedule' );
		}
		$s        = $rows[0];
		$s->audit = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT l.*, u.display_name AS user_name FROM ' . Db::t( 'audit_logs' ) . ' l LEFT JOIN ' . $wpdb->users . " u ON u.ID=l.user_id WHERE l.studio_id=%d AND l.entity='crew_schedule' AND l.entity_id=%d ORDER BY l.id DESC LIMIT 100", $this->sid(), $id ) ) );
		return $this->ok( $s );
	}

	/**
	 * Validates the schedule and its assignments. Returns [schedule, assignments]
	 * or WP_Error.
	 */
	private function read_schedule( $req, $existing = null ) {
		$b    = $this->body( $req );
		$data = $this->validate( $req, $this->schedule_schema(), (bool) $existing );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! empty( $data['booking_event_id'] ) ) {
			$ev  = Db::find( 'booking_events', (int) $data['booking_event_id'] );
			$bid = array_key_exists( 'booking_id', $data ) ? $data['booking_id'] : ( $existing ? $existing->booking_id : null );
			if ( ! $ev || (int) $ev->booking_id !== (int) $bid ) {
				return new \WP_Error( 'validation_failed', 'That event does not belong to the selected booking.', array( 'status' => 422, 'errors' => array( 'booking_event_id' => 'Not part of this booking.' ) ) );
			}
		}
		$list = isset( $b['assignments'] ) && is_array( $b['assignments'] ) ? $b['assignments'] : null;
		if ( null === $list && ! $existing ) {
			$list = array();
		}
		$assignments = null;
		if ( null !== $list ) {
			if ( count( $list ) > 60 ) {
				return new \WP_Error( 'validation_failed', 'A schedule can have at most 60 crew assignments.', array( 'status' => 422 ) );
			}
			$assignments = array();
			$errors      = array();
			foreach ( array_values( $list ) as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$a = Validator::validate( $row, $this->assignment_schema() );
				if ( is_wp_error( $a ) ) {
					$errors[ 'assignments.' . $i ] = $a->get_error_message();
					continue;
				}
				$c = Db::find( 'crew_members', $a['crew_member_id'] );
				if ( ! $c || ( $c->deleted_at && empty( $row['id'] ) ) ) {
					$errors[ 'assignments.' . $i ] = 'Crew member was not found.';
					continue;
				}
				$a['id']       = ! empty( $row['id'] ) ? (int) $row['id'] : 0;
				$a['override'] = ! empty( $row['override'] );
				if ( ! $this->money() ) {
					unset( $a['rate'] ); // Keep the existing rate; new ones get the daily rate below.
					if ( ! $a['id'] && null !== $c->daily_rate ) {
						$a['rate'] = (float) $c->daily_rate;
					}
				} elseif ( ( ! array_key_exists( 'rate', $row ) || '' === $row['rate'] || null === $row['rate'] ) && ! $a['id'] && null !== $c->daily_rate ) {
					$a['rate'] = (float) $c->daily_rate;
				}
				$a['_name']    = $c->name;
				$assignments[] = $a;
			}
			if ( $errors ) {
				return new \WP_Error( 'validation_failed', reset( $errors ), array( 'status' => 422, 'errors' => $errors ) );
			}
		}
		return array( $data, $assignments, ! empty( $b['override_conflicts'] ), isset( $b['override_note'] ) ? sanitize_text_field( (string) $b['override_note'] ) : '' );
	}

	/**
	 * Finds clashes for the assignments that would be active after saving.
	 */
	private function clashes( $sched, array $assignments, $schedule_id ) {
		$active = array_filter( $assignments, function ( $a ) {
			return ! in_array( $a['status'], Config::crew_inactive_statuses(), true );
		} );
		$found  = CrewModel::conflicts( wp_list_pluck( $active, 'crew_member_id' ), $sched['event_date'], $sched['start_time'], $sched['reporting_time'], $sched['end_time'], $schedule_id );
		$out    = array();
		foreach ( $active as $a ) {
			if ( ! empty( $found[ $a['crew_member_id'] ] ) && empty( $a['override'] ) ) {
				$out[] = array( 'crew_member_id' => $a['crew_member_id'], 'crew_name' => $a['_name'], 'conflicts' => $found[ $a['crew_member_id'] ] );
			}
		}
		return $out;
	}

	private function conflict_error( $clashes ) {
		$names = array_unique( wp_list_pluck( $clashes, 'crew_name' ) );
		return new \WP_Error( 'crew_conflict', sprintf( '%s %s already assigned to another event at this time.', implode( ', ', $names ), count( $names ) > 1 ? 'are' : 'is' ), array( 'status' => 409, 'conflicts' => $clashes ) );
	}

	public function check_conflicts( $req ) {
		$b    = $this->body( $req );
		$date = Validator::date( isset( $b['event_date'] ) ? (string) $b['event_date'] : '' );
		if ( ! $date ) {
			return $this->ok( array() );
		}
		$t     = function ( $k ) use ( $b ) {
			return ! empty( $b[ $k ] ) && preg_match( '/^\d{2}:\d{2}/', (string) $b[ $k ] ) ? substr( (string) $b[ $k ], 0, 5 ) . ':00' : null;
		};
		$ids   = isset( $b['crew_member_ids'] ) && is_array( $b['crew_member_ids'] ) ? $b['crew_member_ids'] : array();
		$found = CrewModel::conflicts( $ids, $date, $t( 'start_time' ), $t( 'reporting_time' ), $t( 'end_time' ), isset( $b['exclude_schedule_id'] ) ? (int) $b['exclude_schedule_id'] : 0 );
		return $this->ok( (object) $found );
	}

	private function save_assignments( $schedule_id, array $assignments, $override_note, $is_update ) {
		global $wpdb;
		$sid  = $this->sid();
		$keep = array();
		$log  = array();
		$sts  = Config::crew_assignment_statuses();
		$cats = Config::crew_categories();
		foreach ( $assignments as $a ) {
			$row = array(
				'crew_member_id' => $a['crew_member_id'],
				'role'           => $a['role'],
				'service'        => $a['service'],
				'status'         => $a['status'],
				'notes'          => $a['notes'],
				'updated_by'     => get_current_user_id(),
			);
			if ( array_key_exists( 'rate', $a ) ) {
				$row['rate'] = $a['rate'];
			}
			if ( ! empty( $a['override'] ) ) {
				$row['conflict_override'] = 1;
				$row['override_note']     = $override_note ? mb_substr( $override_note, 0, 255 ) : 'Confirmed by user';
			}
			$old = $a['id'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'crew_schedule_assignments' ) . ' WHERE id=%d AND studio_id=%d AND schedule_id=%d', $a['id'], $sid, $schedule_id ) ) : null;
			if ( $old ) {
				Db::update( 'crew_schedule_assignments', $old->id, $row );
				$aid = (int) $old->id;
				if ( $old->status !== $a['status'] ) {
					$log[] = $a['_name'] . ': ' . $sts[ $old->status ] . ' → ' . $sts[ $a['status'] ];
				}
				if ( (int) $old->crew_member_id !== (int) $a['crew_member_id'] ) {
					$log[] = 'Crew changed to ' . $a['_name'];
				}
			} else {
				$row['schedule_id'] = $schedule_id;
				$row['created_by']  = get_current_user_id();
				$aid                = Db::insert( 'crew_schedule_assignments', $row );
				if ( is_wp_error( $aid ) ) {
					return $aid;
				}
				if ( $is_update ) {
					$log[] = 'Added ' . $a['_name'] . ' as ' . $cats[ $a['role'] ];
				}
			}
			if ( ! empty( $a['override'] ) ) {
				$log[] = 'Conflict overridden for ' . $a['_name'] . ( $override_note ? ' (' . $override_note . ')' : '' );
			}
			$keep[] = (int) $aid;
			CrewModel::sync_expense( (int) $aid );
		}
		// Assignments left out were removed. Keep any that already have crew payments.
		$gone = $wpdb->get_results( $wpdb->prepare( 'SELECT a.id, c.name FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a LEFT JOIN ' . Db::t( 'crew_members' ) . ' c ON c.id=a.crew_member_id WHERE a.studio_id=%d AND a.schedule_id=%d' . ( $keep ? ' AND a.id NOT IN (' . implode( ',', $keep ) . ')' : '' ), $sid, $schedule_id ) );
		foreach ( $gone as $g ) {
			$x    = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'crew_expenses' ) . ' WHERE studio_id=%d AND assignment_id=%d', $sid, $g->id ) );
			$paid = $x ? (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Db::t( 'crew_expense_payments' ) . ' WHERE studio_id=%d AND crew_expense_id=%d', $sid, $x->id ) ) : 0;
			if ( $paid > 0 ) {
				// Money was paid: keep the record, just mark it cancelled.
				Db::update( 'crew_schedule_assignments', (int) $g->id, array( 'status' => 'cancelled', 'updated_by' => get_current_user_id() ) );
				$log[] = 'Cancelled ' . $g->name . ' (payments kept)';
				continue;
			}
			if ( $x ) {
				Db::delete( 'crew_expenses', (int) $x->id );
			}
			Db::delete( 'crew_schedule_assignments', (int) $g->id );
			$log[] = 'Removed ' . $g->name;
		}
		return $log;
	}

	public function create_schedule( $req ) {
		$r = $this->read_schedule( $req );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		list( $data, $assignments, $override_all, $note ) = $r;
		if ( $override_all ) {
			foreach ( $assignments as &$a ) {
				$a['override'] = true;
			}
			unset( $a );
		}
		$data   += array( 'start_time' => null, 'reporting_time' => null, 'end_time' => null );
		$clashes = $this->clashes( $data, $assignments, 0 );
		if ( $clashes ) {
			return $this->conflict_error( $clashes );
		}
		// Only overrides that were really needed are marked.
		$this->mark_needed_overrides( $data, $assignments, 0 );
		if ( ! empty( $data['booking_id'] ) && empty( $data['client_name'] ) ) {
			$data['client_name'] = $this->booking_client( (int) $data['booking_id'] );
		}
		$data['created_by'] = get_current_user_id();
		Db::begin();
		$id = Db::insert( 'crew_schedules', $data );
		if ( is_wp_error( $id ) ) {
			Db::rollback();
			return $id;
		}
		$log = $this->save_assignments( $id, $assignments, $note, false );
		if ( is_wp_error( $log ) ) {
			Db::rollback();
			return $log;
		}
		Db::commit();
		Db::audit( 'crew_schedule', $id, 'created', 'Crew schedule created: ' . $data['event_name'] . ' on ' . \PCRM\Format::date( $data['event_date'] ) . ' with ' . count( $assignments ) . ' crew', $log ? array( 'notes' => $log ) : null );
		if ( ! empty( $data['booking_id'] ) ) {
			Db::log( 'crew', 'Crew scheduled for ' . $data['event_name'] . ' (' . \PCRM\Format::date( $data['event_date'] ) . '): ' . implode( ', ', wp_list_pluck( $assignments, '_name' ) ), array( 'booking_id' => (int) $data['booking_id'] ) );
		}
		return $this->show_schedule( array( 'id' => $id ) );
	}

	private function mark_needed_overrides( $data, array &$assignments, $schedule_id ) {
		$found = CrewModel::conflicts( wp_list_pluck( $assignments, 'crew_member_id' ), $data['event_date'], $data['start_time'], $data['reporting_time'], $data['end_time'], $schedule_id );
		foreach ( $assignments as &$a ) {
			if ( empty( $found[ $a['crew_member_id'] ] ) || in_array( $a['status'], Config::crew_inactive_statuses(), true ) ) {
				$a['override'] = false;
			}
		}
		unset( $a );
	}

	private function booking_client( $booking_id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT c.name FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'clients' ) . ' c ON c.id=b.client_id AND c.studio_id=b.studio_id WHERE b.id=%d AND b.studio_id=%d', $booking_id, $this->sid() ) );
	}

	public function update_schedule( $req ) {
		global $wpdb;
		$id  = (int) $req['id'];
		$old = Db::find( 'crew_schedules', $id );
		if ( ! $old ) {
			return $this->not_found( 'Crew schedule' );
		}
		$r = $this->read_schedule( $req, $old );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		list( $data, $assignments, $override_all, $note ) = $r;
		$merged = array_merge( (array) $old, $data );
		if ( null === $assignments ) {
			// Only the schedule changed: re-check the crew already on it.
			$assignments = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, c.name AS _name FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a LEFT JOIN ' . Db::t( 'crew_members' ) . ' c ON c.id=a.crew_member_id WHERE a.studio_id=%d AND a.schedule_id=%d', $this->sid(), $id ) ) as $a ) {
				$assignments[] = array( 'id' => (int) $a->id, 'crew_member_id' => (int) $a->crew_member_id, 'role' => $a->role, 'service' => $a->service, 'status' => $a->status, 'notes' => $a->notes, '_name' => $a->_name, 'override' => (bool) $a->conflict_override );
			}
		}
		if ( $override_all ) {
			foreach ( $assignments as &$a ) {
				$a['override'] = true;
			}
			unset( $a );
		}
		$clashes = $this->clashes( $merged, $assignments, $id );
		if ( $clashes ) {
			return $this->conflict_error( $clashes );
		}
		$this->mark_needed_overrides( $merged, $assignments, $id );
		$changes = array();
		foreach ( $data as $k => $v ) {
			if ( (string) $old->$k !== (string) $v ) {
				$changes[ $k ] = array( 'from' => $old->$k, 'to' => $v );
			}
		}
		Db::begin();
		$u = Db::update( 'crew_schedules', $id, $data );
		if ( is_wp_error( $u ) ) {
			Db::rollback();
			return $u;
		}
		$log = $this->save_assignments( $id, $assignments, $note, true );
		if ( is_wp_error( $log ) ) {
			Db::rollback();
			return $log;
		}
		Db::commit();
		if ( $changes || $log ) {
			Db::audit( 'crew_schedule', $id, 'updated', 'Crew schedule updated: ' . $merged['event_name'] . ( $log ? '. ' . implode( '; ', $log ) : '' ), $changes ? $changes : null );
		}
		return $this->show_schedule( array( 'id' => $id ) );
	}

	public function destroy_schedule( $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		$s  = Db::find( 'crew_schedules', $id );
		if ( ! $s ) {
			return $this->not_found( 'Crew schedule' );
		}
		$sid  = $this->sid();
		$paid = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(p.amount),0) FROM ' . Db::t( 'crew_expense_payments' ) . ' p JOIN ' . Db::t( 'crew_expenses' ) . ' x ON x.id=p.crew_expense_id WHERE x.studio_id=%d AND x.schedule_id=%d', $sid, $id ) );
		if ( $paid > 0 ) {
			return new \WP_Error( 'has_payments', 'Crew payments were recorded for this event. Cancel the assignments instead, so the payment history is kept.', array( 'status' => 409 ) );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::t( 'crew_expenses' ) . ' WHERE studio_id=%d AND schedule_id=%d', $sid, $id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::t( 'crew_schedule_assignments' ) . ' WHERE studio_id=%d AND schedule_id=%d', $sid, $id ) );
		Db::delete( 'crew_schedules', $id );
		Db::audit( 'crew_schedule', $id, 'deleted', 'Crew schedule deleted: ' . $s->event_name . ' on ' . \PCRM\Format::date( $s->event_date ) );
		return $this->ok( array( 'deleted' => true ) );
	}

	private function assignment( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT a.*, c.name AS crew_name, s.event_name, s.event_date, s.start_time, s.reporting_time, s.end_time FROM ' . Db::t( 'crew_schedule_assignments' ) . ' a JOIN ' . Db::t( 'crew_schedules' ) . ' s ON s.id=a.schedule_id AND s.studio_id=a.studio_id LEFT JOIN ' . Db::t( 'crew_members' ) . ' c ON c.id=a.crew_member_id WHERE a.id=%d AND a.studio_id=%d', $id, $this->sid() ) );
	}

	public function set_status( $req ) {
		$a = $this->assignment( (int) $req['id'] );
		if ( ! $a ) {
			return $this->not_found( 'Assignment' );
		}
		$b  = $this->body( $req );
		$st = isset( $b['status'] ) ? (string) $b['status'] : '';
		$all = Config::crew_assignment_statuses();
		if ( ! isset( $all[ $st ] ) ) {
			return new \WP_Error( 'validation_failed', 'Choose a valid status.', array( 'status' => 422 ) );
		}
		// Re-activating a declined/cancelled assignment re-checks for clashes.
		if ( in_array( $a->status, Config::crew_inactive_statuses(), true ) && ! in_array( $st, Config::crew_inactive_statuses(), true ) && empty( $b['override'] ) ) {
			$found = CrewModel::conflicts( array( $a->crew_member_id ), $a->event_date, $a->start_time, $a->reporting_time, $a->end_time, (int) $a->schedule_id );
			if ( $found ) {
				return $this->conflict_error( array( array( 'crew_member_id' => (int) $a->crew_member_id, 'crew_name' => $a->crew_name, 'conflicts' => reset( $found ) ) ) );
			}
		}
		$row = array( 'status' => $st, 'updated_by' => get_current_user_id() );
		if ( ! empty( $b['override'] ) ) {
			$row['conflict_override'] = 1;
			$row['override_note']     = ! empty( $b['override_note'] ) ? mb_substr( sanitize_text_field( (string) $b['override_note'] ), 0, 255 ) : 'Confirmed by user';
		}
		Db::update( 'crew_schedule_assignments', $a->id, $row );
		CrewModel::sync_expense( (int) $a->id );
		Db::audit( 'crew_schedule', (int) $a->schedule_id, 'status', $a->crew_name . ': ' . $all[ $a->status ] . ' → ' . $all[ $st ], array( 'assignment_id' => (int) $a->id, 'from' => $a->status, 'to' => $st ) );
		return $this->show_schedule( array( 'id' => (int) $a->schedule_id ) );
	}

	/** Marks an assignment "Reassigned" and gives the same job to another crew member. */
	public function reassign( $req ) {
		$a = $this->assignment( (int) $req['id'] );
		if ( ! $a ) {
			return $this->not_found( 'Assignment' );
		}
		$b   = $this->body( $req );
		$new = isset( $b['crew_member_id'] ) ? (int) $b['crew_member_id'] : 0;
		$c   = $new ? $this->find( $new ) : null;
		if ( ! $c ) {
			return new \WP_Error( 'validation_failed', 'Choose the crew member who takes over.', array( 'status' => 422, 'errors' => array( 'crew_member_id' => 'Required.' ) ) );
		}
		if ( (int) $a->crew_member_id === $new ) {
			return new \WP_Error( 'validation_failed', 'Choose a different crew member.', array( 'status' => 422 ) );
		}
		$found = CrewModel::conflicts( array( $new ), $a->event_date, $a->start_time, $a->reporting_time, $a->end_time, (int) $a->schedule_id );
		if ( $found && empty( $b['override'] ) ) {
			return $this->conflict_error( array( array( 'crew_member_id' => $new, 'crew_name' => $c->name, 'conflicts' => reset( $found ) ) ) );
		}
		$rate = $this->money() && isset( $b['rate'] ) && '' !== $b['rate'] && is_numeric( $b['rate'] ) ? max( 0, round( (float) $b['rate'], 2 ) ) : ( null !== $c->daily_rate ? (float) $c->daily_rate : $a->rate );
		Db::begin();
		Db::update( 'crew_schedule_assignments', $a->id, array( 'status' => 'reassigned', 'updated_by' => get_current_user_id() ) );
		CrewModel::sync_expense( (int) $a->id );
		$nid = Db::insert(
			'crew_schedule_assignments',
			array(
				'schedule_id'       => (int) $a->schedule_id,
				'crew_member_id'    => $new,
				'role'              => $a->role,
				'service'           => $a->service,
				'rate'              => $rate,
				'status'            => 'scheduled',
				'conflict_override' => $found ? 1 : 0,
				'override_note'     => $found ? ( ! empty( $b['override_note'] ) ? mb_substr( sanitize_text_field( (string) $b['override_note'] ), 0, 255 ) : 'Confirmed by user' ) : null,
				'created_by'        => get_current_user_id(),
				'updated_by'        => get_current_user_id(),
			)
		);
		if ( is_wp_error( $nid ) ) {
			Db::rollback();
			return $nid;
		}
		CrewModel::sync_expense( (int) $nid );
		Db::commit();
		Db::audit( 'crew_schedule', (int) $a->schedule_id, 'reassigned', 'Reassigned from ' . $a->crew_name . ' to ' . $c->name . ( $found ? ' (conflict overridden)' : '' ) );
		return $this->show_schedule( array( 'id' => (int) $a->schedule_id ) );
	}

	/* =========================================================
	 * Crew payments (expenses, never client revenue)
	 * ======================================================= */

	public function payments( $req ) {
		global $wpdb;
		$sid = $this->sid();
		$w   = array( $wpdb->prepare( 'x.studio_id=%d', $sid ) );
		if ( $req->get_param( 'crew_member_id' ) ) {
			$w[] = $wpdb->prepare( 'x.crew_member_id=%d', $req->get_param( 'crew_member_id' ) );
		}
		$from = Validator::date( (string) $req->get_param( 'from' ) );
		$to   = Validator::date( (string) $req->get_param( 'to' ) );
		if ( $from ) {
			$w[] = $wpdb->prepare( 'COALESCE(s.event_date, DATE(x.created_at)) >= %s', $from );
		}
		if ( $to ) {
			$w[] = $wpdb->prepare( 'COALESCE(s.event_date, DATE(x.created_at)) <= %s', $to );
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$l   = $this->like( $q );
			$w[] = $wpdb->prepare( '(c.name LIKE %s OR s.event_name LIKE %s OR s.client_name LIKE %s)', $l, $l, $l );
		}
		$rows = $wpdb->get_results(
			'SELECT x.*, c.name AS crew_name, c.phone AS crew_phone, c.deleted_at AS crew_deleted, s.event_name, s.client_name, s.event_date, a.status AS assignment_status, a.service
			FROM ' . Db::t( 'crew_expenses' ) . ' x
			LEFT JOIN ' . Db::t( 'crew_members' ) . ' c ON c.id=x.crew_member_id AND c.studio_id=x.studio_id
			LEFT JOIN ' . Db::t( 'crew_schedules' ) . ' s ON s.id=x.schedule_id AND s.studio_id=x.studio_id
			LEFT JOIN ' . Db::t( 'crew_schedule_assignments' ) . ' a ON a.id=x.assignment_id AND a.studio_id=x.studio_id
			WHERE ' . implode( ' AND ', $w ) . ' ORDER BY COALESCE(s.event_date, DATE(x.created_at)) DESC, x.id DESC LIMIT 1000'
		);
		$rows = CrewModel::with_totals( Db::cast_all( $rows ) );
		$st   = (string) $req->get_param( 'status' );
		if ( $st && isset( Config::crew_payment_statuses()[ $st ] ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $st ) {
				return $r->payment_status === $st;
			} ) );
		}
		if ( $rows ) {
			$ids     = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
			$entries = $wpdb->get_results( $wpdb->prepare( 'SELECT p.*, u.display_name AS created_by_name FROM ' . Db::t( 'crew_expense_payments' ) . ' p LEFT JOIN ' . $wpdb->users . " u ON u.ID=p.created_by WHERE p.studio_id=%d AND p.crew_expense_id IN ($ids) ORDER BY p.paid_on, p.id", $sid ) );
			$by      = array();
			foreach ( Db::cast_all( $entries ) as $e ) {
				$by[ $e->crew_expense_id ][] = $e;
			}
			foreach ( $rows as $r ) {
				$r->entries = isset( $by[ $r->id ] ) ? $by[ $r->id ] : array();
			}
		}
		return $this->ok(
			array(
				'items'   => $rows,
				'summary' => array(
					'agreed'    => round( array_sum( wp_list_pluck( $rows, 'agreed_rate' ) ), 2 ),
					'paid'      => round( array_sum( wp_list_pluck( $rows, 'paid' ) ), 2 ),
					'remaining' => round( array_sum( wp_list_pluck( $rows, 'remaining' ) ), 2 ),
				),
			)
		);
	}

	private function expense( $id ) {
		$x = Db::find( 'crew_expenses', $id );
		if ( ! $x ) {
			return null;
		}
		$rows = CrewModel::with_totals( array( Db::cast( $x ) ) );
		return $rows[0];
	}

	/** A crew payment not tied to a schedule assignment (for example a day's editing). */
	public function create_expense( $req ) {
		$data = $this->validate(
			$req,
			array(
				'crew_member_id' => array( 'type' => 'ref', 'table' => 'crew_members', 'required' => true, 'label' => 'Crew member' ),
				'schedule_id'    => array( 'type' => 'ref', 'table' => 'crew_schedules', 'label' => 'Event' ),
				'role'           => array( 'type' => 'enum', 'options' => Config::crew_categories(), 'label' => 'Assigned role' ),
				'agreed_rate'    => array( 'type' => 'money', 'required' => true, 'min' => 1, 'label' => 'Agreed rate' ),
				'notes'          => array( 'type' => 'text' ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['created_by'] = get_current_user_id();
		$id                 = Db::insert( 'crew_expenses', $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$c = Db::find( 'crew_members', $data['crew_member_id'] );
		Db::audit( 'crew_payment', $id, 'created', 'Crew payment record added for ' . $c->name . ': agreed ₹' . \PCRM\Format::inr( $data['agreed_rate'] ) );
		return $this->ok( $this->expense( $id ), 201 );
	}

	public function update_expense( $req ) {
		$id = (int) $req['id'];
		$x  = $this->expense( $id );
		if ( ! $x ) {
			return $this->not_found( 'Crew payment record' );
		}
		$data = $this->validate(
			$req,
			array(
				'agreed_rate' => array( 'type' => 'money', 'label' => 'Agreed rate' ),
				'notes'       => array( 'type' => 'text' ),
			),
			true
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		Db::update( 'crew_expenses', $id, $data );
		if ( isset( $data['agreed_rate'] ) && $x->assignment_id ) {
			// Keep the assignment's rate the same as the agreed rate.
			Db::update( 'crew_schedule_assignments', $x->assignment_id, array( 'rate' => $data['agreed_rate'], 'updated_by' => get_current_user_id() ) );
		}
		if ( isset( $data['agreed_rate'] ) && (float) $data['agreed_rate'] !== (float) $x->agreed_rate ) {
			Db::audit( 'crew_payment', $id, 'updated', 'Agreed rate changed from ₹' . \PCRM\Format::inr( $x->agreed_rate ) . ' to ₹' . \PCRM\Format::inr( $data['agreed_rate'] ) );
		}
		return $this->ok( $this->expense( $id ) );
	}

	public function destroy_expense( $req ) {
		$id = (int) $req['id'];
		$x  = $this->expense( $id );
		if ( ! $x ) {
			return $this->not_found( 'Crew payment record' );
		}
		if ( $x->paid > 0 ) {
			return new \WP_Error( 'has_payments', 'Delete the recorded payments first.', array( 'status' => 409 ) );
		}
		if ( $x->assignment_id ) {
			return new \WP_Error( 'linked', 'This record belongs to a crew assignment. Change or remove the rate on the schedule instead.', array( 'status' => 409 ) );
		}
		Db::delete( 'crew_expenses', $id );
		Db::audit( 'crew_payment', $id, 'deleted', 'Crew payment record deleted' );
		return $this->ok( array( 'deleted' => true ) );
	}

	public function pay( $req ) {
		$id = (int) $req['id'];
		$x  = $this->expense( $id );
		if ( ! $x ) {
			return $this->not_found( 'Crew payment record' );
		}
		$data = $this->validate(
			$req,
			array(
				'amount'       => array( 'type' => 'money', 'required' => true, 'min' => 1 ),
				'paid_on'      => array( 'type' => 'date', 'required' => true, 'label' => 'Payment date' ),
				'payment_type' => array( 'type' => 'enum', 'options' => array( 'advance' => 'Advance', 'payment' => 'Payment' ), 'default' => 'payment', 'label' => 'Payment type' ),
				'method'       => array( 'type' => 'enum', 'options' => Config::payment_methods(), 'label' => 'Payment method' ),
				'reference'    => array( 'type' => 'string', 'max' => 100, 'label' => 'Reference' ),
				'note'         => array( 'type' => 'string', 'max' => 255 ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( $data['amount'] > $x->remaining + 0.005 ) {
			return new \WP_Error( 'validation_failed', 'Amount is more than the remaining ₹' . \PCRM\Format::inr( $x->remaining ) . '. Increase the agreed rate first if needed.', array( 'status' => 422, 'errors' => array( 'amount' => 'More than remaining.' ) ) );
		}
		$data['crew_expense_id'] = $id;
		$data['created_by']      = get_current_user_id();
		$pid                     = Db::insert( 'crew_expense_payments', $data );
		if ( is_wp_error( $pid ) ) {
			return $pid;
		}
		$c = Db::find( 'crew_members', $x->crew_member_id );
		Db::audit( 'crew_payment', $id, 'paid', ( 'advance' === $data['payment_type'] ? 'Advance' : 'Payment' ) . ' of ₹' . \PCRM\Format::inr( $data['amount'] ) . ' to ' . ( $c ? $c->name : 'crew' ) . ' on ' . \PCRM\Format::date( $data['paid_on'] ) );
		return $this->ok( $this->expense( $id ), 201 );
	}

	public function destroy_payment( $req ) {
		$id = (int) $req['id'];
		$p  = Db::find( 'crew_expense_payments', $id );
		if ( ! $p ) {
			return $this->not_found( 'Payment' );
		}
		Db::delete( 'crew_expense_payments', $id );
		Db::audit( 'crew_payment', (int) $p->crew_expense_id, 'payment_deleted', 'Payment of ₹' . \PCRM\Format::inr( $p->amount ) . ' dated ' . \PCRM\Format::date( $p->paid_on ) . ' deleted' );
		return $this->ok( $this->expense( (int) $p->crew_expense_id ) );
	}
}
