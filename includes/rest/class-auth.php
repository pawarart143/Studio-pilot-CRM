<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Permissions;
use PCRM\Plans;
use PCRM\Studios;
use PCRM\Tenant;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

class Auth extends Base {

	public function register() {
		$this->route( '/auth/login', 'POST', array( $this, 'login' ), 'public' );
		$this->route( '/auth/register', 'POST', array( $this, 'signup' ), 'public' );
		$this->route( '/auth/forgot', 'POST', array( $this, 'forgot' ), 'public' );
		$this->route( '/auth/reset', 'POST', array( $this, 'reset' ), 'public' );
		$this->route( '/auth/logout', 'POST', array( $this, 'logout' ), 'public' );
		$this->route( '/auth/create-studio', 'POST', array( $this, 'create_studio' ), 'public' );
		$this->route( '/me', 'GET', array( $this, 'me' ), 'public' );
		$this->route( '/me', 'POST', array( $this, 'update_me' ), 'member' );
		$this->route( '/me/password', 'POST', array( $this, 'change_password' ), 'member' );
		$this->route( '/me/studio', 'POST', array( $this, 'switch_studio' ), 'public' );
	}

	private function throttle( $key, $max = 10 ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$k   = 'pcrm_rl_' . md5( $key . $ip );
		$n   = (int) get_transient( $k );
		if ( $n >= $max ) {
			return new \WP_Error( 'too_many', 'Too many attempts. Please wait 15 minutes and try again.', array( 'status' => 429 ) );
		}
		set_transient( $k, $n + 1, 15 * MINUTE_IN_SECONDS );
		return true;
	}

