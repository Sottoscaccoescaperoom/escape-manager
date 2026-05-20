<?php
/**
 * Plugin deactivator.
 *
 * Disabilita cron e attività in background.
 * NON cancella tabelle né opzioni (lo fa solo uninstall.php se opt-in).
 *
 * @package EscapeManager
 */

namespace EscapeManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {

	public static function deactivate(): void {
		$cron_hooks = array(
			'em_cron_lock_cleanup',
			'em_cron_reminders',
		);

		foreach ( $cron_hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
			wp_clear_scheduled_hook( $hook );
		}
	}
}
