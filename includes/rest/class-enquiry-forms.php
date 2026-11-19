<?php
namespace PCRM\Rest;

use PCRM\Config;
use PCRM\Db;
use PCRM\Enquiry;
use PCRM\Permissions;
use PCRM\Plans;
use PCRM\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Enquiry form (owner/admin) and the submission history.
 */
class Enquiry_Forms extends Base {

	public function register() {
		$this->route( '/enquiry-form', 'GET', array( $this, 'show' ), 'enquiry.manage' );
		$this->route( '/enquiry-form', 'PUT', array( $this, 'update' ), 'enquiry.manage' );
		$this->route( '/enquiry-form/logo', 'POST', array( $this, 'logo' ), 'enquiry.manage' );
		$this->route( '/enquiry-form/logo', 'DELETE', array( $this, 'remove_logo' ), 'enquiry.manage' );
		$this->route( '/enquiry-form/fields', 'PUT', array( $this, 'save_fields' ), 'enquiry.manage' );
		$this->route( '/enquiry-form/fields', 'POST', array( $this, 'add_field' ), 'enquiry.manage' );
		$this->route( '/enquiry-form/fields/(?P<id>\d+)', 'DELETE', array( $this, 'delete_field' ), 'enquiry.manage' );
		$this->route( '/enquiry-submissions', 'GET', array( $this, 'submissions' ), 'leads.view' );
		$this->route( '/enquiry-submissions/(?P<id>\d+)', 'GET', array( $this, 'submission' ), 'leads.view' );
		$this->route( '/enquiry-submissions/(?P<id>\d+)/resolve', 'POST', array( $this, 'resolve' ), 'leads.edit' );
	}

	private function form() {
		return Enquiry::ensure_form( $this->sid() );
	}

	private function present( $form ) {
		global $wpdb;
		$sid = $this->sid();
		$f   = Db::cast( clone $form );
		$f->url              = Enquiry::url( $form );
		$f->assign_pool      = array_map( 'intval', (array) json_decode( (string) $form->assign_pool, true ) );
		$f->captcha_enabled  = (bool) ( $form->captcha_site_key && $form->captcha_secret );
		$f->captcha_secret   = $form->captcha_secret ? '••••••••' : '';
		$f->fields           = Enquiry::fields( (int) $form->id );
		foreach ( $f->fields as $fl ) {
			$fl->builtin_options = in_array( $fl->field_key, array( 'event_type', 'services', 'budget', 'heard_from', 'day_type' ), true );
		}
		$f->sections         = Enquiry::sections();
		$f->field_types      = Enquiry::field_types();
		$members             = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, role FROM ' . Db::t( 'members' ) . " WHERE studio_id=%d AND status='active' ORDER BY name", $sid ) );
		$f->assignable       = array_values(
			array_map(
				function ( $m ) {
					return array( 'id' => (int) $m->id, 'name' => $m->name, 'role' => $m->role );
				},
				array_filter(
					$members,
					function ( $m ) {
						return in_array( 'leads.edit', Permissions::for_role( $m->role ), true );
					}
				)
			)
		);
		$f->stats = array(
			'total'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enquiry_submissions' ) . ' WHERE studio_id=%d', $sid ) ),
			'this_month' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enquiry_submissions' ) . ' WHERE studio_id=%d AND created_at >= %s', $sid, Db::date( 'Y-m-01 00:00:00' ) ) ),
			'review'     => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enquiry_submissions' ) . " WHERE studio_id=%d AND status IN ('duplicate','on_hold')", $sid ) ),
		);
		return $f;
	}

	public function show() {
		$form = $this->form();
		return $form ? $this->ok( $this->present( $form ) ) : $this->not_found( 'Enquiry form' );
	}

