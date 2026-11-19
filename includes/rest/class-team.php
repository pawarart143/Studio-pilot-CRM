<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Plans;
use PCRM\Tenant;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

class Team extends Base {

	private function schema() {
		$roles = Config::roles();
		unset( $roles['owner'] );
		return array(
			'name'   => array( 'type' => 'string', 'required' => true ),
			'email'  => array( 'type' => 'email' ),
			'phone'  => array( 'type' => 'phone' ),
			'role'   => array( 'type' => 'enum', 'options' => $roles, 'required' => true ),
			'status' => array( 'type' => 'enum', 'options' => array( 'active' => 1, 'inactive' => 1 ), 'default' => 'active' ),
			'color'  => array( 'type' => 'string', 'max' => 20 ),
			'team_role'      => array( 'type' => 'enum', 'options' => Config::team_roles(), 'label' => 'Team role' ),
			'specialization' => array( 'type' => 'string', 'max' => 190 ),
			'city'           => array( 'type' => 'string', 'max' => 100 ),
			'availability'   => array( 'type' => 'enum', 'options' => Config::availability_statuses(), 'default' => 'available', 'label' => 'Availability' ),
			'joining_date'   => array( 'type' => 'date', 'label' => 'Joining date' ),
			'notes'          => array( 'type' => 'text' ),
		);
	}

	public function register() {
		$this->route( '/team', 'GET', array( $this, 'index' ), 'member' );
		$this->route( '/team', 'POST', array( $this, 'create' ), 'team.edit' );
		$this->route( '/team/availability', 'GET', array( $this, 'availability' ), array( 'team.view', 'bookings.assign' ) );
		$this->route( '/team/performance', 'GET', array( $this, 'performance' ), 'reports.view' );
		$this->route( '/team/(?P<id>\d+)', 'GET', array( $this, 'show' ), 'team.view' );
		$this->route( '/team/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'team.edit' );
		$this->route( '/team/(?P<id>\d+)', 'DELETE', array( $this, 'destroy' ), 'team.edit' );
		$this->route( '/team/(?P<id>\d+)/photo', 'POST', array( $this, 'photo' ), 'team.edit' );
		$this->route( '/team/(?P<id>\d+)/photo', 'DELETE', array( $this, 'remove_photo' ), 'team.edit' );
	}

	public function photo( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'members', $id ) ) {
			return $this->not_found( 'Team member' );
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
		Db::update( 'members', $id, array( 'photo_url' => $url ) );
		return $this->ok( array( 'photo_url' => $url ) );
	}

	public function remove_photo( $req ) {
		$id = (int) $req['id'];
		if ( ! Db::exists( 'members', $id ) ) {
			return $this->not_found( 'Team member' );
		}
		Db::update( 'members', $id, array( 'photo_url' => null ) );
		return $this->ok( array( 'photo_url' => null ) );
	}

