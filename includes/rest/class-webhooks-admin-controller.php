<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Webhook_Queue_Repository;
use EscapeManager\Services\Webhook_Dispatcher_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Webhooks_Admin_Controller extends Rest_Controller_Base {

	public function __construct(
		private Webhook_Queue_Repository $queue = new Webhook_Queue_Repository(),
		private Webhook_Dispatcher_Service $dispatcher = new Webhook_Dispatcher_Service()
	) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/webhooks/queue', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'summary' ),
			'permission_callback' => $this->require_capability( 'em_manage_settings' ),
		) );

		register_rest_route( self::NAMESPACE, '/webhooks/replay-failed', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'replay_failed' ),
			'permission_callback' => $this->require_capability( 'em_manage_settings' ),
		) );

		register_rest_route( self::NAMESPACE, '/webhooks/dispatch-now', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'dispatch_now' ),
			'permission_callback' => $this->require_capability( 'em_manage_settings' ),
		) );
	}

	public function summary( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;
		$table = em_table( 'webhook_queue' );
		$recent = $wpdb->get_results(
			"SELECT id, target, event_type, status, attempts, last_error, next_attempt_at, sent_at, created_at
			FROM {$table}
			ORDER BY id DESC
			LIMIT 50",
			ARRAY_A
		) ?: array();
		return em_json_data( array(
			'summary' => $this->queue->summary(),
			'recent'  => $recent,
		) );
	}

	public function replay_failed( \WP_REST_Request $req ): \WP_REST_Response {
		$count = $this->queue->replay_failed();
		return em_json_data( array( 'replayed' => $count ) );
	}

	public function dispatch_now( \WP_REST_Request $req ): \WP_REST_Response {
		return em_json_data( $this->dispatcher->dispatch_due( 50 ) );
	}
}
