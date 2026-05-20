<?php
/**
 * Uninstall script.
 *
 * Eseguito solo quando l'utente disinstalla il plugin (non quando lo disattiva).
 * Per default NON cancella i dati: l'utente deve abilitare esplicitamente
 * l'opzione `em_purge_on_uninstall` prima di disinstallare.
 *
 * @package EscapeManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$purge = (bool) get_option( 'em_purge_on_uninstall', false );

if ( ! $purge ) {
	return;
}

global $wpdb;

$tables = array(
	'em_settings',
	'em_temporary_locks',
	'em_activity_logs',
	'em_tasks',
	'em_notes',
	'em_vouchers',
	'em_promocodes',
	'em_booking_rules',
	'em_tariffs',
	'em_payments',
	'em_permissions',
	'em_roles',
	'em_employees',
	'em_customers',
	'em_booking_participants',
	'em_bookings',
	'em_room_blocked_periods',
	'em_room_time_slots',
	'em_rooms',
	'em_locations',
);

foreach ( $tables as $table ) {
	$full = $wpdb->prefix . $table;
	$wpdb->query( "DROP TABLE IF EXISTS {$full}" );
}

$options = array(
	'em_db_version',
	'em_lock_ttl_minutes',
	'em_currency',
	'em_timezone',
	'em_required_fields_public',
	'em_required_fields_admin',
	'em_idle_timeout_minutes',
	'em_purge_on_uninstall',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

$role_slugs = array( 'em_super_admin', 'em_admin', 'em_manager', 'em_game_master', 'em_staff', 'em_read_only' );
foreach ( $role_slugs as $slug ) {
	remove_role( $slug );
}
