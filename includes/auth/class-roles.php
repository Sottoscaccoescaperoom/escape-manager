<?php
/**
 * Definizione e seeding ruoli WordPress + matrice permessi.
 *
 * Ruoli WP custom (prefissati `em_`):
 *   - em_super_admin, em_admin, em_manager, em_game_master, em_staff, em_read_only
 *
 * La matrice permessi è autoritativa: viene usata sia per assegnare capability
 * ai ruoli WP, sia per popolare la tabella em_permissions (vista CRM editabile in MVP3).
 *
 * @package EscapeManager
 */

namespace EscapeManager\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Roles {

	public static function seed(): void {
		$matrix = self::permission_matrix();

		foreach ( self::role_definitions() as $slug => $display_name ) {
			$wp_role_slug = 'em_' . $slug;
			$capabilities = array();

			foreach ( $matrix[ $slug ] as $cap_short => $allowed ) {
				if ( $allowed ) {
					$capabilities[ 'em_' . $cap_short ] = true;
				}
			}

			$existing = get_role( $wp_role_slug );
			if ( $existing ) {
				foreach ( Capabilities::all() as $cap ) {
					$existing->remove_cap( $cap );
				}
				foreach ( array_keys( $capabilities ) as $cap ) {
					$existing->add_cap( $cap );
				}
			} else {
				add_role( $wp_role_slug, $display_name, $capabilities );
			}
		}

		// Allinea admin WP nativo: assegna tutte le capability em_.
		$wp_admin = get_role( 'administrator' );
		if ( $wp_admin ) {
			foreach ( Capabilities::all() as $cap ) {
				$wp_admin->add_cap( $cap );
			}
		}
	}

	public static function role_definitions(): array {
		return array(
			'super_admin' => __( 'EM Super Admin', 'escape-manager' ),
			'admin'       => __( 'EM Admin', 'escape-manager' ),
			'manager'     => __( 'EM Manager', 'escape-manager' ),
			'game_master' => __( 'EM Game Master', 'escape-manager' ),
			'staff'       => __( 'EM Staff', 'escape-manager' ),
			'read_only'   => __( 'EM Read Only', 'escape-manager' ),
		);
	}

	public static function permission_matrix(): array {
		return array(
			'super_admin' => array(
				'view_dashboard'    => true,
				'view_calendar'     => true,
				'manage_calendar'   => true,
				'view_bookings'     => true,
				'manage_bookings'   => true,
				'delete_bookings'   => true,
				'view_customers'    => true,
				'manage_customers'  => true,
				'view_rooms'        => true,
				'manage_rooms'      => true,
				'view_settings'     => true,
				'manage_settings'   => true,
				'view_staff'        => true,
				'manage_staff'      => true,
				'manage_roles'      => true,
				'view_payments'     => true,
				'manage_payments'   => true,
				'view_statistics'   => true,
				'export_data'       => true,
			),
			'admin' => array(
				'view_dashboard'    => true,
				'view_calendar'     => true,
				'manage_calendar'   => true,
				'view_bookings'     => true,
				'manage_bookings'   => true,
				'delete_bookings'   => true,
				'view_customers'    => true,
				'manage_customers'  => true,
				'view_rooms'        => true,
				'manage_rooms'      => true,
				'view_settings'     => true,
				'manage_settings'   => true,
				'view_staff'        => true,
				'manage_staff'      => true,
				'manage_roles'      => false,
				'view_payments'     => true,
				'manage_payments'   => true,
				'view_statistics'   => true,
				'export_data'       => true,
			),
			'manager' => array(
				'view_dashboard'    => true,
				'view_calendar'     => true,
				'manage_calendar'   => true,
				'view_bookings'     => true,
				'manage_bookings'   => true,
				'delete_bookings'   => false,
				'view_customers'    => true,
				'manage_customers'  => true,
				'view_rooms'        => true,
				'manage_rooms'      => false,
				'view_settings'     => false,
				'manage_settings'   => false,
				'view_staff'        => true,
				'manage_staff'      => false,
				'manage_roles'      => false,
				'view_payments'     => true,
				'manage_payments'   => true,
				'view_statistics'   => true,
				'export_data'       => false,
			),
			'game_master' => array(
				'view_dashboard'    => true,
				'view_calendar'     => true,
				'manage_calendar'   => false,
				'view_bookings'     => true,
				'manage_bookings'   => true, // limitato lato app (solo → completed)
				'delete_bookings'   => false,
				'view_customers'    => false,
				'manage_customers'  => false,
				'view_rooms'        => true,
				'manage_rooms'      => false,
				'view_settings'     => false,
				'manage_settings'   => false,
				'view_staff'        => false,
				'manage_staff'      => false,
				'manage_roles'      => false,
				'view_payments'     => false,
				'manage_payments'   => false,
				'view_statistics'   => false,
				'export_data'       => false,
			),
			'staff' => array(
				'view_dashboard'    => true,
				'view_calendar'     => true,
				'manage_calendar'   => false,
				'view_bookings'     => true,
				'manage_bookings'   => false,
				'delete_bookings'   => false,
				'view_customers'    => false,
				'manage_customers'  => false,
				'view_rooms'        => true,
				'manage_rooms'      => false,
				'view_settings'     => false,
				'manage_settings'   => false,
				'view_staff'        => false,
				'manage_staff'      => false,
				'manage_roles'      => false,
				'view_payments'     => false,
				'manage_payments'   => false,
				'view_statistics'   => false,
				'export_data'       => false,
			),
			'read_only' => array(
				'view_dashboard'    => true,
				'view_calendar'     => true,
				'manage_calendar'   => false,
				'view_bookings'     => true,
				'manage_bookings'   => false,
				'delete_bookings'   => false,
				'view_customers'    => true,
				'manage_customers'  => false,
				'view_rooms'        => true,
				'manage_rooms'      => false,
				'view_settings'     => false,
				'manage_settings'   => false,
				'view_staff'        => false,
				'manage_staff'      => false,
				'manage_roles'      => false,
				'view_payments'     => true,
				'manage_payments'   => false,
				'view_statistics'   => true,
				'export_data'       => false,
			),
		);
	}
}
