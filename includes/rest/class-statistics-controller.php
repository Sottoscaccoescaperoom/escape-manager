<?php
namespace EscapeManager\Rest;

use EscapeManager\Services\Statistics_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Statistics_Controller extends Rest_Controller_Base {

	public function __construct( private Statistics_Service $service = new Statistics_Service() ) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/statistics/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'overview' ),
			'permission_callback' => $this->require_capability( 'em_view_statistics' ),
		) );
	}

	public function overview( \WP_REST_Request $req ): \WP_REST_Response {
		$from = $this->str_param( $req, 'from', gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$to   = $this->str_param( $req, 'to', gmdate( 'Y-m-d' ) );
		$location_id = $this->int_param( $req, 'location_id' );

		$start_utc = $from . ' 00:00:00';
		$end_utc   = $to . ' 23:59:59';

		$data = $this->service->overview( $start_utc, $end_utc, $location_id );
		$data['occupancy_rate'] = $this->service->occupancy_rate( $start_utc, $end_utc, $location_id );

		return em_json_data( $data, 200, array( 'from' => $from, 'to' => $to ) );
	}
}
