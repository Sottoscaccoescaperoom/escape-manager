<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Tariff_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tariffs_Controller extends Rest_Controller_Base {

	public function __construct( private Tariff_Repository $repo = new Tariff_Repository() ) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/tariffs', array(
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

		register_rest_route( self::NAMESPACE, '/tariffs/(?P<id>\d+)', array(
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

		register_rest_route( self::NAMESPACE, '/tariffs/public', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_public' ),
			'permission_callback' => $this->public_permission(),
		) );
	}

	public function list_admin( \WP_REST_Request $req ): \WP_REST_Response {
		return em_json_data( $this->repo->all() );
	}

	public function list_public( \WP_REST_Request $req ): \WP_REST_Response {
		$room_id = (int) $req->get_param( 'room_id' );
		if ( ! $room_id ) {
			return em_json_data( array() );
		}
		return em_json_data( $this->repo->for_room( $room_id ) );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$id = $this->repo->create( $this->body( $req ) );
		return em_json_data( $this->repo->find( $id ), 201 );
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
}
