<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Booking_Repository;
use EscapeManager\Repositories\Room_Repository;
use EscapeManager\Repositories\Customer_Repository;
use EscapeManager\Services\Availability_Service;
use EscapeManager\Helpers\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calendar aggregato per CRM:
 *  - GET /calendar?date=YYYY-MM-DD&view=day|week&location_id=
 *  Ritorna: stanze, slot config, bookings, locks per il periodo.
 */
final class Calendar_Controller extends Rest_Controller_Base {

	public function __construct(
		private Booking_Repository $bookings = new Booking_Repository(),
		private Room_Repository $rooms = new Room_Repository(),
		private Customer_Repository $customers = new Customer_Repository(),
		private Availability_Service $availability = new Availability_Service()
	) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/calendar', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get' ),
			'permission_callback' => $this->require_capability( 'em_view_calendar' ),
		) );
	}

	public function get( \WP_REST_Request $req ): \WP_REST_Response {
		$date        = $this->str_param( $req, 'date', gmdate( 'Y-m-d' ) );
		$view        = $this->str_param( $req, 'view', 'day' );
		$location_id = $this->int_param( $req, 'location_id' );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return em_json_error( 'VALIDATION', 'date richiesto YYYY-MM-DD', 400 );
		}

		$tz_string = em_setting( 'em_timezone', 'Europe/Rome' );
		try {
			$start_local = new \DateTimeImmutable( $date . ' 00:00:00', new \DateTimeZone( $tz_string ) );
		} catch ( \Exception $e ) {
			return em_json_error( 'VALIDATION', 'data non valida', 400 );
		}

		$end_local = $view === 'week'
			? $start_local->modify( '+7 days' )
			: $start_local->modify( '+1 day' );

		$start_utc = $start_local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$end_utc   = $end_local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		$bookings_raw = $this->bookings->for_date_range( $start_utc, $end_utc, array(
			'location_id'      => $location_id,
			'exclude_statuses' => array( 'cancelled', 'unsuccessful_booking', 'temporary_lock' ),
		) );

		$bookings = array_map( function ( $b ) {
			$room     = $this->rooms->find( (int) $b['room_id'] );
			$customer = $b['customer_id'] ? $this->customers->find( (int) $b['customer_id'] ) : null;
			return array(
				'id'              => (int) $b['id'],
				'booking_code'    => $b['booking_code'],
				'room_id'         => (int) $b['room_id'],
				'room_name'       => $room['name'] ?? '',
				'start_datetime'  => Validator::to_local( $b['start_datetime'] ) ?? $b['start_datetime'],
				'end_datetime'    => Validator::to_local( $b['end_datetime'] ) ?? $b['end_datetime'],
				'total_players'   => (int) $b['total_players'],
				'total_amount'    => (int) $b['total_amount'],
				'paid_amount'     => (int) $b['paid_amount'],
				'booking_status'  => $b['booking_status'],
				'payment_status'  => $b['payment_status'],
				'assigned_staff_id' => $b['assigned_staff_id'] ? (int) $b['assigned_staff_id'] : null,
				'customer'        => $customer ? array(
					'id'         => (int) $customer['id'],
					'first_name' => $customer['first_name'],
					'last_name'  => $customer['last_name'],
					'phone'      => $customer['phone'],
				) : null,
			);
		}, $bookings_raw );

		// Per la vista giorno includiamo anche gli slot di disponibilità (template + status)
		$availability = ( $view === 'day' )
			? $this->availability->slots_for_date( $date, null, $location_id )
			: null;

		$rooms = $this->rooms->all_active( $location_id );

		return em_json_data( array(
			'date'         => $date,
			'view'         => $view,
			'rooms'        => $rooms,
			'bookings'     => $bookings,
			'availability' => $availability,
		), 200, array(
			'timezone'     => $tz_string,
			'generated_at' => gmdate( 'c' ),
		) );
	}
}