	public function login( $req ) {
		$b = $this->body( $req );
		$t = $this->throttle( 'login' );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$login = isset( $b['email'] ) ? sanitize_text_field( $b['email'] ) : '';
		$pass  = isset( $b['password'] ) ? (string) $b['password'] : '';
		if ( ! $login || ! $pass ) {
			return new \WP_Error( 'validation_failed', 'Email and password are required.', array( 'status' => 422 ) );
		}
		if ( is_email( $login ) ) {
			$u = get_user_by( 'email', $login );
			if ( $u ) {
				$login = $u->user_login;
			}
		}
		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $pass,
				'remember'      => ! empty( $b['remember'] ),
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			return new \WP_Error( 'invalid_login', 'Incorrect email or password.', array( 'status' => 401 ) );
		}
		return $this->ok( array( 'ok' => true ) );
	}

	public function signup( $req ) {
		$t = $this->throttle( 'register', 5 );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$data = $this->validate(
			$req,
			array(
				'name'        => array( 'type' => 'string', 'required' => true, 'label' => 'Your name' ),
				'studio_name' => array( 'type' => 'string', 'required' => true, 'label' => 'Studio name' ),
				'email'       => array( 'type' => 'email', 'required' => true ),
				'phone'       => array( 'type' => 'phone', 'required' => true ),
				'city'        => array( 'type' => 'string', 'max' => 100 ),
				'password'    => array( 'type' => 'string', 'required' => true, 'max' => 200 ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$b = $this->body( $req );
		if ( strlen( (string) $b['password'] ) < 8 ) {
			return new \WP_Error( 'validation_failed', 'Password must be at least 8 characters.', array( 'status' => 422, 'errors' => array( 'password' => 'Password must be at least 8 characters.' ) ) );
		}
		if ( email_exists( $data['email'] ) ) {
			return new \WP_Error( 'validation_failed', 'An account with this email already exists. Please sign in.', array( 'status' => 422, 'errors' => array( 'email' => 'This email is already registered.' ) ) );
		}
		$username = sanitize_user( strstr( $data['email'], '@', true ), true );
		$base     = $username ? $username : 'user';
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i++;
		}
		$uid = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $data['email'],
				'user_pass'    => (string) $b['password'],
				'display_name' => $data['name'],
				'first_name'   => $data['name'],
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $uid ) ) {
			return new \WP_Error( 'register_failed', $uid->get_error_message(), array( 'status' => 422 ) );
		}
		$sid = Studios::create(
			$uid,
			array(
				'name'       => $data['studio_name'],
				'owner_name' => $data['name'],
				'email'      => $data['email'],
				'phone'      => $data['phone'],
				'city'       => $data['city'],
			)
		);
		if ( is_wp_error( $sid ) ) {
			return $sid;
		}
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true, is_ssl() );
		return $this->ok( array( 'ok' => true ), 201 );
	}

	public function create_studio( $req ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'not_logged_in', 'Please sign in to continue.', array( 'status' => 401 ) );
		}
		$data = $this->validate(
			$req,
			array(
				'studio_name' => array( 'type' => 'string', 'required' => true, 'label' => 'Studio name' ),
				'phone'       => array( 'type' => 'phone' ),
				'city'        => array( 'type' => 'string', 'max' => 100 ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$u   = wp_get_current_user();
		$sid = Studios::create(
			$u->ID,
			array(
				'name'  => $data['studio_name'],
				'email' => $u->user_email,
				'phone' => $data['phone'],
				'city'  => $data['city'],
			)
		);
		return is_wp_error( $sid ) ? $sid : $this->ok( array( 'id' => $sid ), 201 );
	}

	public function forgot( $req ) {
		$t = $this->throttle( 'forgot', 5 );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$b     = $this->body( $req );
		$email = isset( $b['email'] ) ? sanitize_email( $b['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'validation_failed', 'Please enter a valid email address.', array( 'status' => 422 ) );
		}
		$user = get_user_by( 'email', $email );
		$sent = true;
		if ( $user ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$url  = home_url( '/crm/reset?login=' . rawurlencode( $user->user_login ) . '&key=' . rawurlencode( $key ) );
				$msg  = "Hi {$user->display_name},\n\nWe received a request to reset your ShootPilot password.\n\nReset it here: $url\n\nIf you did not ask for this, you can ignore this email.";
				$sent = wp_mail( $user->user_email, 'Reset your ShootPilot password', $msg );
			}
		}
		if ( ! $sent ) {
			return new \WP_Error( 'mail_failed', 'We could not send the email. Please ask your administrator to check the site email (SMTP) settings.', array( 'status' => 500 ) );
		}
		// Same answer whether or not the account exists, so emails cannot be probed.
		return $this->ok( array( 'ok' => true ) );
	}

	public function reset( $req ) {
		$b     = $this->body( $req );
		$login = isset( $b['login'] ) ? sanitize_user( $b['login'] ) : '';
		$key   = isset( $b['key'] ) ? sanitize_text_field( $b['key'] ) : '';
		$pass  = isset( $b['password'] ) ? (string) $b['password'] : '';
		if ( strlen( $pass ) < 8 ) {
			return new \WP_Error( 'validation_failed', 'Password must be at least 8 characters.', array( 'status' => 422 ) );
		}
		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			return new \WP_Error( 'invalid_key', 'This reset link is invalid or has expired. Please request a new one.', array( 'status' => 400 ) );
		}
		reset_password( $user, $pass );
		return $this->ok( array( 'ok' => true ) );
	}

	public function logout() {
		wp_logout();
		return $this->ok( array( 'ok' => true ) );
	}

	public function me() {
		if ( ! is_user_logged_in() ) {
			return $this->ok( array( 'user' => null, 'config' => Config::all() ) );
		}
		$u      = wp_get_current_user();
		$member = Tenant::member();
		$studio = Tenant::studio();
		$out    = array(
			'user'        => array(
				'id'    => $u->ID,
				'name'  => $u->display_name,
				'email' => $u->user_email,
			),
			'is_super'    => Tenant::is_super_admin(),
			'member'      => $member ? \PCRM\Db::cast( $member ) : null,
			'permissions' => $member ? Permissions::for_role( $member->role ) : array(),
			'studios'     => \PCRM\Db::cast_all( Tenant::memberships( $u->ID ) ),
			'config'      => Config::all(),
			'studio'      => null,
		);
		if ( $studio ) {
			$settings = $studio->settings;
			$can_edit = Tenant::can( 'settings.edit' );
			foreach ( Config::secret_settings() as $k ) {
				if ( ! $can_edit ) {
					unset( $settings[ $k ] );
				} elseif ( ! empty( $settings[ $k ] ) ) {
					$settings[ $k ] = '••••' . substr( $settings[ $k ], -4 );
				}
			}
			if ( ! $can_edit ) {
				unset( $settings['rzp_key_id'] );
			}
			$settings['rzp_enabled'] = ! empty( $studio->settings['rzp_key_id'] ) && ! empty( $studio->settings['rzp_key_secret'] );
			$settings['rzp_webhook'] = $can_edit ? rest_url( 'pcrm/v1/razorpay/webhook/' . $studio->id ) : '';
			$out['studio'] = array(
				'id'              => $studio->id,
				'name'            => $studio->name,
				'email'           => $studio->email,
				'phone'           => $studio->phone,
				'address'         => $studio->address,
				'city'            => $studio->city,
				'state'           => $studio->state,
				'gstin'           => $studio->gstin,
				'logo_url'        => $studio->logo_url,
				'plan'            => $studio->plan,
				'plan_info'       => Plans::get( $studio->plan ),
				'plan_expires_at' => $studio->plan_expires_at,
				'status'          => $studio->status,
				'settings'        => $settings,
			);
		}
		return $this->ok( $out );
	}

	public function update_me( $req ) {
		$data = $this->validate(
			$req,
			array(
				'name'  => array( 'type' => 'string', 'required' => true ),
				'phone' => array( 'type' => 'phone' ),
			),
			true
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$uid = get_current_user_id();
		if ( isset( $data['name'] ) ) {
			wp_update_user( array( 'ID' => $uid, 'display_name' => $data['name'] ) );
		}
		\PCRM\Db::update( 'members', $this->member_id(), $data );
		return $this->ok( array( 'ok' => true ) );
	}

	public function change_password( $req ) {
		$b    = $this->body( $req );
		$user = wp_get_current_user();
		if ( empty( $b['current'] ) || ! wp_check_password( (string) $b['current'], $user->user_pass, $user->ID ) ) {
			return new \WP_Error( 'validation_failed', 'Current password is incorrect.', array( 'status' => 422, 'errors' => array( 'current' => 'Current password is incorrect.' ) ) );
		}
		if ( empty( $b['password'] ) || strlen( (string) $b['password'] ) < 8 ) {
			return new \WP_Error( 'validation_failed', 'New password must be at least 8 characters.', array( 'status' => 422, 'errors' => array( 'password' => 'At least 8 characters.' ) ) );
		}
		wp_set_password( (string) $b['password'], $user->ID );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		return $this->ok( array( 'ok' => true ) );
	}

	public function switch_studio( $req ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'not_logged_in', 'Please sign in.', array( 'status' => 401 ) );
		}
		$b = $this->body( $req );
		if ( ! Tenant::switch_studio( (int) ( isset( $b['studio_id'] ) ? $b['studio_id'] : 0 ) ) ) {
			return new \WP_Error( 'forbidden', 'You are not a member of that studio.', array( 'status' => 403 ) );
		}
		return $this->ok( array( 'ok' => true ) );
	}
}
