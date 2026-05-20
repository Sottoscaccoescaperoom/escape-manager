<?php
namespace EscapeManager\Rest;

use EscapeManager\Services\Booking_Service;
use EscapeManager\Services\Payment_Service;
use EscapeManager\Repositories\Booking_Repository;
use EscapeManager\Repositories\Customer_Repository;
use EscapeManager\Repositories\Payment_Repository;
use EscapeManager\Repositories\Room_Repository;
use EscapeManager\Domain\Booking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bookings_Controller extends Rest_Controller_Base {

	public function __construct(
		private Booking_Service $service = new Booking_Service(),
		private Payment_Service $payments = new Payment_Service(),
		private Booking_Repository $bookings = new Booking_Repository(),
		private Customer_Repository $customers = new Customer_Repository(),
		private Payment_Repository $payment_repo = new Payment_Repository(),
		private Room_Repository $rooms = new Room_Repository()
	) {}

	public function register_routes(): void {
		// Pubblico: creazione da lock
		register_rest_route( self::NAMESPACE, '/bookings/public', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_public' ),
			'permission_callback' => $this->public_permission(),
		) );

		register_rest_route( self::NAMESPACE, '/bookings/public/(?P<code>[A-Za-z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_public' ),
			'permission_callback' => $this->public_permission(),
		) );

		// CRM list/create
		register_rest_route( self::NAMESPACE, '/bookings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_admin' ),
				'permission_callback' => $this->require_capability( 'em_view_bookings' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_manual' ),
				'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $this->require_capability( 'em_view_bookings' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => $this->require_capability( 'em_delete_bookings' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/confirm', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'confirm' ),
			'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
		) );

		register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'cancel' ),
			'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
		) );

		register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/payment', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'add_payment' ),
			'permission_callback' => $this->require_capability( 'em_manage_payments' ),
		) );

		register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/notes', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'add_note' ),
			'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
		) );

		register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/assign-staff', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'assign_staff' ),
			'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
		) );

		register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/transition', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'transition' ),
			'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
		) );
	}

	public function create_public( \WP_REST_Request $req ): \WP_REST_Response {
		$body   = $this->body( $req );
		$result = $this->service->create_from_public( $body );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_response( $result );
		}
		return em_json_data( $this->present( $result ), 201 );
	}

	public function get_public( \WP_REST_Request $req ): \WP_REST_Response {
		$code = (string) $req['code'];
		$b    = $this->bookings->find_by_code( $code );
		if ( ! $b ) {
			return em_json_error( 'NOT_FOUND', 'Prenotazione non trovata', 404 );
		}
		$room = $this->rooms->find( (int) $b['room_id'] );
		// Privacy: ritorna solo info essenziali
		return em_json_data( array(
			'booking_code'   => $b['booking_code'],
			'room_name'      => $room['name'] ?? '',
			'start_datetime' => $b['start_datetime'],
			'total_players'  => (int) $b['total_players'],
			'booking_status' => $b['booking_status'],
			'payment_status' => $b['payment_status'],
		) );
	}

	public function list_admin( \WP_REST_Request $req ): \WP_REST_Response {
		$filters = array(
			'room_id'        => $this->int_param( $req, 'room_id' ),
			'booking_status' => $this->str_param( $req, 'status' ),
			'payment_status' => $this->str_param( $req, 'payment_status' ),
			'from'           => $this->str_param( $req, 'from' ),
			'to'             => $this->str_param( $req, 'to' ),
			'customer_id'    => $this->int_param( $req, 'customer_id' ),
			'search'         => $this->str_param( $req, 'search' ),
		);
		$filters = array_filter( $filters, static fn( $v ) => $v !== null && $v !== '' );

		$page     = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $req->get_param( 'per_page' ) ?: 50 ) );

		$res = $this->bookings->paginate( $filters, $page, $per_page );
		$rows = array_map( fn( $r ) => $this->present( $r ), $res['rows'] );

		return em_json_data( $rows, 200, array(
			'pagination' => array(
				'total'    => $res['total'],
				'page'     => $res['page'],
				'per_page' => $res['per_page'],
			),
		) );
	}

	public function show( \WP_REST_Request $req ): \WP_REST_Response {
		$b = $this->bookings->find( (int) $req['id'] );
		if ( ! $b ) {
			return em_json_error( 'NOT_FOUND', 'Prenotazione non trovata', 404 );
		}
		$detail = $this->present( $b );
		$detail['payments'] = $this->payment_repo->by_booking( (int) $b['id'] );
		return em_json_data( $detail );
	}

	public function create_manual( \WP_REST_Request $req ): \WP_REST_Response {
		$result = $this->service->create_manual( $this->body( $req ) );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_response( $result );
		}
		return em_json_data( $this->present( $result ), 201 );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req['id'];
		$body = $this->body( $req );

		$existing = $this->bookings->find( $id );
		if ( ! $existing ) {
			return em_json_error( 'NOT_FOUND', 'Prenotazione non trovata', 404 );
		}

		// Whitelist campi modificabili
		$allowed = array(
			'customer_comment', 'internal_notes', 'adults', 'children',
			'total_players', 'total_amount', 'paid_amount',
			'payment_method', 'payment_status', 'assigned_staff_id',
		);
		$update = array_intersect_key( $body, array_flip( $allowed ) );

		if ( isset( $update['adults'] ) || isset( $update['children'] ) ) {
			$update['total_players'] = (int) ( $update['adults'] ?? $existing['adults'] )
				+ (int) ( $update['children'] ?? $existing['children'] );
		}

		$this->bookings->update( $id, $update );
		$updated = $this->bookings->find( $id );
		do_action( 'em_booking_updated', $updated, $existing );
		return em_json_data( $this->present( $updated ) );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$this->bookings->soft_delete( (int) $req['id'] );
		return new \WP_REST_Response( null, 204 );
	}

	public function confirm( \WP_REST_Request $req ): \WP_REST_Response {
		$res = $this->service->transition_to( (int) $req['id'], Booking::STATUS_CONFIRMED );
		if ( is_wp_error( $res ) ) return $this->wp_error_response( $res );
		return em_json_data( $this->present( $res ) );
	}

	public function cancel( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		$res  = $this->service->transition_to( (int) $req['id'], Booking::STATUS_CANCELLED, array( 'reason' => $body['reason'] ?? null ) );
		if ( is_wp_error( $res ) ) return $this->wp_error_response( $res );
		return em_json_data( $this->present( $res ) );
	}

	public function transition( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		$status = (string) ( $body['status'] ?? '' );
		$res = $this->service->transition_to( (int) $req['id'], $status, $body );
		if ( is_wp_error( $res ) ) return $this->wp_error_response( $res );
		return em_json_data( $this->present( $res ) );
	}

	public function add_payment( \WP_REST_Request $req ): \WP_REST_Response {
		$body   = $this->body( $req );
		$cents  = (int) ( $body['amount_cents'] ?? round( (float) ( $body['amount'] ?? 0 ) * 100 ) );
		$method = (string) ( $body['payment_method'] ?? 'on_site' );
		$result = $this->payments->add_payment( (int) $req['id'], $cents, $method, $body['transaction_id'] ?? null );
		if ( is_wp_error( $result ) ) return $this->wp_error_response( $result );
		return em_json_data( $result, 201 );
	}

	public function add_note( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		$note = trim( (string) ( $body['note'] ?? '' ) );
		if ( ! $note ) return em_json_error( 'VALIDATION', 'Nota vuota', 400 );
		$res = $this->service->add_note( (int) $req['id'], $note );
		return em_json_data( $res, 201 );
	}

	public function assign_staff( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		$staff_id = (int) ( $body['staff_id'] ?? 0 );
		$res = $this->service->assign_staff( (int) $req['id'], $staff_id );
		if ( is_wp_error( $res ) ) return $this->wp_error_response( $res );
		return em_json_data( $this->present( $res ) );
	}

	private function present( array $b ): array {
		$room     = $this->rooms->find( (int) $b['room_id'] );
		$customer = $b['customer_id'] ? $this->customers->find( (int) $b['customer_id'] ) : null;
		return array_merge( $b, array(
			'room'     => $room ? array( 'id' => (int) $room['id'], 'name' => $room['name'], 'slug' => $room['slug'] ) : null,
			'customer' => $customer ? array(
				'id'         => (int) $customer['id'],
				'first_name' => $customer['first_name'],
				'last_name'  => $customer['last_name'],
				'phone'      => $customer['phone'],
				'email'      => $customer['email'],
			) : null,
		) );
	}
}
