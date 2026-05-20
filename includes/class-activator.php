<?php
/**
 * Plugin activator: crea/aggiorna tabelle, ruoli, settings di default.
 *
 * @package EscapeManager
 */

namespace EscapeManager;

use EscapeManager\Auth\Capabilities;
use EscapeManager\Auth\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {

	public static function activate(): void {
		Database::run_migrations();
		Capabilities::register();
		Roles::seed();
		self::seed_default_settings();
		self::seed_roles_and_permissions_tables();

		update_option( 'em_db_version', EM_DB_VERSION );
	}

	private static function seed_default_settings(): void {
		$defaults = array(
			'em_lock_ttl_minutes'      => 10,
			'em_currency'              => 'EUR',
			'em_timezone'              => 'Europe/Rome',
			'em_idle_timeout_minutes'  => 15,
			'em_required_fields_public' => wp_json_encode(
				array(
					'first_name' => true,
					'last_name'  => false,
					'phone'      => true,
					'email'      => true,
					'birthday'   => false,
					'address'    => false,
				)
			),
			'em_required_fields_admin' => wp_json_encode(
				array(
					'first_name' => true,
					'last_name'  => false,
					'phone'      => true,
					'email'      => false,
					'birthday'   => false,
					'address'    => false,
				)
			),
			'em_purge_on_uninstall'    => false,
			'em_sottoscacco_bridge_enabled' => 0,
			'em_sottoscacco_webhook_url'    => '',
			'em_sottoscacco_webhook_secret' => '',
			'em_sottoscacco_max_retries'    => 5,
			'em_sottoscacco_external_id_prefix' => 'em-',
			'em_booking_code_prefix'        => 'EM',
			'em_email_from_name'            => '',
			'em_email_from_address'         => '',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value );
			}
		}
	}

	private static function seed_roles_and_permissions_tables(): void {
		global $wpdb;

		$roles_table       = $wpdb->prefix . 'em_roles';
		$permissions_table = $wpdb->prefix . 'em_permissions';

		$roles = array(
			array( 'slug' => 'super_admin',  'name' => 'Super Admin',  'description' => 'Accesso totale al sistema' ),
			array( 'slug' => 'admin',        'name' => 'Admin',        'description' => 'Gestisce prenotazioni, stanze, clienti, tariffe' ),
			array( 'slug' => 'manager',      'name' => 'Manager',      'description' => 'Responsabile operativo turno' ),
			array( 'slug' => 'game_master',  'name' => 'Game Master',  'description' => 'Conduce le partite' ),
			array( 'slug' => 'staff',        'name' => 'Staff',        'description' => 'Accesso limitato operativo' ),
			array( 'slug' => 'read_only',    'name' => 'Read Only',    'description' => 'Sola lettura' ),
		);

		foreach ( $roles as $role ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$roles_table} WHERE slug = %s LIMIT 1", $role['slug'] )
			);

			if ( $existing ) {
				$role_id = (int) $existing;
			} else {
				$wpdb->insert(
					$roles_table,
					array(
						'name'        => $role['name'],
						'slug'        => $role['slug'],
						'description' => $role['description'],
						'created_at'  => current_time( 'mysql', true ),
					),
					array( '%s', '%s', '%s', '%s' )
				);
				$role_id = (int) $wpdb->insert_id;
			}

			$matrix = Roles::permission_matrix();
			$perms  = $matrix[ $role['slug'] ] ?? array();

			foreach ( $perms as $permission_key => $allowed ) {
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$permissions_table} WHERE role_id = %d AND permission_key = %s LIMIT 1",
						$role_id,
						$permission_key
					)
				);

				if ( $exists ) {
					$wpdb->update(
						$permissions_table,
						array( 'allowed' => $allowed ? 1 : 0 ),
						array( 'id' => $exists ),
						array( '%d' ),
						array( '%d' )
					);
				} else {
					$wpdb->insert(
						$permissions_table,
						array(
							'role_id'        => $role_id,
							'permission_key' => $permission_key,
							'allowed'        => $allowed ? 1 : 0,
						),
						array( '%d', '%s', '%d' )
					);
				}
			}
		}
	}
}
