<?php
namespace EscapeManager\Services;

use EscapeManager\Domain\Booking;
use EscapeManager\Repositories\Room_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregazione statistiche dashboard.
 * Tutti gli importi in centesimi.
 */
final class Statistics_Service {

	public function __construct( private Room_Repository $rooms = new Room_Repository() ) {}

	/**
	 * Statistiche overview per range data (UTC).
	 *
	 * @return array{
	 *   total_bookings:int, confirmed:int, cancelled:int, completed:int, no_show:int,
	 *   total_revenue_cents:int, paid_revenue_cents:int, avg_party_size:float,
	 *   by_room:array, by_day:array
	 * }
	 */
	public function overview( string $start_utc, string $end_utc, ?int $location_id = null ): array {
		global $wpdb;
		$table = em_table( 'bookings' );

		$loc_clause = '';
		$params     = array( $start_utc, $end_utc );
		if ( $location_id ) {
			$loc_clause = ' AND location_id = %d';
			$params[]   = $location_id;
		}

		// Totali per stato
		$sql = "SELECT
				booking_status,
				COUNT(*) AS count,
				COALESCE(SUM(total_amount), 0) AS total_amount,
				COALESCE(SUM(paid_amount), 0) AS paid_amount,
				COALESCE(SUM(total_players), 0) AS players
			FROM {$table}
			WHERE deleted_at IS NULL
			AND start_datetime >= %s AND start_datetime <= %s
			{$loc_clause}
			GROUP BY booking_status";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();

		$result = array(
			'total_bookings'      => 0,
			'confirmed'           => 0,
			'cancelled'           => 0,
			'completed'           => 0,
			'no_show'             => 0,
			'total_revenue_cents' => 0,
			'paid_revenue_cents'  => 0,
			'avg_party_size'      => 0,
		);
		$total_players = 0;
		foreach ( $rows as $r ) {
			$count = (int) $r['count'];
			$result['total_bookings'] += $count;
			if ( $r['booking_status'] === Booking::STATUS_CONFIRMED ) $result['confirmed']  += $count;
			if ( $r['booking_status'] === Booking::STATUS_CANCELLED ) $result['cancelled']  += $count;
			if ( $r['booking_status'] === Booking::STATUS_COMPLETED ) $result['completed']  += $count;
			if ( $r['booking_status'] === Booking::STATUS_NO_SHOW )   $result['no_show']    += $count;

			// Revenue solo da booking non cancellati
			if ( ! in_array( $r['booking_status'], array( Booking::STATUS_CANCELLED, Booking::STATUS_UNSUCCESSFUL_BOOKING, Booking::STATUS_TEMPORARY_LOCK ), true ) ) {
				$result['total_revenue_cents'] += (int) $r['total_amount'];
				$result['paid_revenue_cents']  += (int) $r['paid_amount'];
				$total_players                 += (int) $r['players'];
			}
		}
		$active_count = $result['total_bookings'] - $result['cancelled'];
		$result['avg_party_size'] = $active_count > 0
			? round( $total_players / $active_count, 1 )
			: 0;

		// Per stanza
		$sql_rooms = "SELECT room_id, COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS revenue
			FROM {$table}
			WHERE deleted_at IS NULL
			AND start_datetime >= %s AND start_datetime <= %s
			AND booking_status NOT IN ('cancelled','unsuccessful_booking','temporary_lock')
			{$loc_clause}
			GROUP BY room_id ORDER BY count DESC";
		$by_room_raw = $wpdb->get_results( $wpdb->prepare( $sql_rooms, $params ), ARRAY_A ) ?: array();

		$by_room = array_map( function ( $r ) {
			$room = $this->rooms->find( (int) $r['room_id'] );
			return array(
				'room_id'      => (int) $r['room_id'],
				'room_name'    => $room['name'] ?? '—',
				'count'        => (int) $r['count'],
				'revenue_cents' => (int) $r['revenue'],
			);
		}, $by_room_raw );
		$result['by_room'] = $by_room;

		// Per giorno
		$sql_day = "SELECT DATE(start_datetime) AS day, COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS revenue
			FROM {$table}
			WHERE deleted_at IS NULL
			AND start_datetime >= %s AND start_datetime <= %s
			AND booking_status NOT IN ('cancelled','unsuccessful_booking','temporary_lock')
			{$loc_clause}
			GROUP BY day ORDER BY day ASC";
		$by_day = $wpdb->get_results( $wpdb->prepare( $sql_day, $params ), ARRAY_A ) ?: array();
		$result['by_day'] = array_map( static fn( $r ) => array(
			'day'          => $r['day'],
			'count'        => (int) $r['count'],
			'revenue_cents' => (int) $r['revenue'],
		), $by_day );

		return $result;
	}

	public function occupancy_rate( string $start_utc, string $end_utc, ?int $location_id = null ): float {
		global $wpdb;
		$table = em_table( 'bookings' );

		$loc_clause = '';
		$params     = array( $start_utc, $end_utc );
		if ( $location_id ) {
			$loc_clause = ' AND location_id = %d';
			$params[]   = $location_id;
		}

		// Slot disponibili totali = somma su tutti i giorni dello schedule
		// Semplificazione: contiamo gli slot configurati × giorni nel range
		$slots_per_week = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . em_table( 'room_time_slots' ) . ' WHERE is_active = 1' );
		$days           = max( 1, ( strtotime( $end_utc ) - strtotime( $start_utc ) ) / 86400 );
		$total_slots    = (int) round( $slots_per_week / 7 * $days );

		$booked = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			WHERE deleted_at IS NULL
			AND start_datetime >= %s AND start_datetime <= %s
			AND booking_status NOT IN ('cancelled','unsuccessful_booking','temporary_lock')
			{$loc_clause}",
			$params
		) );

		return $total_slots > 0 ? round( $booked / $total_slots * 100, 1 ) : 0;
	}
}
