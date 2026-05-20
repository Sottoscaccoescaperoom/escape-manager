<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Customer_Repository;
use EscapeManager\Repositories\Booking_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Customers_Controller extends Rest_Controller_Base {

	public function __construct(
		private Customer_Repository $repo = new Customer_Repository(),
		private Booking_Repository $bookings = new Booking_Repository()
	) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/customers', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_admin' ),
				'permission_callback' => $this->require_capability( 'em_view_customers' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $this->require_capability( 'em_manage_customers' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/customers/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $this->require_capability( 'em_view_customers' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => $this->require_capability( 'em_manage_customers' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/customers/search', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'search' ),
			'permission_callback' => $this->require_capability( 'em_view_customers' ),
		) );
	}

	public function list_admin( \WP_REST_Request $req ): \WP_REST_Response {
		$page     = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $req->get_param( 'per_page' ) ?: 50 ) );
		$res = $this->repo->paginate( $page, $per_page );
		return em_json_data( $res['rows'], 200, array(
			'pagination' => array( 'total' => $res['total'], 'page' => $res['page'], 'per_page' => $res['per_page'] ),
		) );
	}

	public function show( \WP_REST_Request $req ): \WP_REST_Response {
		$id  = (int) $req['id'];
		$row = $this->repo->find( $id );
		if ( ! $row ) return em_json_error( 'NOT_FOUND', 'Cliente non trovato', 404 );
		$row['bookings'] = $this->bookings->by_customer( $id );
		return em_json_data( $row );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		if ( empty( $body['first_name'] ) || empty( $body['phone'] ) ) {
			return em_json_error( 'VALIDATION', 'Nome e telefono obbligatori', 400 );
		}
		$id = $this->repo->create( $body );
		return em_json_data( $this->repo->find( $id ), 201 );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$this->repo->update( $id, $this->body( $req ) );
		return em_json_data( $this->repo->find( $id ) );
	}

	public function search( \WP_REST_Request $req ): \WP_REST_Response {
		$q = (string) $req->get_param( 'q' );
		if ( strlen( $q ) < 2 ) return em_json_data( array() );
		return em_json_data( $this->repo->search( $q ) );
	}
}
