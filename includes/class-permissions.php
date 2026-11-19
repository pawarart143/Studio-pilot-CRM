<?php
namespace PCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Role → permission matrix. The SPA uses it to hide menus; the REST layer
 * re-checks every request.
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
		$creative = array( 'dashboard.view', 'calendar.view', 'bookings.view', 'clients.view', 'tasks.view', 'tasks.edit', 'team.view', 'deliverables.view', 'deliverables.update' );

		return array(
			'owner'              => $all,
			'admin'              => $all,
			'sales'              => array(
				'dashboard.view', 'leads.view', 'leads.edit', 'leads.import', 'leads.view_all',
				'quotes.view', 'quotes.edit',
				'clients.view', 'clients.edit',
				'bookings.view', 'bookings.view_all', 'bookings.edit',
				'payments.view',
				'deliverables.view', 'deliverables.view_all',
				'calendar.view',
				'tasks.view', 'tasks.edit',
				'team.view', 'reports.view', 'whatsapp.send',
			),
			'production_manager' => array(
				'dashboard.view', 'leads.view', 'leads.view_all', 'quotes.view',
				'clients.view', 'clients.edit',
				'bookings.view', 'bookings.view_all', 'bookings.edit', 'bookings.assign',
				'deliverables.view', 'deliverables.view_all', 'deliverables.edit', 'deliverables.update',
				'calendar.view',
				'tasks.view', 'tasks.edit', 'tasks.view_all',
				'team.view', 'reports.view', 'whatsapp.send',
				'crew.view', 'crew.edit',
			),
			'photographer'       => $creative,
			'cinematographer'    => $creative,
			'editor'             => $creative,
			'album_designer'     => $creative,
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
