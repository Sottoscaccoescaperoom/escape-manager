<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Room_Blocked_Period_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * §Lavagna+ 2026-07-01 — Room_Blocked_Periods_Controller
 *
 * CRUD dei blocchi manutenzione/chiusura per stanza + shortcut "blocca
 * giornata" da lavagna turni. I blocchi sono cumulativi: piu' blocchi
 * possono coesistere (per esempio una manutenzione parziale gia'
 * esistente + un blocco intera giornata sopra). Availability service li
 * considera tramite overlap.
 */
final class Room_Blocked_Periods_Controller extends Rest_Controller_Base {

	public function __construct( private Room_Blocked_Period_Repository $repo = new Room_Blocked_Period_Repository() ) {}

	public function register_routes(): void {
		// GET /rooms/{roomId}/blocks?date=YYYY-MM-DD  → blocchi che coprono il giorno.
		register_rest_route( self::NAMESPACE, '/rooms/(?P<room_id>\d+)/blocks', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_day' ),
				'permission_callback' => $this->require_capability( 'em_view_bookings' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
			),
		) );

		// POST /rooms/{roomId}/block-day { date, reason } — shortcut lavagna.
		register_rest_route( self::NAMESPACE, '/rooms/(?P<room_id>\d+)/block-day', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'block_day' ),
				'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'unblock_day' ),
				'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
			),
		) );

		// DELETE /blocks/{id}
		register_rest_route( self::NAMESPACE, '/blocks/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete' ),
			'permission_callback' => $this->require_capability( 'em_manage_bookings' ),
		) );
	}

	public function list_day( \WP_REST_Request $req ): \WP_REST_Response {
		$room_id = (int) $req['room_id'];
		$date    = (string) $req->get_param( 'date' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return em_json_error( 'invalid_date', 'Parametro date richiesto (YYYY-MM-DD)', 400 );
		}
		list( $start, $end ) = $this->day_range( $date );
		return em_json_data( $this->repo->list_range( $room_id, $start, $end ) );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$room_id = (int) $req['room_id'];
		$body    = $this->body( $req );
		$start   = (string) ( $body['start_datetime'] ?? '' );
		$end     = (string) ( $body['end_datetime']   ?? '' );
		if ( ! $start || ! $end || $start >= $end ) {
			return em_json_error( 'invalid_range', 'Intervallo blocco non valido', 400 );
		}
		$reason = isset( $body['reason'] ) ? (string) $body['reason'] : null;
		$id = $this->repo->create( $room_id, $start, $end, $reason, get_current_user_id() ?: null );
		return em_json_data( $this->repo->find( $id ), 201 );
	}

	public function block_day( \WP_REST_Request $req ): \WP_REST_Response {
		$room_id = (int) $req['room_id'];
		$body    = $this->body( $req );
		$date    = (string) ( $body['date'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return em_json_error( 'invalid_date', 'Parametro date richiesto (YYYY-MM-DD)', 400 );
		}
		$reason = isset( $body['reason'] ) ? (string) $body['reason'] : 'Giornata bloccata';
		list( $start, $end ) = $this->day_range( $date );
		$id = $this->repo->create( $room_id, $start, $end, $reason, get_current_user_id() ?: null );
		return em_json_data( array(
			'ok'    => true,
			'block' => $this->repo->find( $id ),
		), 201 );
	}

	public function unblock_day( \WP_REST_Request $req ): \WP_REST_Response {
		$room_id = (int) $req['room_id'];
		$date    = (string) $req->get_param( 'date' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return em_json_error( 'invalid_date', 'Parametro date richiesto (YYYY-MM-DD)', 400 );
		}
		list( $start, $end ) = $this->day_range( $date );
		$n = $this->repo->delete_day( $room_id, $start, $end );
		return em_json_data( array( 'ok' => true, 'deleted' => $n ) );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$this->repo->delete( (int) $req['id'] );
		return new \WP_REST_Response( null, 204 );
	}

	/** Range [00:00, 24:00) del giorno indicato. Il DB tratta timestamp
	 *  in orario locale del server WP, coerente con come li scrive il
	 *  resto del plugin (availability_service ecc.). */
	private function day_range( string $date ): array {
		return array( $date . ' 00:00:00', $date . ' 23:59:59' );
	}
}
