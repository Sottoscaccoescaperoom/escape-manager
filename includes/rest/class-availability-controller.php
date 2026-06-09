<?php
namespace EscapeManager\Rest;

use EscapeManager\Services\Availability_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Availability_Controller extends Rest_Controller_Base {

	public function __construct( private Availability_Service $service = new Availability_Service() ) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/availability', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get' ),
			'permission_callback' => $this->public_permission(),
		) );
	}

	public function get( \WP_REST_Request $req ): \WP_REST_Response {
		$date        = $this->str_param( $req, 'date', gmdate( 'Y-m-d' ) );
		$room_id     = $this->int_param( $req, 'room_id' );
		$location_id = $this->int_param( $req, 'location_id' );
		$days        = max( 1, min( 14, (int) $this->int_param( $req, 'days' ) ?: 1 ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return em_json_error( 'VALIDATION', __( 'date deve essere YYYY-MM-DD', 'escape-manager' ), 400 );
		}

		// Risposta unificata per-giorno: [{ date, rooms: [...] }]. Day view = 1 giorno, Week view = 7.
		$data = $this->service->slots_for_range( $date, $days, $room_id, $location_id );

		return em_json_data( $data, 200, array(
			'timezone'     => em_setting( 'em_timezone', 'Europe/Rome' ),
			'generated_at' => gmdate( 'c' ),
			'days'         => $days,
		) );
	}
}
