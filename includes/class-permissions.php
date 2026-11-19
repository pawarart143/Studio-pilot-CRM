<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Role → permission matrix. The SPA uses it to hide menus; the REST layer
 * re-checks every request.
 *
 * Business access (money, reports, the team directory, business settings and
 * the plan) belongs to the studio owner and admins only. Team roles never get
 * the keys in business_permissions(), so every page, API route and dashboard
 * widget that checks those keys is closed to them.
 */
class Permissions {

	public static function matrix() {
		$all = array(
			'dashboard.view',
			'leads.view', 'leads.edit', 'leads.delete', 'leads.import', 'leads.view_all',
			'quotes.view', 'quotes.edit', 'quotes.delete',
			'clients.view', 'clients.edit', 'clients.delete',
			'bookings.view', 'bookings.view_all', 'bookings.edit', 'bookings.delete', 'bookings.assign',
			'payments.view', 'payments.edit',
			'deliverables.view', 'deliverables.view_all', 'deliverables.edit', 'deliverables.update',
			'portal.manage',
			'crew.view', 'crew.edit', 'crew.payments',
			'enquiry.manage',
			'expenses.view', 'expenses.edit',
			'calendar.view',
			'tasks.view', 'tasks.edit', 'tasks.view_all',
			'team.view', 'team.edit',
			'reports.view',
			'settings.edit',
			'whatsapp.send',
		);

		// Creative crew update the deliverables assigned to them (status, progress, link).
		$creative = array( 'dashboard.view', 'calendar.view', 'bookings.view', 'clients.view', 'tasks.view', 'tasks.edit', 'deliverables.view', 'deliverables.update' );

		$business = self::business_permissions();
		$strip    = function ( $perms ) use ( $business ) {
			return array_values( array_diff( $perms, $business ) );
		};

		return array(
			'owner'              => $all,
			'admin'              => $all,
			// Team roles go through $strip so a later edit can never hand them business access.
			'sales'              => $strip( array(
				'dashboard.view', 'leads.view', 'leads.edit', 'leads.import', 'leads.view_all',
				'quotes.view', 'quotes.edit',
				'clients.view', 'clients.edit',
				'bookings.view', 'bookings.view_all', 'bookings.edit',
				'deliverables.view', 'deliverables.view_all',
				'calendar.view',
				'tasks.view', 'tasks.edit',
				'whatsapp.send',
			) ),
			'production_manager' => $strip( array(
				'dashboard.view', 'leads.view', 'leads.view_all', 'quotes.view',
				'clients.view', 'clients.edit',
				'bookings.view', 'bookings.view_all', 'bookings.edit', 'bookings.assign',
				'deliverables.view', 'deliverables.view_all', 'deliverables.edit', 'deliverables.update',
				'calendar.view',
				'tasks.view', 'tasks.edit', 'tasks.view_all',
				'whatsapp.send',
				'crew.view', 'crew.edit',
			) ),
			'photographer'       => $strip( $creative ),
			'cinematographer'    => $strip( $creative ),
			'editor'             => $strip( $creative ),
			'album_designer'     => $strip( $creative ),
		);
	}

	/**
	 * Keys that make up the "Business" area. Only owner and admin hold them.
	 */
	public static function business_permissions() {
		return array(
			'payments.view', 'payments.edit',
			'expenses.view', 'expenses.edit',
			'crew.payments',
			'reports.view',
			'team.view', 'team.edit',
			'settings.edit',
			'portal.manage',
			'enquiry.manage',
		);
	}

	public static function for_role( $role ) {
		$m = self::matrix();
		return isset( $m[ $role ] ) ? $m[ $role ] : array();
	}

	/**
	 * Payment records can only be changed by the studio owner or an admin. This
	 * is checked in addition to the permission matrix, so a role edit can never
	 * hand payment changes to someone else by accident.
	 */
	public static function is_admin( $member ) {
		return $member && in_array( $member->role, array( 'owner', 'admin' ), true );
	}

	public static function can( $member, $perm ) {
		if ( ! $member ) {
			return false;
		}
		return in_array( $perm, self::for_role( $member->role ), true );
	}
}
