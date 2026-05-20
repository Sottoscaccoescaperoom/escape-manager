<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package EscapeManager
 */

namespace EscapeManager;

use EscapeManager\Admin\Admin;
use EscapeManager\Rest\Rest_Router;
use EscapeManager\Cron\Lock_Cleanup;
use EscapeManager\Cron\Webhook_Dispatcher_Cron;
use EscapeManager\Services\Notification_Service;
use EscapeManager\Services\Sottoscacco_Bridge_Service;
use EscapeManager\Public_App\Public_App;

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

		// REST API sempre registrate (anche front-end le usa)
		( new Rest_Router() )->register_hooks();

		// Cron
		( new Lock_Cleanup() )->register_hooks();
		( new Webhook_Dispatcher_Cron() )->register_hooks();

		// Notification + Bridge ascoltano hook em_booking_*
		( new Notification_Service() )->register_hooks();
		( new Sottoscacco_Bridge_Service() )->register_hooks();

		// Public shortcode + assets
		if ( class_exists( Public_App::class ) ) {
			( new Public_App() )->register();
		}

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
