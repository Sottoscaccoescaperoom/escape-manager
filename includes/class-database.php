<?php
/**
 * Migrazioni database versionate.
 *
 * Tutte le tabelle vengono create/aggiornate via dbDelta.
 * Schema documentato in PROGETTO.md sezione 4.
 *
 * @package EscapeManager
 */

namespace EscapeManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Database {

	public static function run_migrations(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$queries = self::schema( $p, $charset_collate );

		foreach ( $queries as $sql ) {
			dbDelta( $sql );
		}
	}

	private static function schema( string $p, string $charset_collate ): array {
		return array(

			// 1) locations
			"CREATE TABLE {$p}em_locations (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(191) NOT NULL,
				address VARCHAR(255) NULL,
				city VARCHAR(120) NULL,
				postal_code VARCHAR(20) NULL,
				country VARCHAR(80) NULL,
				latitude DECIMAL(10,7) NULL,
				longitude DECIMAL(10,7) NULL,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY is_active (is_active),
				KEY deleted_at (deleted_at)
			) {$charset_collate};",

			// 2) rooms
			"CREATE TABLE {$p}em_rooms (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				location_id BIGINT UNSIGNED NOT NULL,
				name VARCHAR(191) NOT NULL,
				slug VARCHAR(191) NOT NULL,
				image_url VARCHAR(255) NULL,
				description LONGTEXT NULL,
				teaser TEXT NULL,
				important_info TEXT NULL,
				duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
				min_players TINYINT UNSIGNED NOT NULL DEFAULT 2,
				max_players TINYINT UNSIGNED NOT NULL DEFAULT 6,
				minimum_age TINYINT UNSIGNED NULL,
				difficulty TINYINT UNSIGNED NULL,
				fear_level TINYINT UNSIGNED NULL,
				room_type VARCHAR(60) NULL,
				has_actors TINYINT(1) NOT NULL DEFAULT 0,
				tags LONGTEXT NULL,
				sort_order INT NOT NULL DEFAULT 0,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY location_id (location_id),
				KEY is_active (is_active),
				KEY deleted_at (deleted_at)
			) {$charset_collate};",

