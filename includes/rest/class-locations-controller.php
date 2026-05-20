<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Location_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Locations_Controller extends Rest_Controller_Base {

	public function __construct( private Location_Repository $repo = new Location_Repository() ) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/locations', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_public' ),
				'permission_callback' => $this->public_permission(),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $this->require_capability( 'em_manage_settings' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/locations/(?P<id>\d+)', array(
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
	}

	public function list_public( \WP_REST_Request $req ): \WP_REST_Response {
		return em_json_data( $this->repo->all_active() );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		$id   = $this->repo->create( $body );
		return em_json_data( $this->repo->find( $id ), 201 );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req['id'];
		$body = $this->body( $req );
		$this->repo->update( $id, $body );
		return em_json_data( $this->repo->find( $id ) );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$this->repo->soft_delete( $id );
		return new \WP_REST_Response( null, 204 );
	}
}
