<?php
/**
 * Plugin Name:       Escape Manager
 * Plugin URI:        https://example.com/escape-manager
 * Description:       Sistema proprietario di gestione escape room: prenotazioni, CRM, calendario operativo, clienti, staff, pagamenti.
 * Version:           0.9.27
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

/**
 * ⚠️ QUESTO NUMERO VA ALZATO A OGNI RILASCIO, SEMPRE.
 *
 * Non è un'etichetta: è quello che WordPress appende ai file (`?ver=0.9.27`) e
 * quindi l'unica cosa che dice al browser di un cliente «questo file è
 * cambiato, riscaricalo». Lasciandolo fermo si carica il plugin nuovo sul
 * server e i clienti continuano a vedere quello vecchio, dalla loro cache —
 * un aggiornamento che risulta fatto e non si vede da nessuna parte, che è il
 * modo peggiore di fallire perché nessuno lo cerca più.
 */
define( 'EM_VERSION', '0.9.28' );
define( 'EM_DB_VERSION', '6' );
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

		$relative   = substr( $class, strlen( 'EscapeManager\\' ) );
		$parts      = explode( '\\', $relative );
		$class_part = array_pop( $parts );

		$top_map = array(
			'Admin'        => 'admin',
			'Public_App'   => 'public',
		);

		$subpath = 'includes';
		if ( ! empty( $parts ) ) {
			$top = $parts[0];
			if ( isset( $top_map[ $top ] ) ) {
				$subpath = $top_map[ $top ];
				array_shift( $parts );
			}
			if ( ! empty( $parts ) ) {
				$subpath .= '/' . strtolower( implode( '/', $parts ) );
			}
		}

		$filename = 'class-' . strtolower( str_replace( '_', '-', $class_part ) ) . '.php';
		$path     = EM_PLUGIN_DIR . $subpath . '/' . $filename;

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

// Carica helper functions globali (non sono classi)
require_once EM_PLUGIN_DIR . 'includes/helpers/functions.php';

register_activation_hook( __FILE__, array( 'EscapeManager\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EscapeManager\\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\EscapeManager\Plugin::instance()->boot();
	}
);
