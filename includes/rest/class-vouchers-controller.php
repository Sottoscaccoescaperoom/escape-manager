<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Voucher_Repository;
use EscapeManager\Services\Voucher_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Vouchers_Controller extends Rest_Controller_Base {

	public function __construct(
		private Voucher_Repository $repo = new Voucher_Repository(),
		private Voucher_Service $service = new Voucher_Service()
	) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/vouchers', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_admin' ),
				'permission_callback' => $this->require_capability( 'em_view_payments' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $this->require_capability( 'em_manage_payments' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/vouchers/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => $this->require_capability( 'em_manage_payments' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/vouchers/validate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'validate' ),
			'permission_callback' => $this->public_permission(),
		) );
	}

	public function list_admin( \WP_REST_Request $req ): \WP_REST_Response {
		$status = $req->get_param( 'status' );
		return em_json_data( $this->repo->all( $status ?: null ) );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		$res  = $this->service->issue( $body );
		if ( is_wp_error( $res ) ) return $this->wp_error_response( $res );
		return em_json_data( $res, 201 );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$this->repo->update( $id, $this->body( $req ) );
		return em_json_data( $this->repo->find( $id ) );
	}

	public function validate( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		$res  = $this->service->validate_and_compute( (string) ( $body['code'] ?? '' ), (int) ( $body['amount_cents'] ?? 0 ) );
		if ( is_wp_error( $res ) ) return $this->wp_error_response( $res );
		return em_json_data( $res );
	}
}