	/**
	 * Bookings a member works on (events, services or deliverables) with what
	 * they do there. Upcoming first.
	 */
	public static function assignments( $member_id, $upcoming_only = false ) {
		global $wpdb;
		$sid   = Tenant::studio_id();
		$today = Db::today();
		$ids   = Bookings::member_booking_ids_sql( (int) $member_id );
		$rows  = Db::cast_all(
			$wpdb->get_results(
				'SELECT b.id, b.number, b.title, b.status, b.event_type, c.name AS client_name,
					(SELECT MIN(event_date) FROM ' . Db::t( 'booking_events' ) . ' e WHERE e.booking_id=b.id AND e.studio_id=b.studio_id) AS first_date,
					(SELECT MAX(event_date) FROM ' . Db::t( 'booking_events' ) . ' e WHERE e.booking_id=b.id AND e.studio_id=b.studio_id) AS last_date
				FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'clients' ) . ' c ON c.id=b.client_id
				WHERE b.studio_id=' . (int) $sid . " AND b.status<>'cancelled' AND b.id IN ($ids) ORDER BY first_date IS NULL, first_date DESC LIMIT 200"
			)
		);
		if ( ! $rows ) {
			return array();
		}
		$bids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		$svc  = $wpdb->get_results( $wpdb->prepare( 'SELECT s.booking_id, s.name FROM ' . Db::t( 'booking_services' ) . ' s JOIN ' . Db::t( 'service_team_assignments' ) . " sta ON sta.booking_service_id=s.id WHERE sta.studio_id=%d AND sta.member_id=%d AND s.booking_id IN ($bids) ORDER BY s.sort", $sid, $member_id ) );
		$evs  = $wpdb->get_results( $wpdb->prepare( 'SELECT e.booking_id, e.name, e.event_date, e.start_time, e.venue, e.city, a.role FROM ' . Db::t( 'event_assignments' ) . ' a JOIN ' . Db::t( 'booking_events' ) . " e ON e.id=a.booking_event_id WHERE a.studio_id=%d AND a.member_id=%d AND e.booking_id IN ($bids) ORDER BY e.event_date", $sid, $member_id ) );
		$dls  = $wpdb->get_results( $wpdb->prepare( 'SELECT booking_id, name, status, expected_date FROM ' . Db::t( 'booking_deliverables' ) . " WHERE studio_id=%d AND assigned_to=%d AND booking_id IN ($bids) ORDER BY sort", $sid, $member_id ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$r->services     = array_values( wp_list_pluck( array_filter( $svc, function ( $x ) use ( $r ) {
				return (int) $x->booking_id === $r->id;
			} ), 'name' ) );
			$r->events       = array_values( array_filter( $evs, function ( $x ) use ( $r ) {
				return (int) $x->booking_id === $r->id;
			} ) );
			$r->deliverables = array_values( array_filter( $dls, function ( $x ) use ( $r ) {
				return (int) $x->booking_id === $r->id;
			} ) );
			$r->upcoming     = $r->last_date && $r->last_date >= $today;
			if ( $upcoming_only && ! $r->upcoming ) {
				continue;
			}
			$out[] = $r;
		}
		return $out;
	}

