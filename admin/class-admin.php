<?php
/**
 * Admin bootstrap: registra menu, enqueue asset CRM.
 *
 * In Sprint 1 il menu mostra solo un placeholder che conferma l'attivazione
 * del plugin e indica i prossimi step. Il bundle React CRM verrà montato
 * qui in Sprint 4.
 *
 * @package EscapeManager
 */

namespace EscapeManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	public const MENU_SLUG = 'escape-manager';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_activation_notice' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Escape Manager', 'escape-manager' ),
			__( 'Escape Manager', 'escape-manager' ),
			'em_view_dashboard',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-tickets-alt',
			3
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'escape-manager' ),
			__( 'Dashboard', 'escape-manager' ),
			'em_view_dashboard',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Diagnostica', 'escape-manager' ),
			__( 'Diagnostica', 'escape-manager' ),
			'manage_options',
			'escape-manager-diagnostics',
			array( $this, 'render_diagnostics_page' )
		);
	}

	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'em_view_dashboard' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'escape-manager' ) );
		}
		require EM_PLUGIN_DIR . 'admin/views/admin-page.php';
	}

	public function render_diagnostics_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'escape-manager' ) );
		}
		require EM_PLUGIN_DIR . 'admin/views/diagnostics-page.php';
	}

	public function maybe_show_activation_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'escape-manager' ) === false ) {
			return;
		}

		$db_version = get_option( 'em_db_version', '0' );
		if ( version_compare( $db_version, EM_DB_VERSION, '<' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Schema DB Escape Manager non aggiornato. Disattiva e riattiva il plugin per applicare le migrazioni.', 'escape-manager' )
			);
		}
	}
}
