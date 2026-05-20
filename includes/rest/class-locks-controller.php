<?php
namespace EscapeManager\Rest;

use EscapeManager\Services\Lock_Service;
use EscapeManager\Helpers\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Locks_Controller extends Rest_Controller_Base {

	public function __construct( private Lock_Service $service = new Lock_Service() ) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/temporary-lock', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create' ),
			'permission_callback' => $this->public_permission(),
		) );

		register_rest_route( self::NAMESPACE, '/temporary-lock/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete' ),
			'permission_callback' => $this->public_permission(),
		) );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$body       = $this->body( $req );
		$room_id    = (int) ( $body['room_id'] ?? 0 );
		$start      = (string) ( $body['start_datetime'] ?? '' );
		$session_id = Validator::required_string( $body['session_id'] ?? '', 64 );
		$phone      = isset( $body['phone'] ) ? Validator::phone( (string) $body['phone'] ) : null;

		if ( ! $room_id || ! $start || ! $session_id ) {
			return em_json_error( 'VALIDATION', __( 'room_id, start_datetime, session_id obbligatori.', 'escape-manager' ), 400 );
		}

		$utc = Validator::to_utc( $start, em_setting( 'em_timezone', 'Europe/Rome' ) );
		if ( ! $utc ) {
			return em_json_error( 'VALIDATION', __( 'start_datetime non valido.', 'escape-manager' ), 400 );
		}

		$result = $this->service->acquire( $room_id, $utc, $session_id, $phone );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_response( $result );
		}
		return em_json_data( $result, 201 );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$id         = (int) $req['id'];
		$session_id = $this->str_param( $req, 'session_id', '' );
		if ( ! $session_id ) {
			$body = $this->body( $req );
			$session_id = (string) ( $body['session_id'] ?? '' );
		}
		if ( ! $session_id ) {
			return em_json_error( 'VALIDATION', 'session_id mancante', 400 );
		}
		$ok = $this->service->release( $id, $session_id );
		return $ok ? new \WP_REST_Response( null, 204 ) : em_json_error( 'NOT_FOUND', 'Lock non trovato', 404 );
	}
}
