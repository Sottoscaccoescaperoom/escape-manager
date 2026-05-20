<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Room_Repository;
use EscapeManager\Repositories\Room_Time_Slot_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rooms_Controller extends Rest_Controller_Base {

	public function __construct(
		private Room_Repository $rooms = new Room_Repository(),
		private Room_Time_Slot_Repository $time_slots = new Room_Time_Slot_Repository()
	) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/rooms', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_public' ),
				'permission_callback' => $this->public_permission(),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $this->require_capability( 'em_manage_rooms' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/rooms/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $this->public_permission(),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => $this->require_capability( 'em_manage_rooms' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => $this->require_capability( 'em_manage_rooms' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/rooms/(?P<id>\d+)/time-slots', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_slots' ),
				'permission_callback' => $this->require_capability( 'em_view_rooms' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'replace_slots' ),
				'permission_callback' => $this->require_capability( 'em_manage_rooms' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/rooms/admin', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_admin' ),
			'permission_callback' => $this->require_capability( 'em_view_rooms' ),
		) );
	}

	public function list_public( \WP_REST_Request $req ): \WP_REST_Response {
		$location_id = $this->int_param( $req, 'location_id' );
		return em_json_data( $this->rooms->all_active( $location_id ) );
	}

	public function list_admin( \WP_REST_Request $req ): \WP_REST_Response {
		return em_json_data( $this->rooms->all_for_admin() );
	}

	public function show( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req['id'];
		$room = $this->rooms->find( $id );
		if ( ! $room ) {
			return em_json_error( 'NOT_FOUND', __( 'Stanza non trovata.', 'escape-manager' ), 404 );
		}
		$room['time_slots'] = $this->time_slots->by_room( $id );
		return em_json_data( $room );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		if ( empty( $body['name'] ) || empty( $body['location_id'] ) ) {
			return em_json_error( 'VALIDATION', __( 'Nome e location_id obbligatori.', 'escape-manager' ), 400 );
		}
		$id = $this->rooms->create( $body );
		return em_json_data( $this->rooms->find( $id ), 201 );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$this->rooms->update( $id, $this->body( $req ) );
		return em_json_data( $this->rooms->find( $id ) );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$this->rooms->soft_delete( $id );
		return new \WP_REST_Response( null, 204 );
	}

	public function list_slots( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		return em_json_data( $this->time_slots->by_room( $id ) );
	}

	public function replace_slots( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req['id'];
		$body = $this->body( $req );
		$slots = isset( $body['slots'] ) && is_array( $body['slots'] ) ? $body['slots'] : array();
		$this->time_slots->delete_by_room( $id );
		foreach ( $slots as $s ) {
			$this->time_slots->create( array_merge( $s, array( 'room_id' => $id ) ) );
		}
		return em_json_data( $this->time_slots->by_room( $id ) );
	}
}
