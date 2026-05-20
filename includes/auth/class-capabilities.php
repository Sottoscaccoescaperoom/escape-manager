<?php
/**
 * Definizione capability custom di Escape Manager.
 *
 * Tutte le capability sono prefissate `em_` per evitare collisioni.
 * Vengono aggiunte ai ruoli WordPress mappati in Roles::seed().
 *
 * @package EscapeManager
 */

namespace EscapeManager\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {

	public const ALL = array(
		'em_view_dashboard',
		'em_view_calendar',
		'em_manage_calendar',
		'em_view_bookings',
		'em_manage_bookings',
		'em_delete_bookings',
		'em_view_customers',
		'em_manage_customers',
		'em_view_rooms',
		'em_manage_rooms',
		'em_view_settings',
		'em_manage_settings',
		'em_view_staff',
		'em_manage_staff',
		'em_manage_roles',
		'em_view_payments',
		'em_manage_payments',
		'em_view_statistics',
		'em_export_data',
	);

	public static function register(): void {
		// I capability vengono assegnati ai ruoli WP da Roles::seed().
		// Questo metodo è qui per estensioni future (logging, hook, validazione).
		do_action( 'em_capabilities_registered', self::ALL );
	}

	public static function all(): array {
		return self::ALL;
	}
}
