<?php
/**
 * Pagina diagnostica — utile in sviluppo per verificare stato plugin.
 *
 * @package EscapeManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

$roles_count       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}em_roles" );
$permissions_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}em_permissions" );

$wp_roles_em = array();
foreach ( wp_roles()->roles as $slug => $role ) {
	if ( strpos( $slug, 'em_' ) === 0 ) {
		$wp_roles_em[ $slug ] = $role['name'];
	}
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Escape Manager — Diagnostica', 'escape-manager' ); ?></h1>

	<h2><?php esc_html_e( 'Versioni', 'escape-manager' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<tr><th><?php esc_html_e( 'Plugin', 'escape-manager' ); ?></th><td><?php echo esc_html( EM_VERSION ); ?></td></tr>
			<tr><th><?php esc_html_e( 'DB Schema', 'escape-manager' ); ?></th><td><?php echo esc_html( get_option( 'em_db_version', '0' ) ); ?> (atteso: <?php echo esc_html( EM_DB_VERSION ); ?>)</td></tr>
			<tr><th>PHP</th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
			<tr><th>WordPress</th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
			<tr><th>MySQL</th><td><?php echo esc_html( $wpdb->db_version() ); ?></td></tr>
			<tr><th>Timezone WP</th><td><?php echo esc_html( wp_timezone_string() ); ?></td></tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Settings di default', 'escape-manager' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<tr><th>em_lock_ttl_minutes</th><td><?php echo esc_html( (string) get_option( 'em_lock_ttl_minutes' ) ); ?></td></tr>
			<tr><th>em_currency</th><td><?php echo esc_html( (string) get_option( 'em_currency' ) ); ?></td></tr>
			<tr><th>em_timezone</th><td><?php echo esc_html( (string) get_option( 'em_timezone' ) ); ?></td></tr>
			<tr><th>em_idle_timeout_minutes</th><td><?php echo esc_html( (string) get_option( 'em_idle_timeout_minutes' ) ); ?></td></tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Ruoli e permessi', 'escape-manager' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: 1: roles count 2: permissions count */
			esc_html__( 'Ruoli in em_roles: %1$d — Permessi in em_permissions: %2$d', 'escape-manager' ),
			$roles_count,
			$permissions_count
		);
		?>
	</p>

	<h3><?php esc_html_e( 'Ruoli WordPress custom registrati', 'escape-manager' ); ?></h3>
	<table class="widefat striped">
		<thead>
			<tr><th>Slug</th><th>Display name</th></tr>
		</thead>
		<tbody>
			<?php foreach ( $wp_roles_em as $slug => $name ) : ?>
				<tr><td><code><?php echo esc_html( $slug ); ?></code></td><td><?php echo esc_html( $name ); ?></td></tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Utente corrente', 'escape-manager' ); ?></h2>
	<?php
	$user = wp_get_current_user();
	$em_caps = array();
	foreach ( \EscapeManager\Auth\Capabilities::all() as $cap ) {
		$em_caps[ $cap ] = current_user_can( $cap ) ? '✅' : '⛔';
	}
	?>
	<p><strong><?php echo esc_html( $user->user_login ); ?></strong> — <?php echo esc_html( implode( ', ', $user->roles ) ); ?></p>
	<table class="widefat striped">
		<tbody>
			<?php foreach ( $em_caps as $cap => $val ) : ?>
				<tr><th><?php echo esc_html( $cap ); ?></th><td><?php echo esc_html( $val ); ?></td></tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