			// 3) room_time_slots
			"CREATE TABLE {$p}em_room_time_slots (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				day_of_week TINYINT UNSIGNED NOT NULL,
				start_time TIME NOT NULL,
				end_time TIME NOT NULL,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY room_day (room_id, day_of_week),
				KEY is_active (is_active)
			) {$charset_collate};",

			// 4) room_blocked_periods
			"CREATE TABLE {$p}em_room_blocked_periods (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				start_datetime DATETIME NOT NULL,
				end_datetime DATETIME NOT NULL,
				reason VARCHAR(255) NULL,
				created_by BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY room_range (room_id, start_datetime, end_datetime)
			) {$charset_collate};",

			// 5) customers
			"CREATE TABLE {$p}em_customers (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				first_name VARCHAR(120) NOT NULL,
				last_name VARCHAR(120) NULL,
				phone VARCHAR(40) NULL,
				email VARCHAR(191) NULL,
				birthday DATE NULL,
				address VARCHAR(255) NULL,
				total_bookings INT UNSIGNED NOT NULL DEFAULT 0,
				last_booking_date DATETIME NULL,
				last_room_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY phone (phone),
				KEY email (email),
				KEY deleted_at (deleted_at)
			) {$charset_collate};",

			// 6) bookings
			"CREATE TABLE {$p}em_bookings (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_code VARCHAR(32) NOT NULL,
				room_id BIGINT UNSIGNED NOT NULL,
				location_id BIGINT UNSIGNED NOT NULL,
				customer_id BIGINT UNSIGNED NULL,
				start_datetime DATETIME NOT NULL,
				end_datetime DATETIME NOT NULL,
				timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Rome',
				adults TINYINT UNSIGNED NOT NULL DEFAULT 0,
				children TINYINT UNSIGNED NOT NULL DEFAULT 0,
				children_reduced TINYINT UNSIGNED NOT NULL DEFAULT 0,
				children_free TINYINT UNSIGNED NOT NULL DEFAULT 0,
				total_players TINYINT UNSIGNED NOT NULL DEFAULT 0,
				total_amount BIGINT NOT NULL DEFAULT 0,
				addons_amount BIGINT NOT NULL DEFAULT 0,
				paid_amount BIGINT NOT NULL DEFAULT 0,
				payment_method VARCHAR(40) NULL,
				payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
				booking_status VARCHAR(30) NOT NULL DEFAULT 'temporary_lock',
				source VARCHAR(40) NULL,
				event_type VARCHAR(40) NULL,
				event_label VARCHAR(191) NULL,
				customer_comment TEXT NULL,
				internal_notes TEXT NULL,
				cancellation_reason VARCHAR(255) NULL,
				created_by BIGINT UNSIGNED NULL,
				assigned_staff_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				expires_at DATETIME NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY booking_code (booking_code),
				KEY room_start (room_id, start_datetime),
				KEY start_datetime (start_datetime),
				KEY booking_status (booking_status),
				KEY payment_status (payment_status),
				KEY customer_id (customer_id),
				KEY location_id (location_id),
				KEY deleted_at (deleted_at)
			) {$charset_collate};",

			// 7) booking_participants
			"CREATE TABLE {$p}em_booking_participants (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT UNSIGNED NOT NULL,
				name VARCHAR(191) NULL,
				phone VARCHAR(40) NULL,
				email VARCHAR(191) NULL,
				type VARCHAR(20) NOT NULL DEFAULT 'adult',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY booking_id (booking_id)
			) {$charset_collate};",

			// 8) employees
			"CREATE TABLE {$p}em_employees (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				wp_user_id BIGINT UNSIGNED NULL,
				first_name VARCHAR(120) NOT NULL,
				last_name VARCHAR(120) NULL,
				email VARCHAR(191) NULL,
				phone VARCHAR(40) NULL,
				role_id BIGINT UNSIGNED NULL,
				position VARCHAR(120) NULL,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				last_visit DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY wp_user_id (wp_user_id),
				KEY role_id (role_id),
				KEY is_active (is_active)
			) {$charset_collate};",

			// 9) roles
			"CREATE TABLE {$p}em_roles (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(120) NOT NULL,
				slug VARCHAR(60) NOT NULL,
				description VARCHAR(255) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$charset_collate};",

			// 10) permissions
			"CREATE TABLE {$p}em_permissions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				role_id BIGINT UNSIGNED NOT NULL,
				permission_key VARCHAR(80) NOT NULL,
				allowed TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY role_perm (role_id, permission_key)
			) {$charset_collate};",

			// 11) payments
			"CREATE TABLE {$p}em_payments (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT UNSIGNED NOT NULL,
				amount BIGINT NOT NULL DEFAULT 0,
				payment_method VARCHAR(40) NOT NULL,
				payment_status VARCHAR(30) NOT NULL DEFAULT 'paid',
				transaction_id VARCHAR(120) NULL,
				paid_at DATETIME NULL,
				created_by BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY booking_id (booking_id),
				KEY payment_status (payment_status)
			) {$charset_collate};",

			// 12) tariffs
			"CREATE TABLE {$p}em_tariffs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NULL,
				title VARCHAR(191) NOT NULL,
				min_players TINYINT UNSIGNED NOT NULL DEFAULT 1,
				max_players TINYINT UNSIGNED NOT NULL DEFAULT 6,
				price_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
				price_per_person BIGINT NOT NULL DEFAULT 0,
				fixed_price BIGINT NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY room_id (room_id)
			) {$charset_collate};",

			// 13) booking_rules
			"CREATE TABLE {$p}em_booking_rules (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				title VARCHAR(191) NOT NULL,
				block_online_before_hours INT UNSIGNED NOT NULL DEFAULT 2,
				cancellation_without_penalty_hours INT UNSIGNED NOT NULL DEFAULT 24,
				cancellation_fee BIGINT NOT NULL DEFAULT 0,
				booking_only_by_phone_hours INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id)
			) {$charset_collate};",

			// 14) promocodes
			"CREATE TABLE {$p}em_promocodes (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				code VARCHAR(60) NOT NULL,
				type VARCHAR(20) NOT NULL DEFAULT 'percent',
				value INT NOT NULL DEFAULT 0,
				usage_limit INT NULL,
				used_count INT NOT NULL DEFAULT 0,
				valid_from DATETIME NULL,
				valid_to DATETIME NULL,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY is_active (is_active)
			) {$charset_collate};",

			// 15) vouchers
			"CREATE TABLE {$p}em_vouchers (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				code VARCHAR(60) NOT NULL,
				customer_id BIGINT UNSIGNED NULL,
				amount BIGINT NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				valid_until DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY customer_id (customer_id),
				KEY status (status)
			) {$charset_collate};",

			// 16) notes
			"CREATE TABLE {$p}em_notes (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_type VARCHAR(40) NOT NULL,
				entity_id BIGINT UNSIGNED NOT NULL,
				note TEXT NOT NULL,
				created_by BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY entity (entity_type, entity_id)
			) {$charset_collate};",

			// 17) tasks
			"CREATE TABLE {$p}em_tasks (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_type VARCHAR(40) NOT NULL,
				entity_id BIGINT UNSIGNED NOT NULL,
				title VARCHAR(191) NOT NULL,
				description TEXT NULL,
				assigned_to BIGINT UNSIGNED NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'todo',
				due_date DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY entity (entity_type, entity_id),
				KEY assigned_to (assigned_to),
				KEY status (status)
			) {$charset_collate};",

			// 18) activity_logs
			"CREATE TABLE {$p}em_activity_logs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NULL,
				action VARCHAR(80) NOT NULL,
				entity_type VARCHAR(40) NULL,
				entity_id BIGINT UNSIGNED NULL,
				old_value LONGTEXT NULL,
				new_value LONGTEXT NULL,
				ip_address VARCHAR(45) NULL,
				user_agent VARCHAR(255) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY entity (entity_type, entity_id),
				KEY user_created (user_id, created_at)
			) {$charset_collate};",

			// 19) temporary_locks
			"CREATE TABLE {$p}em_temporary_locks (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				start_datetime DATETIME NOT NULL,
				end_datetime DATETIME NOT NULL,
				session_id VARCHAR(64) NOT NULL,
				customer_phone VARCHAR(40) NULL,
				expires_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY room_start (room_id, start_datetime),
				KEY expires_at (expires_at),
				KEY session_id (session_id)
			) {$charset_collate};",

			// 20) settings
			"CREATE TABLE {$p}em_settings (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				setting_key VARCHAR(120) NOT NULL,
				setting_value LONGTEXT NULL,
				autoload TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY setting_key (setting_key),
				KEY autoload (autoload)
			) {$charset_collate};",

			// 21) webhook_queue (em_db_version 2)
			"CREATE TABLE {$p}em_webhook_queue (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				target VARCHAR(40) NOT NULL DEFAULT 'sottoscacco',
				event_type VARCHAR(40) NOT NULL,
				payload LONGTEXT NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				last_error TEXT NULL,
				next_attempt_at DATETIME NULL,
				sent_at DATETIME NULL,
				booking_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY status_next (status, next_attempt_at),
				KEY booking_id (booking_id)
			) {$charset_collate};",
		);
	}
}
