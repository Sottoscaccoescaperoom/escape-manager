<?php
/**
 * Pagina admin placeholder — Sprint 1.
 *
 * Verrà sostituita dal mount React del CRM in Sprint 4 (id `em-crm-root`).
 *
 * @package EscapeManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

$tables_to_check = array(
	'em_locations',
	'em_rooms',
	'em_room_time_slots',
	'em_room_blocked_periods',
	'em_bookings',
	'em_booking_participants',
	'em_customers',
	'em_employees',
	'em_roles',
	'em_permissions',
	'em_payments',
	'em_tariffs',
	'em_booking_rules',
	'em_promocodes',
	'em_vouchers',
	'em_notes',
	'em_tasks',
	'em_activity_logs',
	'em_temporary_locks',
	'em_settings',
);

$existing_tables = $wpdb->get_col(
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . 'em_' ) . '%' )
);
$existing_tables = array_map(
	static function ( $t ) use ( $prefix ) {
		return substr( $t, strlen( $prefix ) );
	},
	$existing_tables
);

$missing = array_diff( $tables_to_check, $existing_tables );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Escape Manager', 'escape-manager' ); ?></h1>

	<div class="notice notice-info inline">
		<p>
			<strong><?php esc_html_e( 'Sprint 1 — Fondamenta plugin attivata.', 'escape-manager' ); ?></strong><br>
			<?php esc_html_e( 'Database, ruoli, capability e settings di default sono stati creati.', 'escape-manager' ); ?>
		</p>
	</div>

	<h2><?php esc_html_e( 'Stato Database', 'escape-manager' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: 1: existing 2: expected */
			esc_html__( 'Tabelle Escape Manager rilevate: %1$d / %2$d', 'escape-manager' ),
			count( $tables_to_check ) - count( $missing ),
			count( $tables_to_check )
		);
		?>
	</p>

	<?php if ( ! empty( $missing ) ) : ?>
		<div class="notice notice-error inline">
			<p><strong><?php esc_html_e( 'Tabelle mancanti:', 'escape-manager' ); ?></strong></p>
			<ul style="list-style: disc; padding-left: 24px;">
				<?php foreach ( $missing as $m ) : ?>
					<li><code><?php echo esc_html( $prefix . $m ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<p><?php esc_html_e( 'Disattiva e riattiva il plugin per ricreare le tabelle mancanti.', 'escape-manager' ); ?></p>
		</div>
	<?php else : ?>
		<div class="notice notice-success inline">
			<p><?php esc_html_e( 'Tutte le tabelle sono presenti.', 'escape-manager' ); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Prossimi step', 'escape-manager' ); ?></h2>
	<ol>
		<li><?php esc_html_e( 'Sprint 2 — REST API minimale (rooms, locations, availability, temporary-lock).', 'escape-manager' ); ?></li>
		<li><?php esc_html_e( 'Sprint 3 — Booking pubblico React + shortcode [escape_booking].', 'escape-manager' ); ?></li>
		<li><?php esc_html_e( 'Sprint 4 — CRM core (calendario, bookings, customers).', 'escape-manager' ); ?></li>
		<li><?php esc_html_e( 'Sprint 5 — Pagamenti sul posto, email, activity log, idle timer.', 'escape-manager' ); ?></li>
	</ol>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=escape-manager-diagnostics' ) ); ?>" class="button">
			<?php esc_html_e( 'Apri diagnostica completa', 'escape-manager' ); ?>
		</a>
	</p>
</div>
