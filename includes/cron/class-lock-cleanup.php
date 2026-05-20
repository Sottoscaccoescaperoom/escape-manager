<?php
namespace EscapeManager\Cron;

use EscapeManager\Services\Lock_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lock_Cleanup {

	public const HOOK = 'em_cron_lock_cleanup';

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'add_minute_schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	public function add_minute_schedule( array $schedules ): array {
		if ( ! isset( $schedules['em_every_minute'] ) ) {
			$schedules['em_every_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every Minute (Escape Manager)', 'escape-manager' ),
			);
		}
		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'em_every_minute', self::HOOK );
		}
	}

	public function run(): void {
		( new Lock_Service() )->cleanup();
	}
}
