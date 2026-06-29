<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Event_Extra_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Event_Extras_Controller extends Rest_Controller_Base {

	public function __construct( private Event_Extra_Repository $repo = new Event_Extra_Repository() ) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/event-extras', array(
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

		register_rest_route( self::NAMESPACE, '/event-extras/(?P<id>\d+)', array(
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

		// Pubblico (booking): solo extra attivi, campi essenziali.
		register_rest_route( self::NAMESPACE, '/event-extras/public', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_public' ),
			'permission_callback' => $this->public_permission(),
		) );
	}

	public function list_admin( \WP_REST_Request $req ): \WP_REST_Response {
		return em_json_data( $this->repo->all() );
	}

	public function list_public( \WP_REST_Request $req ): \WP_REST_Response {
		$out = array_map( static function ( $e ) {
			return array(
				'id'          => (int) $e['id'],
				'title'       => $e['title'],
				'description' => $e['description'],
				'price_cents' => (int) $e['price_cents'],
				'event_types' => $e['event_types'], // CSV o "all"
			);
		}, $this->repo->active() );
		return em_json_data( $out );
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
