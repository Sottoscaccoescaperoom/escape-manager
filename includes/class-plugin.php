<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package EscapeManager
 */

namespace EscapeManager;

use EscapeManager\Admin\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'escape-manager', false, dirname( EM_PLUGIN_BASENAME ) . '/languages' );
	}

	public function maybe_upgrade(): void {
		$installed = get_option( 'em_db_version', '0' );
		if ( version_compare( $installed, EM_DB_VERSION, '<' ) ) {
			Activator::activate();
		}
	}
}
