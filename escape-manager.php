<?php
/**
 * Plugin Name:       Escape Manager
 * Plugin URI:        https://example.com/escape-manager
 * Description:       Sistema proprietario di gestione escape room: prenotazioni, CRM, calendario operativo, clienti, staff, pagamenti.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Luca D.
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       escape-manager
 * Domain Path:       /languages
 *
 * @package EscapeManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EM_VERSION', '0.2.0' );
define( 'EM_DB_VERSION', '2' );
define( 'EM_PLUGIN_FILE', __FILE__ );
define( 'EM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'EM_REST_NAMESPACE', 'escape-manager/v1' );

spl_autoload_register(
	static function ( $class ) {
		if ( strpos( $class, 'EscapeManager\\' ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( 'EscapeManager\\' ) );
		$parts    = explode( '\\', $relative );
		$class_part = array_pop( $parts );

		$directory_map = array(
			''        => 'includes',
			'Admin'   => 'admin',
			'Public_' => 'public',
		);

		$subpath = '';
		if ( ! empty( $parts ) ) {
			$top = $parts[0];
			if ( isset( $directory_map[ $top ] ) ) {
				$subpath = $directory_map[ $top ];
				array_shift( $parts );
				if ( ! empty( $parts ) ) {
					$subpath .= '/' . strtolower( implode( '/', $parts ) );
				}
			} else {
				$subpath = 'includes/' . strtolower( implode( '/', $parts ) );
			}
		} else {
			$subpath = 'includes';
		}

		$filename = 'class-' . strtolower( str_replace( '_', '-', $class_part ) ) . '.php';
		$path     = EM_PLUGIN_DIR . $subpath . '/' . $filename;

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'EscapeManager\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EscapeManager\\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\EscapeManager\Plugin::instance()->boot();
	}
);