	public function update( $req ) {
		$form = $this->form();
		$b    = $this->body( $req );
		$data = $this->validate(
			$req,
			array(
				'is_enabled'       => array( 'type' => 'bool' ),
				'title'            => array( 'type' => 'string', 'max' => 190, 'label' => 'Form title' ),
				'description'      => array( 'type' => 'text', 'label' => 'Form description' ),
				'brand_color'      => array( 'type' => 'string', 'max' => 7, 'label' => 'Brand colour' ),
				'success_title'    => array( 'type' => 'string', 'max' => 190, 'label' => 'Thank-you headline' ),
				'success_message'  => array( 'type' => 'text', 'label' => 'Thank-you message' ),
				'whatsapp_number'  => array( 'type' => 'phone', 'label' => 'WhatsApp number' ),
				'consent_text'     => array( 'type' => 'string', 'max' => 500, 'label' => 'Consent text' ),
				'assign_mode'      => array( 'type' => 'enum', 'options' => array( 'none' => 'Leave unassigned', 'fixed' => 'One person', 'round_robin' => 'Round robin' ), 'label' => 'Lead assignment' ),
				'assign_member_id' => array( 'type' => 'ref', 'table' => 'members', 'label' => 'Assigned person' ),
				'captcha_site_key' => array( 'type' => 'string', 'max' => 190, 'label' => 'Turnstile site key' ),
				'slug'             => array( 'type' => 'string', 'max' => 80, 'label' => 'Form address' ),
			),
			true
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['title'] ) && '' === trim( (string) $data['title'] ) ) {
			return new \WP_Error( 'validation_failed', 'Form title is required.', array( 'status' => 422, 'errors' => array( 'title' => 'Required.' ) ) );
		}
		if ( isset( $data['brand_color'] ) && ! preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $data['brand_color'] ) ) {
			return new \WP_Error( 'validation_failed', 'Brand colour must look like #F3724C.', array( 'status' => 422, 'errors' => array( 'brand_color' => 'Use a hex colour like #F3724C.' ) ) );
		}
		if ( isset( $data['slug'] ) ) {
			$slug = trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $data['slug'] ) ), '-' );
			if ( strlen( $slug ) < 3 ) {
				return new \WP_Error( 'validation_failed', 'The form address needs at least 3 letters or numbers.', array( 'status' => 422, 'errors' => array( 'slug' => 'At least 3 characters.' ) ) );
			}
			$data['slug'] = Enquiry::unique_slug( $slug, (int) $form->id );
		}
		if ( array_key_exists( 'assign_pool', $b ) ) {
			$pool = array();
			foreach ( (array) $b['assign_pool'] as $mid ) {
				if ( Db::exists( 'members', (int) $mid ) ) {
					$pool[] = (int) $mid;
				}
			}
			$data['assign_pool'] = wp_json_encode( array_values( array_unique( $pool ) ) );
		}
		if ( isset( $b['captcha_secret'] ) && '••••••••' !== $b['captcha_secret'] ) {
			$data['captcha_secret'] = '' === trim( (string) $b['captcha_secret'] ) ? null : sanitize_text_field( (string) $b['captcha_secret'] );
		}
		$mode = isset( $data['assign_mode'] ) ? $data['assign_mode'] : $form->assign_mode;
		if ( 'fixed' === $mode && empty( $data['assign_member_id'] ) && ! $form->assign_member_id ) {
			return new \WP_Error( 'validation_failed', 'Choose who gets the new leads.', array( 'status' => 422, 'errors' => array( 'assign_member_id' => 'Required.' ) ) );
		}
		if ( 'round_robin' === $mode ) {
			$pool = isset( $data['assign_pool'] ) ? json_decode( $data['assign_pool'], true ) : json_decode( (string) $form->assign_pool, true );
			if ( ! $pool ) {
				return new \WP_Error( 'validation_failed', 'Choose at least one person for round robin.', array( 'status' => 422, 'errors' => array( 'assign_pool' => 'Choose at least one person.' ) ) );
			}
		}
		$changes = array();
		foreach ( $data as $k => $v ) {
			if ( 'captcha_secret' !== $k && (string) $form->$k !== (string) $v ) {
				$changes[ $k ] = array( 'from' => $form->$k, 'to' => $v );
			}
		}
		Db::update( 'public_enquiry_forms', (int) $form->id, $data );
		if ( $changes ) {
			Db::audit( 'enquiry_form', (int) $form->id, 'updated', 'Enquiry form settings changed: ' . implode( ', ', array_keys( $changes ) ), $changes );
		}
		\PCRM\Plugin::purge_url( Enquiry::url( $form ) );
		return $this->show();
	}

	public function logo( $req ) {
		$form  = $this->form();
		$files = $req->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'validation_failed', 'Choose an image.', array( 'status' => 422 ) );
		}
		if ( (int) $files['file']['size'] > 2 * MB_IN_BYTES ) {
			return new \WP_Error( 'validation_failed', 'Logo must be 2 MB or smaller.', array( 'status' => 422 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$_FILES['file'] = $files['file'];
		$aid            = media_handle_upload( 'file', 0, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
		if ( is_wp_error( $aid ) ) {
			return new \WP_Error( 'upload_failed', $aid->get_error_message(), array( 'status' => 422 ) );
		}
		Db::update( 'public_enquiry_forms', (int) $form->id, array( 'logo_url' => wp_get_attachment_url( $aid ) ) );
		return $this->show();
	}

	public function remove_logo() {
		$form = $this->form();
		Db::update( 'public_enquiry_forms', (int) $form->id, array( 'logo_url' => null ) );
		return $this->show();
	}

	private function clean_options( $raw ) {
		$out = array();
		foreach ( (array) $raw as $o ) {
			$o = mb_substr( sanitize_text_field( (string) $o ), 0, 120 );
			if ( '' !== $o && ! in_array( $o, $out, true ) ) {
				$out[] = $o;
			}
		}
		return array_slice( $out, 0, 40 );
	}

	private function clean_show_for( $raw ) {
		return array_values( array_intersect( array_map( 'strval', (array) $raw ), array_keys( Config::event_types() ) ) );
	}

	/**
	 * Saves order, labels, required/on flags and options for all fields at once.
	 */
	public function save_fields( $req ) {
		global $wpdb;
		$form = $this->form();
		$b    = $this->body( $req );
		$list = isset( $b['fields'] ) && is_array( $b['fields'] ) ? $b['fields'] : array();
		$have = array();
		foreach ( Enquiry::fields( (int) $form->id ) as $f ) {
			$have[ $f->id ] = $f;
		}
		$errors = array();
		$sort   = 10;
		foreach ( $list as $i => $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( ! isset( $have[ $id ] ) ) {
				continue;
			}
			$f     = $have[ $id ];
			$label = isset( $row['label'] ) ? mb_substr( sanitize_text_field( (string) $row['label'] ), 0, 190 ) : $f->label;
			if ( '' === $label ) {
				$errors[ 'fields.' . $i ] = 'Every field needs a label.';
				continue;
			}
			$upd = array(
				'label'       => $label,
				'placeholder' => isset( $row['placeholder'] ) ? mb_substr( sanitize_text_field( (string) $row['placeholder'] ), 0, 190 ) : $f->placeholder,
				'help'        => isset( $row['help'] ) ? mb_substr( sanitize_text_field( (string) $row['help'] ), 0, 255 ) : $f->help,
				'is_required' => ! empty( $row['is_required'] ) ? 1 : 0,
				'is_enabled'  => ! empty( $row['is_enabled'] ) ? 1 : 0,
				'show_for'    => isset( $row['show_for'] ) && $this->clean_show_for( $row['show_for'] ) ? wp_json_encode( $this->clean_show_for( $row['show_for'] ) ) : null,
				'sort'        => $sort,
			);
			if ( ! $f->is_core && isset( $row['section'] ) && isset( Enquiry::sections()[ $row['section'] ] ) ) {
				$upd['section'] = $row['section'];
			}
			if ( in_array( $f->type, array( 'select', 'radio', 'multiselect' ), true ) && ! in_array( $f->field_key, array( 'event_type', 'services', 'budget', 'heard_from', 'day_type' ), true ) && isset( $row['options'] ) ) {
				$opts = $this->clean_options( $row['options'] );
				if ( ! $opts ) {
					$errors[ 'fields.' . $i ] = '"' . $label . '" needs at least one option.';
					continue;
				}
				$upd['options'] = wp_json_encode( $opts );
			}
			if ( in_array( $f->field_key, Enquiry::locked_keys(), true ) ) {
				$upd['is_required'] = 1;
				$upd['is_enabled']  = 1;
				$upd['show_for']    = null;
			}
			if ( 'day_type' === $f->field_key || 'event_date' === $f->field_key ) {
				$upd['show_for'] = null;
			}
			$sort += 10;
			Db::update( 'enquiry_form_fields', $id, $upd );
		}
		if ( $errors ) {
			return new \WP_Error( 'validation_failed', reset( $errors ), array( 'status' => 422, 'errors' => $errors ) );
		}
		Db::audit( 'enquiry_form', (int) $form->id, 'fields', 'Enquiry form fields saved' );
		\PCRM\Plugin::purge_url( Enquiry::url( $form ) );
		return $this->show();
	}

	public function add_field( $req ) {
		global $wpdb;
		$form = $this->form();
		$b    = $this->body( $req );
		$type = isset( $b['type'] ) ? (string) $b['type'] : '';
		if ( ! isset( Enquiry::field_types()[ $type ] ) ) {
			return new \WP_Error( 'validation_failed', 'Choose a question type.', array( 'status' => 422, 'errors' => array( 'type' => 'Required.' ) ) );
		}
		$label = isset( $b['label'] ) ? mb_substr( sanitize_text_field( (string) $b['label'] ), 0, 190 ) : '';
		if ( '' === $label ) {
			return new \WP_Error( 'validation_failed', 'Write the question.', array( 'status' => 422, 'errors' => array( 'label' => 'Required.' ) ) );
		}
		$opts = array();
		if ( in_array( $type, array( 'select', 'radio', 'multiselect' ), true ) ) {
			$opts = $this->clean_options( isset( $b['options'] ) ? $b['options'] : array() );
			if ( ! $opts ) {
				return new \WP_Error( 'validation_failed', 'Add at least one option.', array( 'status' => 422, 'errors' => array( 'options' => 'Add at least one option.' ) ) );
			}
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enquiry_form_fields' ) . ' WHERE form_id=%d AND is_core=0', $form->id ) );
		if ( $count >= 25 ) {
			return new \WP_Error( 'validation_failed', 'You can add up to 25 custom questions.', array( 'status' => 422 ) );
		}
		$section = isset( $b['section'] ) && isset( Enquiry::sections()[ $b['section'] ] ) ? $b['section'] : 'additional';
		$key     = 'q_' . substr( sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) ), 0, 40 );
		$base    = $key;
		$i       = 2;
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'enquiry_form_fields' ) . ' WHERE form_id=%d AND field_key=%s', $form->id, $key ) ) ) {
			$key = $base . '_' . $i++;
		}
		// Place it at the end of its section.
		$sort = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort) FROM ' . Db::t( 'enquiry_form_fields' ) . ' WHERE form_id=%d', $form->id ) ) + 10;
		$id   = Db::insert(
			'enquiry_form_fields',
			array(
				'form_id'     => (int) $form->id,
				'field_key'   => $key,
				'type'        => $type,
				'label'       => $label,
				'placeholder' => isset( $b['placeholder'] ) ? mb_substr( sanitize_text_field( (string) $b['placeholder'] ), 0, 190 ) : null,
				'help'        => isset( $b['help'] ) ? mb_substr( sanitize_text_field( (string) $b['help'] ), 0, 255 ) : null,
				'options'     => $opts ? wp_json_encode( $opts ) : null,
				'section'     => $section,
				'is_core'     => 0,
				'is_required' => ! empty( $b['is_required'] ) ? 1 : 0,
				'is_enabled'  => 1,
				'show_for'    => ! empty( $b['show_for'] ) && $this->clean_show_for( $b['show_for'] ) ? wp_json_encode( $this->clean_show_for( $b['show_for'] ) ) : null,
				'sort'        => $sort,
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Db::audit( 'enquiry_form', (int) $form->id, 'field_added', 'Custom question added: ' . $label );
		\PCRM\Plugin::purge_url( Enquiry::url( $form ) );
		return $this->show();
	}

	public function delete_field( $req ) {
		$form = $this->form();
		$f    = Db::find( 'enquiry_form_fields', (int) $req['id'] );
		if ( ! $f || (int) $f->form_id !== (int) $form->id ) {
			return $this->not_found( 'Question' );
		}
		if ( (int) $f->is_core ) {
			return new \WP_Error( 'locked', 'Built-in fields can be turned off but not deleted, because they fill in the lead.', array( 'status' => 409 ) );
		}
		Db::delete( 'enquiry_form_fields', (int) $f->id );
		Db::audit( 'enquiry_form', (int) $form->id, 'field_deleted', 'Custom question deleted: ' . $f->label );
		\PCRM\Plugin::purge_url( Enquiry::url( $form ) );
		return $this->show();
	}

	/* ---------- Submissions ---------- */

	public function submissions( $req ) {
		global $wpdb;
		list( $page, $per, $off ) = $this->paginate( $req );
		$sid = $this->sid();
		$w   = array( $wpdb->prepare( 's.studio_id=%d', $sid ) );
		$st  = (string) $req->get_param( 'status' );
		if ( 'review' === $st ) {
			$w[] = "s.status IN ('duplicate','on_hold')";
		} elseif ( $st && isset( Config::enquiry_statuses()[ $st ] ) ) {
			$w[] = $wpdb->prepare( 's.status=%s', $st );
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$l     = $this->like( $q );
			$digit = preg_replace( '/\D/', '', $q );
			$w[]   = $wpdb->prepare( '(s.name LIKE %s OR s.email LIKE %s' . ( strlen( $digit ) >= 4 ? $wpdb->prepare( ' OR s.phone LIKE %s', '%' . $digit . '%' ) : '' ) . ')', $l, $l );
		}
		// Sales people who only see their own leads see only their submissions.
		if ( ! Tenant::can( 'leads.view_all' ) ) {
			$w[] = $wpdb->prepare( 'l.assigned_to=%d', $this->member_id() );
		}
		$where = implode( ' AND ', $w );
		$from  = Db::t( 'enquiry_submissions' ) . ' s LEFT JOIN ' . Db::t( 'leads' ) . ' l ON l.id=COALESCE(s.lead_id, s.duplicate_lead_id) AND l.studio_id=s.studio_id';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $from WHERE $where" );
		$rows  = $wpdb->get_results( "SELECT s.id, s.status, s.name, s.phone, s.email, s.event_type, s.event_date, s.lead_id, s.duplicate_lead_id, s.utm_source, s.utm_medium, s.utm_campaign, s.created_at, l.name AS lead_name, l.stage AS lead_stage FROM $from WHERE $where ORDER BY s.id DESC LIMIT $per OFFSET $off" );
		return $this->ok( array( 'items' => Db::cast_all( $rows ), 'total' => $total, 'page' => $page, 'per_page' => $per ) );
	}

	private function load_submission( $id ) {
		global $wpdb;
		$s = Db::find( 'enquiry_submissions', $id );
		if ( ! $s ) {
			return null;
		}
		$link = $s->lead_id ? $s->lead_id : $s->duplicate_lead_id;
		if ( ! Tenant::can( 'leads.view_all' ) ) {
			$owner = $link ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT assigned_to FROM ' . Db::t( 'leads' ) . ' WHERE id=%d AND studio_id=%d', $link, $this->sid() ) ) : 0;
			if ( $owner !== $this->member_id() ) {
				return null;
			}
		}
		$s          = Db::cast( $s );
		$a          = json_decode( $s->answers, true );
		$s->answers = array_values(
			array_map(
				function ( $k, $v ) {
					return array( 'key' => $k, 'label' => $v['label'], 'value' => $v['display'], 'section' => $v['section'] );
				},
				array_keys( $a['fields'] ),
				$a['fields']
			)
		);
		unset( $s->ip_hash, $s->token_hash, $s->user_agent );
		$s->reference      = 'EQ-' . str_pad( (string) $s->id, 6, '0', STR_PAD_LEFT );
		$s->lead           = $s->lead_id ? Db::cast( $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, stage, phone FROM ' . Db::t( 'leads' ) . ' WHERE id=%d AND studio_id=%d', $s->lead_id, $this->sid() ) ) ) : null;
		$s->duplicate_lead = $s->duplicate_lead_id ? Db::cast( $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, stage, phone, email, event_date, city, created_at FROM ' . Db::t( 'leads' ) . ' WHERE id=%d AND studio_id=%d', $s->duplicate_lead_id, $this->sid() ) ) ) : null;
		return $s;
	}

	public function submission( $req ) {
		$s = $this->load_submission( (int) $req['id'] );
		return $s ? $this->ok( $s ) : $this->not_found( 'Enquiry' );
	}

	/**
	 * Duplicate / on-hold workflow:
	 * - create_lead: create a separate new lead from this enquiry.
	 * - merge: add the enquiry to the existing lead; only empty fields are
	 *   filled in, nothing already on the lead is changed.
	 * - dismiss: keep it in the history without a lead.
	 */
	public function resolve( $req ) {
		$id  = (int) $req['id'];
		$sub = Db::find( 'enquiry_submissions', $id );
		if ( ! $sub || ! $this->load_submission( $id ) ) {
			return $this->not_found( 'Enquiry' );
		}
		if ( ! in_array( $sub->status, array( 'duplicate', 'on_hold' ), true ) ) {
			return new \WP_Error( 'already_handled', 'This enquiry was already handled.', array( 'status' => 409 ) );
		}
		$b      = $this->body( $req );
		$action = isset( $b['action'] ) ? (string) $b['action'] : '';
		$sid    = $this->sid();
		$ref    = 'EQ-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT );
		if ( 'create_lead' === $action ) {
			$limit = Plans::check( Tenant::studio(), 'leads' );
			if ( is_wp_error( $limit ) ) {
				return $limit;
			}
			$dup     = $sub->duplicate_lead_id ? Db::find( 'leads', (int) $sub->duplicate_lead_id ) : null;
			$lead_id = Enquiry::create_lead( $sub, $sid, $dup );
			if ( is_wp_error( $lead_id ) ) {
				return $lead_id;
			}
			Db::update( 'enquiry_submissions', $id, array( 'status' => 'separate', 'lead_id' => $lead_id, 'handled_by' => get_current_user_id(), 'handled_at' => Db::now() ) );
			Db::audit( 'enquiry_submission', $id, 'create_lead', $ref . ' created as a new lead #' . $lead_id );
		} elseif ( 'merge' === $action ) {
			$lead = $sub->duplicate_lead_id ? Db::find( 'leads', (int) $sub->duplicate_lead_id ) : null;
			if ( ! $lead ) {
				return new \WP_Error( 'validation_failed', 'There is no existing lead to add this to. Create a new lead instead.', array( 'status' => 422 ) );
			}
			$data = json_decode( $sub->answers, true );
			$v    = $data['values'];
			$fill = array();
			$map  = array( 'email' => 'email', 'event_type' => 'event_type', 'event_date' => 'event_date', 'event_end_date' => 'event_end_date', 'venue' => 'venue', 'city' => 'city', 'budget' => 'budget' );
			foreach ( $map as $col => $key ) {
				if ( ( null === $lead->$col || '' === $lead->$col ) && ! empty( $v[ $key ] ) ) {
					$fill[ $col ] = $v[ $key ];
				}
			}
			$existing = $lead->services ? (array) json_decode( $lead->services, true ) : array();
			$services = array_values( array_unique( array_merge( $existing, (array) $v['services'] ) ) );
			if ( $services !== $existing ) {
				$fill['services'] = wp_json_encode( $services );
			}
			if ( ! $lead->enquiry_submission_id ) {
				$fill['enquiry_submission_id'] = $id;
				$fill['form_url']              = $sub->form_url;
				foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $u ) {
					if ( ! $lead->$u && $sub->$u ) {
						$fill[ $u ] = $sub->$u;
					}
				}
			}
			$fill['notes'] = trim( (string) $lead->notes . "\n\n--- " . $ref . ' (' . \PCRM\Format::date( substr( $sub->created_at, 0, 10 ) ) . ") ---\n" . Enquiry::notes_for( $data ) );
			Db::update( 'leads', (int) $lead->id, $fill );
			Db::log( 'enquiry', 'Enquiry ' . $ref . ' added to this lead. Empty fields filled: ' . ( array_diff( array_keys( $fill ), array( 'notes', 'enquiry_submission_id', 'form_url' ) ) ? implode( ', ', array_diff( array_keys( $fill ), array( 'notes', 'enquiry_submission_id', 'form_url' ) ) ) : 'none' ) . '.', array( 'lead_id' => (int) $lead->id ) );
			Db::update( 'enquiry_submissions', $id, array( 'status' => 'merged', 'lead_id' => (int) $lead->id, 'handled_by' => get_current_user_id(), 'handled_at' => Db::now() ) );
			Db::audit( 'enquiry_submission', $id, 'merge', $ref . ' added to lead #' . $lead->id );
		} elseif ( 'dismiss' === $action ) {
			Db::update( 'enquiry_submissions', $id, array( 'status' => 'dismissed', 'handled_by' => get_current_user_id(), 'handled_at' => Db::now() ) );
			Db::audit( 'enquiry_submission', $id, 'dismiss', $ref . ' dismissed' );
		} else {
			return new \WP_Error( 'validation_failed', 'Choose what to do with this enquiry.', array( 'status' => 422 ) );
		}
		return $this->ok( $this->load_submission( $id ) );
	}
}
