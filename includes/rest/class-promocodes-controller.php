<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Promocode_Repository;
use EscapeManager\Services\Promocode_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Promocodes_Controller extends Rest_Controller_Base {

	public function __construct(
		private Promocode_Repository $repo = new Promocode_Repository(),
		private Promocode_Service $service = new Promocode_Service()
	) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/promocodes', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_admin' ),
				'permission_callback' => $this->require_capability( 'em_view_settings' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $this->require_capability( 'em_manage_settings' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/promocodes/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => $this->require_capability( 'em_manage_settings' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => $this->require_capability( 'em_manage_settings' ),
			),
		) );

		// Validazione pubblica (in step booking)
		register_rest_route( self::NAMESPACE, '/promocodes/validate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'validate' ),
			'permission_callback' => $this->public_permission(),
		) );
	}

	public function list_admin( \WP_REST_Request $req ): \WP_REST_Response {
		return em_json_data( $this->repo->all() );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		if ( empty( $body['code'] ) ) {
			return em_json_error( 'VALIDATION', 'Codice obbligatorio', 400 );
		}
		try {
			$id = $this->repo->create( $body );
			return em_json_data( $this->repo->find( $id ), 201 );
		} catch ( \Throwable $e ) {
			return em_json_error( 'CREATE_FAILED', $e->getMessage(), 500 );
		}
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$this->repo->update( $id, $this->body( $req ) );
		return em_json_data( $this->repo->find( $id ) );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$this->repo->delete( (int) $req['id'] );
		return new \WP_REST_Response( null, 204 );
	}

	public function validate( \WP_REST_Request $req ): \WP_REST_Response {
		$body   = $this->body( $req );
		$code   = (string) ( $body['code'] ?? '' );
		$amount = (int) ( $body['amount_cents'] ?? 0 );
		$res    = $this->service->validate_and_compute( $code, $amount );
		if ( is_wp_error( $res ) ) {
			return $this->wp_error_response( $res );
		}
		return em_json_data( $res );
	}
}
