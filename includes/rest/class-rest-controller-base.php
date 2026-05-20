<?php
namespace EscapeManager\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base controller REST.
 * Espone helper di routing, permission, validation.
 */
abstract class Rest_Controller_Base {

	public const NAMESPACE = EM_REST_NAMESPACE;

	abstract public function register_routes(): void;

	protected function require_capability( string $cap ): callable {
		return static function () use ( $cap ): bool|\WP_Error {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'rest_forbidden', __( 'Devi essere autenticato.', 'escape-manager' ), array( 'status' => 401 ) );
			}
			if ( ! current_user_can( $cap ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'Permessi insufficienti.', 'escape-manager' ), array( 'status' => 403 ) );
			}
			return true;
		};
	}

	protected function public_permission(): callable {
		return static fn(): bool => true;
	}

	protected function body( \WP_REST_Request $req ): array {
		$json = $req->get_json_params();
		return is_array( $json ) ? $json : (array) $req->get_body_params();
	}

	protected function int_param( \WP_REST_Request $req, string $key, ?int $default = null ): ?int {
		$v = $req->get_param( $key );
		return ( $v === null || $v === '' ) ? $default : (int) $v;
	}

	protected function str_param( \WP_REST_Request $req, string $key, ?string $default = null ): ?string {
		$v = $req->get_param( $key );
		return ( $v === null || $v === '' ) ? $default : (string) $v;
	}

	protected function wp_error_response( \WP_Error $err ): \WP_REST_Response {
		$data = $err->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		return em_json_error( $err->get_error_code(), $err->get_error_message(), $status );
	}
}
