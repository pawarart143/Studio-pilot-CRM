<?php
namespace PCRM\Rest;

use PCRM\Db;
use PCRM\Tenant;
use PCRM\Validator;

defined( 'ABSPATH' ) || exit;

abstract class Base {

	protected $ns = 'pcrm/v1';

	abstract public function register();

	/**
	 * Registers a route. $perm is a permission key, 'public', 'member' or 'super'.
	 */
	protected function route( $path, $method, $callback, $perm ) {
		register_rest_route(
			$this->ns,
			$path,
			array(
				'methods'             => $method,
				'callback'            => function ( $req ) use ( $callback ) {
					try {
						$res = call_user_func( $callback, $req );
					} catch ( \Throwable $e ) {
						error_log( '[pcrm] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
						return new \WP_Error( 'server_error', 'Something went wrong. Please try again.', array( 'status' => 500 ) );
					}
					return $res;
				},
				'permission_callback' => function ( $req ) use ( $perm, $path ) {
					$ok = self::check( $perm );
					if ( true === $ok && 'public' !== $perm && 'super' !== $perm ) {
						$ok = self::check_plan( $req, $path );
					}
					return $ok;
				},
			)
		);
	}

	public static function check( $perm ) {
		if ( 'public' === $perm ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'not_logged_in', 'Please sign in to continue.', array( 'status' => 401 ) );
		}
		if ( 'super' === $perm ) {
			return Tenant::is_super_admin() ? true : new \WP_Error( 'forbidden', 'Super admin only.', array( 'status' => 403 ) );
		}
		$member = Tenant::member();
		if ( ! $member ) {
			return new \WP_Error( 'no_studio', 'You are not part of any studio yet.', array( 'status' => 403 ) );
		}
		$studio = Tenant::studio();
		if ( ! $studio || 'active' !== $studio->status ) {
			return new \WP_Error( 'studio_suspended', 'This studio account is suspended. Please contact support.', array( 'status' => 403 ) );
		}
		if ( 'member' === $perm ) {
			return true;
		}
		foreach ( (array) $perm as $p ) {
			if ( Tenant::can( $p ) ) {
				return true;
			}
		}
		return new \WP_Error( 'forbidden', 'You do not have permission to do this.', array( 'status' => 403 ) );
	}

	/**
	 * Route prefixes that keep working when a trial or plan has ended in
	 * read-only mode, so the studio can still sign in, switch studio and pay.
	 */
	public static function read_only_allowed() {
		return apply_filters( 'pcrm_read_only_allowed_routes', array( '/billing', '/auth', '/me', '/payment-links', '/notifications' ) );
	}

	/**
	 * Read-only mode after a trial or plan ends: reading and exporting work,
	 * adding and changing data answers HTTP 402 with an upgrade message.
	 * Platform super admins (support) are not blocked.
	 */
	public static function check_plan( $req, $path ) {
		$method = $req instanceof \WP_REST_Request ? $req->get_method() : 'GET';
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return true;
		}
		foreach ( self::read_only_allowed() as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}
		$studio = Tenant::studio();
		if ( ! $studio || Tenant::is_super_admin() ) {
			return true;
		}
		$err = \PCRM\Plans::read_only_error( $studio );
		return $err ? $err : true;
	}

	protected function body( $req ) {
		$json = $req->get_json_params();
		return is_array( $json ) ? $json : $req->get_body_params();
	}

	protected function validate( $req, $schema, $partial = false ) {
		return Validator::validate( $this->body( $req ), $schema, $partial );
	}

	protected function not_found( $what = 'Record' ) {
		return new \WP_Error( 'not_found', "$what not found.", array( 'status' => 404 ) );
	}

	protected function paginate( $req ) {
		$page = max( 1, (int) $req->get_param( 'page' ) );
		$per  = (int) $req->get_param( 'per_page' );
		$per  = $per > 0 ? min( $per, 500 ) : 25;
		return array( $page, $per, ( $page - 1 ) * $per );
	}

	protected function sid() {
		return Tenant::studio_id();
	}

	protected function member_id() {
		$m = Tenant::member();
		return $m ? (int) $m->id : 0;
	}

	protected function like( $s ) {
		global $wpdb;
		return '%' . $wpdb->esc_like( $s ) . '%';
	}

	/**
	 * Builds "IN (%d,%d)" safely from ints.
	 */
	protected function ids_in( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		return $ids ? implode( ',', $ids ) : '0';
	}

	protected function ok( $data = array(), $status = 200 ) {
		return new \WP_REST_Response( $data, $status );
	}
}
