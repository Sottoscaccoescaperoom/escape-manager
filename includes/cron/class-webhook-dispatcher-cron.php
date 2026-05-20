<?php
namespace EscapeManager\Cron;

use EscapeManager\Services\Webhook_Dispatcher_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Webhook_Dispatcher_Cron {

	public const HOOK = 'em_cron_webhook_dispatcher';

	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'em_every_minute', self::HOOK );
		}
	}

	public function run(): void {
		( new Webhook_Dispatcher_Service() )->dispatch_due( 50 );
	}
}
