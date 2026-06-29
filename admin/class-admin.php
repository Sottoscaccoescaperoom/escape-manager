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

	/** Mappa hook-admin → pagina del CRM (per iniettare initialPage). */
	private array $crm_hooks = array();

	/** Pagine del CRM esposte come sottomenu WP. slug => [label, page, cap]. */
	private static function crm_pages(): array {
		return array(
			self::MENU_SLUG             => array( 'label' => __( 'Calendario', 'escape-manager' ),    'page' => 'calendar',    'cap' => 'em_view_calendar' ),
			'escape-manager-bookings'   => array( 'label' => __( 'Prenotazioni', 'escape-manager' ),  'page' => 'bookings',    'cap' => 'em_view_bookings' ),
			'escape-manager-customers'  => array( 'label' => __( 'Clienti', 'escape-manager' ),       'page' => 'customers',   'cap' => 'em_view_customers' ),
			'escape-manager-statistics' => array( 'label' => __( 'Statistiche', 'escape-manager' ),   'page' => 'statistics',  'cap' => 'em_view_statistics' ),
			'escape-manager-rooms'      => array( 'label' => __( 'Stanze', 'escape-manager' ),        'page' => 'rooms',       'cap' => 'em_view_rooms' ),
			'escape-manager-tariffs'    => array( 'label' => __( 'Tariffe', 'escape-manager' ),       'page' => 'tariffs',     'cap' => 'em_view_settings' ),
			'escape-manager-extras'     => array( 'label' => __( 'Servizi extra', 'escape-manager' ), 'page' => 'event_extras', 'cap' => 'em_view_settings' ),
			'escape-manager-promocodes' => array( 'label' => __( 'Codici sconto', 'escape-manager' ), 'page' => 'promocodes',  'cap' => 'em_view_settings' ),
			'escape-manager-vouchers'   => array( 'label' => __( 'Voucher', 'escape-manager' ),       'page' => 'vouchers',    'cap' => 'em_view_payments' ),
			'escape-manager-settings'   => array( 'label' => __( 'Impostazioni', 'escape-manager' ),  'page' => 'settings',    'cap' => 'em_view_settings' ),
		);
	}

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
			array( $this, 'render_crm_page' ),
			'dashicons-tickets-alt',
			3
		);

		// Una voce di sottomenu per ogni pagina del CRM (tendina come Marketing/Impostazioni).
		foreach ( self::crm_pages() as $slug => $p ) {
			$hook = add_submenu_page(
				self::MENU_SLUG,
				$p['label'],
				$p['label'],
				$p['cap'],
				$slug,
				array( $this, 'render_crm_page' )
			);
			if ( $hook ) {
				$this->crm_hooks[ $hook ] = $p['page'];
			}
		}

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Bridge Sottoscacco', 'escape-manager' ),
			__( 'Bridge Sottoscacco', 'escape-manager' ),
			'em_manage_settings',
			'escape-manager-bridge',
			array( $this, 'render_bridge_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Diagnostica', 'escape-manager' ),
			__( 'Diagnostica', 'escape-manager' ),
			'manage_options',
			'escape-manager-diagnostics',
			array( $this, 'render_diagnostics_page' )
		);

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_crm_assets' ) );
		// Il bundle CRM usa import ESM (Preact/htm da CDN): va servito come modulo.
		add_filter( 'script_loader_tag', array( $this, 'module_script_tag' ), 10, 3 );
	}

	/**
	 * Forza type="module" sul bundle CRM (necessario per gli import ESM).
	 */
	public function module_script_tag( string $tag, string $handle, string $src ): string {
		if ( 'em-crm-app' !== $handle ) {
			return $tag;
		}
		return sprintf(
			'<script type="module" src="%s" id="%s-js"></script>' . "\n",
			esc_url( $src ),
			esc_attr( $handle )
		);
	}

	public function enqueue_crm_assets( string $hook ): void {
		// Carichiamo il CRM solo sulle pagine CRM (non su Bridge/Diagnostica).
		if ( ! isset( $this->crm_hooks[ $hook ] ) ) {
			return;
		}
		$initial_page = $this->crm_hooks[ $hook ];

		wp_register_script(
			'em-crm-app',
			EM_PLUGIN_URL . 'admin/crm-app/crm-app.js',
			array(),
			EM_VERSION,
			true
		);
		wp_register_style(
			'em-crm-app',
			EM_PLUGIN_URL . 'admin/crm-app/crm-app.css',
			array(),
			EM_VERSION
		);

		wp_localize_script( 'em-crm-app', 'EM_CRM_CONFIG', array(
			'apiBase'     => esc_url_raw( rest_url( EM_REST_NAMESPACE ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'timezone'    => em_setting( 'em_timezone', 'Europe/Rome' ),
			'currency'    => em_setting( 'em_currency', 'EUR' ),
			'initialPage' => $initial_page,
			'wpMenu'      => true,
		) );

		// Media Library per il caricamento icone/loghi stanza (wp.media nel CRM).
		wp_enqueue_media();

		wp_enqueue_script( 'em-crm-app' );
		wp_enqueue_style( 'em-crm-app' );
	}

	public function render_crm_page(): void {
		if ( ! current_user_can( 'em_view_dashboard' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'escape-manager' ) );
		}
		echo '<div class="wrap"><div id="em-crm-root"></div></div>';
	}

	public function render_bridge_page(): void {
		if ( ! current_user_can( 'em_manage_settings' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'escape-manager' ) );
		}
		require EM_PLUGIN_DIR . 'admin/views/bridge-page.php';
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