	public function index( $req ) {
		global $wpdb;
		$w = $wpdb->prepare( 'm.studio_id=%d', $this->sid() );
		if ( $req->get_param( 'active' ) ) {
			$w .= " AND m.status='active'";
		}
		if ( $req->get_param( 'role' ) ) {
			$roles = array_values( array_intersect( explode( ',', $req->get_param( 'role' ) ), array_keys( Config::roles() ) ) );
			if ( $roles ) {
				$w .= " AND m.role IN ('" . implode( "','", $roles ) . "')";
			}
		}
		if ( $req->get_param( 'team_role' ) ) {
			$roles = array_values( array_intersect( explode( ',', $req->get_param( 'team_role' ) ), array_keys( Config::team_roles() ) ) );
			if ( $roles ) {
				$w .= " AND m.team_role IN ('" . implode( "','", $roles ) . "')";
			}
		}
		if ( $req->get_param( 'availability' ) && array_key_exists( $req->get_param( 'availability' ), Config::availability_statuses() ) ) {
			$w .= $wpdb->prepare( ' AND m.availability=%s', $req->get_param( 'availability' ) );
		}
		if ( in_array( $req->get_param( 'status' ), array( 'active', 'inactive' ), true ) ) {
			$w .= $wpdb->prepare( ' AND m.status=%s', $req->get_param( 'status' ) );
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like = $this->like( $q );
			$w   .= $wpdb->prepare( ' AND (m.name LIKE %s OR m.phone LIKE %s OR m.email LIKE %s OR m.city LIKE %s OR m.specialization LIKE %s)', $like, $like, $like, $like, $like );
		}
		$today = Db::today();
		$work  = Bookings::work_sql();
		$rows  = $wpdb->get_results(
			'SELECT m.*, (m.user_id IS NOT NULL) AS has_login,
				(SELECT COUNT(*) FROM ' . Db::t( 'tasks' ) . " t WHERE t.assigned_to=m.id AND t.studio_id=m.studio_id AND t.status<>'done') AS open_tasks,
				(SELECT COUNT(DISTINCT e.id) FROM " . $work . ' a JOIN ' . Db::t( 'booking_events' ) . ' e ON e.id=a.booking_event_id JOIN ' . Db::t( 'bookings' ) . " b ON b.id=e.booking_id WHERE a.member_id=m.id AND e.event_date >= '$today' AND b.status<>'cancelled') AS upcoming_events,
				(SELECT COUNT(*) FROM " . Db::t( 'booking_deliverables' ) . " d WHERE d.assigned_to=m.id AND d.studio_id=m.studio_id AND d.status<>'delivered') AS open_deliverables
			FROM " . Db::t( 'members' ) . " m WHERE $w ORDER BY FIELD(m.role,'owner','admin','sales','production_manager','photographer','cinematographer','editor','album_designer'), m.name"
		);
		foreach ( $rows as $r ) {
			$r->has_login       = (bool) $r->has_login;
			$r->open_tasks      = (int) $r->open_tasks;
			$r->upcoming_events = (int) $r->upcoming_events;
			$r->open_deliverables = (int) $r->open_deliverables;
			// Production managers need teammates' numbers to coordinate shoots.
			if ( ! Tenant::can( 'team.view' ) && ! Tenant::can( 'bookings.assign' ) ) {
				unset( $r->email, $r->phone, $r->notes, $r->joining_date );
			}
			if ( ! Tenant::can( 'team.edit' ) ) {
				unset( $r->notes );
			}
		}
		return $this->ok( Db::cast_all( $rows ) );
	}

	public function show( $req ) {
		global $wpdb;
		$m = Db::find( 'members', (int) $req['id'] );
		if ( ! $m ) {
			return $this->not_found( 'Team member' );
		}
		$m->events = Calendar::events( Db::today(), date( 'Y-m-d', strtotime( '+180 days' ) ), array( 'member_id' => (int) $m->id ) );
		$m->tasks  = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'tasks' ) . " WHERE studio_id=%d AND assigned_to=%d ORDER BY status='done', due_at LIMIT 100", $this->sid(), $m->id ) ) );
		$m->has_login = (bool) $m->user_id;
		$m->bookings  = self::assignments( (int) $m->id );
		$m->deliverables = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT d.id, d.booking_id, d.name, d.status, d.progress, d.expected_date, b.number AS booking_number, b.title AS booking_title FROM ' . Db::t( 'booking_deliverables' ) . ' d JOIN ' . Db::t( 'bookings' ) . " b ON b.id=d.booking_id WHERE d.studio_id=%d AND d.assigned_to=%d ORDER BY d.status='delivered', d.expected_date LIMIT 100", $this->sid(), $m->id ) ) );
		if ( ! Tenant::can( 'team.edit' ) ) {
			unset( $m->notes );
		}
		return $this->ok( Db::cast( $m ) );
	}

	/**
	 * Optionally creates (or links) a WordPress login for the member.
	 */
	private function attach_login( $member_id, $email, $name, $password ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'members' ) . ' WHERE studio_id=%d AND user_id=%d AND id<>%d', $this->sid(), $user->ID, $member_id ) );
			if ( $exists ) {
				return new \WP_Error( 'validation_failed', 'This email already belongs to another team member.', array( 'status' => 422, 'errors' => array( 'email' => 'Already a team member.' ) ) );
			}
			Db::update( 'members', $member_id, array( 'user_id' => $user->ID ) );
			return array( 'linked' => true );
		}
		$gen = false;
		if ( ! $password ) {
			$password = wp_generate_password( 12, false );
			$gen      = true;
		} elseif ( strlen( $password ) < 8 ) {
			return new \WP_Error( 'validation_failed', 'Password must be at least 8 characters.', array( 'status' => 422, 'errors' => array( 'password' => 'At least 8 characters.' ) ) );
		}
		$login = sanitize_user( strstr( $email, '@', true ), true );
		$base  = $login ? $login : 'member';
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i++;
		}
		$uid = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $uid ) ) {
			return new \WP_Error( 'validation_failed', $uid->get_error_message(), array( 'status' => 422 ) );
		}
		update_user_meta( $uid, 'pcrm_current_studio', $this->sid() );
		Db::update( 'members', $member_id, array( 'user_id' => $uid ) );
		$studio = Tenant::studio();
		$mailed = wp_mail(
			$email,
			'You have been added to ' . $studio->name . ' on ShootPilot',
			"Hi $name,\n\n{$studio->name} has added you to their team on ShootPilot.\n\nSign in: " . home_url( '/crm/login' ) . "\nEmail: $email\n" . ( $gen ? "Set your password here: " . home_url( '/crm/forgot' ) . "\n" : "Your password was shared by your admin.\n" )
		);
		$out = array(
			'created' => true,
			'mailed'  => (bool) $mailed,
		);
		// A generated password is otherwise unknown to everyone, so the member
		// could never sign in when the invite email does not arrive. Show it to
		// the admin once so they can share it.
		if ( $gen ) {
			$out['password'] = $password;
		}
		return $out;
	}

	/**
	 * True when the WordPress account is used only by this studio, so its admin
	 * may set the password or email. Accounts shared with another studio, or
	 * WordPress administrators, are never changed from here.
	 */
	private function login_owned_by_studio( $user_id ) {
		global $wpdb;
		if ( ! $user_id || user_can( (int) $user_id, 'manage_options' ) ) {
			return false;
		}
		$other = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'members' ) . ' WHERE user_id=%d AND studio_id<>%d', $user_id, $this->sid() ) );
		return 0 === $other;
	}

	public function create( $req ) {
		$data = $this->validate( $req, $this->schema() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$b = $this->body( $req );
		// Plans limit sign-in seats; crew without a login can always be added for scheduling.
		if ( ! empty( $b['create_login'] ) ) {
			$limit = Plans::check( Tenant::studio(), 'members' );
			if ( is_wp_error( $limit ) ) {
				return $limit;
			}
		}
		if ( ! empty( $b['create_login'] ) && empty( $data['email'] ) ) {
			return new \WP_Error( 'validation_failed', 'Email is required to give this member a login.', array( 'status' => 422, 'errors' => array( 'email' => 'Required for login.' ) ) );
		}
		if ( empty( $data['color'] ) ) {
			$palette       = array( '#4f46e5', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#2563eb', '#65a30d' );
			$data['color'] = $palette[ wp_rand( 0, count( $palette ) - 1 ) ];
		}
		Db::begin();
		$id = Db::insert( 'members', $data );
		if ( is_wp_error( $id ) ) {
			Db::rollback();
			return $id;
		}
		$login = null;
		if ( ! empty( $b['create_login'] ) ) {
			$login = $this->attach_login( $id, $data['email'], $data['name'], isset( $b['password'] ) ? (string) $b['password'] : '' );
			if ( is_wp_error( $login ) ) {
				Db::rollback();
				return $login;
			}
		}
		Db::commit();
		$m        = Db::cast( Db::find( 'members', $id ) );
		$m->login = $login;
		return $this->ok( $m, 201 );
	}

	public function update( $req ) {
		$id = (int) $req['id'];
		$m  = Db::find( 'members', $id );
		if ( ! $m ) {
			return $this->not_found( 'Team member' );
		}
		$schema = $this->schema();
		if ( 'owner' === $m->role ) {
			unset( $schema['role'], $schema['status'] );
		}
		$data = $this->validate( $req, $schema, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( (int) $m->user_id === get_current_user_id() && isset( $data['status'] ) && 'inactive' === $data['status'] ) {
			return new \WP_Error( 'validation_failed', 'You cannot deactivate yourself.', array( 'status' => 422 ) );
		}
		// Turning a deactivated member with a login back on uses a team login again.
		if ( $m->user_id && 'inactive' === $m->status && isset( $data['status'] ) && 'inactive' !== $data['status'] ) {
			$limit = Plans::check( Tenant::studio(), 'members' );
			if ( is_wp_error( $limit ) ) {
				return $limit;
			}
		}
		$body         = $this->body( $req );
		$new_password = isset( $body['password'] ) ? (string) $body['password'] : '';
		$login_info   = null;
		if ( $m->user_id ) {
			$owned = $this->login_owned_by_studio( (int) $m->user_id );
			if ( '' !== $new_password ) {
				if ( 'owner' === $m->role && (int) $m->user_id !== get_current_user_id() ) {
					return new \WP_Error( 'forbidden', "Only the owner can change the owner's password.", array( 'status' => 403 ) );
				}
				if ( strlen( $new_password ) < 8 ) {
					return new \WP_Error( 'validation_failed', 'Password must be at least 8 characters.', array( 'status' => 422, 'errors' => array( 'password' => 'At least 8 characters.' ) ) );
				}
				if ( ! $owned ) {
					return new \WP_Error( 'validation_failed', 'This person also uses this login for another studio, so only they can change its password (Forgot password on the sign-in page).', array( 'status' => 422, 'errors' => array( 'password' => 'Shared login: ask them to use Forgot password.' ) ) );
				}
			}
			// Keep the sign-in email in step with the member's email, otherwise
			// they can no longer sign in with the address the admin shows them.
			if ( ! empty( $data['email'] ) ) {
				$u = get_userdata( (int) $m->user_id );
				if ( $u && strtolower( $u->user_email ) !== strtolower( $data['email'] ) ) {
					$taken = get_user_by( 'email', $data['email'] );
					if ( $taken && (int) $taken->ID !== (int) $u->ID ) {
						return new \WP_Error( 'validation_failed', 'Another account already signs in with this email.', array( 'status' => 422, 'errors' => array( 'email' => 'Already used by another login.' ) ) );
					}
					if ( 'owner' === $m->role && (int) $m->user_id !== get_current_user_id() ) {
						return new \WP_Error( 'forbidden', "Only the owner can change the owner's sign-in email.", array( 'status' => 403 ) );
					}
					if ( ! $owned ) {
						return new \WP_Error( 'validation_failed', 'This login is shared with another studio, so its email cannot be changed here.', array( 'status' => 422, 'errors' => array( 'email' => 'Shared login: email cannot be changed here.' ) ) );
					}
				}
			}
		}
		Db::update( 'members', $id, $data );
		$b = $this->body( $req );
		if ( $m->user_id ) {
			$u = get_userdata( (int) $m->user_id );
			if ( $u && ! empty( $data['email'] ) && strtolower( $u->user_email ) !== strtolower( $data['email'] ) ) {
				wp_update_user( array( 'ID' => $u->ID, 'user_email' => $data['email'] ) );
				$login_info = array( 'email_changed' => true );
			}
			if ( '' !== $new_password ) {
				wp_set_password( $new_password, (int) $m->user_id );
				if ( (int) $m->user_id === get_current_user_id() ) {
					wp_set_auth_cookie( (int) $m->user_id, true, is_ssl() );
				}
				$login_info = array_merge( (array) $login_info, array( 'password_set' => true ) );
			}
		}
		if ( ! empty( $b['create_login'] ) && ! $m->user_id ) {
			$limit = Plans::check( Tenant::studio(), 'members' );
			if ( is_wp_error( $limit ) ) {
				return $limit;
			}
			$email = isset( $data['email'] ) ? $data['email'] : $m->email;
			if ( ! $email ) {
				return new \WP_Error( 'validation_failed', 'Email is required to give this member a login.', array( 'status' => 422 ) );
			}
			$r = $this->attach_login( $id, $email, isset( $data['name'] ) ? $data['name'] : $m->name, $new_password );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$login_info = $r;
		}
		$out        = Db::cast( Db::find( 'members', $id ) );
		$out->login = $login_info;
		return $this->ok( $out );
	}

	public function destroy( $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		$m  = Db::find( 'members', $id );
		if ( ! $m ) {
			return $this->not_found( 'Team member' );
		}
		if ( 'owner' === $m->role ) {
			return new \WP_Error( 'forbidden', 'The studio owner cannot be removed.', array( 'status' => 422 ) );
		}
		if ( (int) $m->user_id === get_current_user_id() ) {
			return new \WP_Error( 'forbidden', 'You cannot remove yourself.', array( 'status' => 422 ) );
		}
		$used = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'event_assignments' ) . ' WHERE studio_id=%d AND member_id=%d', $this->sid(), $id ) );
		if ( $used ) {
			Db::update( 'members', $id, array( 'status' => 'inactive' ) );
			return $this->ok( array( 'deactivated' => true, 'message' => 'This member has event history, so they were deactivated instead of deleted.' ) );
		}
		$sid = $this->sid();
		$wpdb->update( Db::t( 'leads' ), array( 'assigned_to' => null ), array( 'assigned_to' => $id, 'studio_id' => $sid ) );
		$wpdb->update( Db::t( 'tasks' ), array( 'assigned_to' => null ), array( 'assigned_to' => $id, 'studio_id' => $sid ) );
		$wpdb->update( Db::t( 'followups' ), array( 'assigned_to' => null ), array( 'assigned_to' => $id, 'studio_id' => $sid ) );
		Db::delete( 'members', $id );
		return $this->ok( array( 'deleted' => true ) );
	}

	public function availability( $req ) {
		global $wpdb;
		$from = Validator::date( (string) $req->get_param( 'from' ) );
		$from = $from ? $from : Db::today();
		$to   = Validator::date( (string) $req->get_param( 'to' ) );
		$to   = $to && $to >= $from ? $to : date( 'Y-m-d', strtotime( $from . ' +6 days' ) );
		if ( ( strtotime( $to ) - strtotime( $from ) ) > 62 * DAY_IN_SECONDS ) {
			$to = date( 'Y-m-d', strtotime( $from . ' +62 days' ) );
		}
		$members = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, role, team_role, color, photo_url, availability FROM ' . Db::t( 'members' ) . " WHERE studio_id=%d AND status='active' ORDER BY name", $this->sid() ) ) );
		$busy    = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT a.member_id, a.role, a.source, a.service_name, e.id AS event_id, e.name, e.event_date, e.start_time, e.end_time, e.venue, e.city, b.id AS booking_id, b.title AS booking_title FROM ' . Bookings::work_sql() . ' a JOIN ' . Db::t( 'booking_events' ) . ' e ON e.id=a.booking_event_id JOIN ' . Db::t( 'bookings' ) . " b ON b.id=e.booking_id WHERE e.studio_id=%d AND b.status<>'cancelled' AND e.event_date BETWEEN %s AND %s ORDER BY e.event_date, e.start_time", $this->sid(), $from, $to ) ) );
		foreach ( $members as $m ) {
			$m->busy = array_values(
				array_filter(
					$busy,
					function ( $x ) use ( $m ) {
						return $x->member_id === $m->id;
					}
				)
			);
		}
		return $this->ok( array( 'from' => $from, 'to' => $to, 'members' => $members ) );
	}

	public function performance( $req ) {
		global $wpdb;
		$from = Validator::date( (string) $req->get_param( 'from' ) );
		$from = $from ? $from : Db::date( 'Y-m-01' );
		$to   = Validator::date( (string) $req->get_param( 'to' ) );
		$to   = $to ? $to : Db::today();
		$sid  = $this->sid();
		$rows = Db::cast_all( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, role, color FROM ' . Db::t( 'members' ) . ' WHERE studio_id=%d ORDER BY name', $sid ) ) );
		$l    = Db::t( 'leads' );
		$f    = $from . ' 00:00:00';
		$t    = $to . ' 23:59:59';
		foreach ( $rows as $m ) {
			$m->leads      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE studio_id=%d AND assigned_to=%d AND created_at BETWEEN %s AND %s", $sid, $m->id, $f, $t ) );
			$m->booked     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE studio_id=%d AND assigned_to=%d AND stage='booked' AND created_at BETWEEN %s AND %s", $sid, $m->id, $f, $t ) );
			$m->conversion = $m->leads ? round( $m->booked * 100 / $m->leads, 1 ) : 0;
			$m->calls      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'calls' ) . ' WHERE studio_id=%d AND member_id=%d AND called_at BETWEEN %s AND %s', $sid, $m->id, $f, $t ) );
			$m->revenue    = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(b.total_amount),0) FROM ' . Db::t( 'bookings' ) . " b JOIN $l l ON l.id=b.lead_id WHERE b.studio_id=%d AND l.assigned_to=%d AND b.status<>'cancelled' AND b.confirmed_on BETWEEN %s AND %s", $sid, $m->id, $from, $to ) );
			$m->events     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'event_assignments' ) . ' a JOIN ' . Db::t( 'booking_events' ) . ' e ON e.id=a.booking_event_id WHERE a.studio_id=%d AND a.member_id=%d AND e.event_date BETWEEN %s AND %s', $sid, $m->id, $from, $to ) );
			$m->tasks_done = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'tasks' ) . " WHERE studio_id=%d AND assigned_to=%d AND status='done' AND completed_at BETWEEN %s AND %s", $sid, $m->id, $f, $t ) );
			$m->tasks_open = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'tasks' ) . " WHERE studio_id=%d AND assigned_to=%d AND status<>'done'", $sid, $m->id ) );
			$m->tasks_overdue = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'tasks' ) . " WHERE studio_id=%d AND assigned_to=%d AND status<>'done' AND due_at < %s", $sid, $m->id, Db::now() ) );
		}
		return $this->ok( array( 'from' => $from, 'to' => $to, 'members' => $rows ) );
	}
}
