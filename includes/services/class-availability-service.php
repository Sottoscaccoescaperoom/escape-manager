<?php
namespace EscapeManager\Services;

use EscapeManager\Repositories\Room_Repository;
use EscapeManager\Repositories\Room_Time_Slot_Repository;
use EscapeManager\Repositories\Booking_Repository;
use EscapeManager\Repositories\Lock_Repository;
use EscapeManager\Helpers\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Genera gli slot disponibili per una stanza in una certa data.
 * Combina: room_time_slots × range data − bookings attivi − temporary_locks − blocked_periods.
 */
final class Availability_Service {

	public function __construct(
		private Room_Repository $rooms = new Room_Repository(),
		private Room_Time_Slot_Repository $time_slots = new Room_Time_Slot_Repository(),
		private Booking_Repository $bookings = new Booking_Repository(),
		private Lock_Repository $locks = new Lock_Repository(),
		private Pricing_Service $pricing = new Pricing_Service(),
		private \EscapeManager\Repositories\Location_Repository $locations = new \EscapeManager\Repositories\Location_Repository()
	) {}

	/** Cache location per id (evita query ripetute nel loop stanze). */
	private array $location_cache = array();
	private function location_for( int $id ): array {
		if ( ! isset( $this->location_cache[ $id ] ) ) {
			$this->location_cache[ $id ] = $this->locations->find( $id ) ?: array();
		}
		return $this->location_cache[ $id ];
	}

	/**
	 * Orari "hot" (più prenotati storicamente) per stanza, calcolati dalle
	 * prenotazioni reali passate. Mappa: room_id => [ 'HH:MM' (UTC) => true ].
	 * Memoizzato per richiesta. Un orario è "hot" se è tra i 2 più scelti della
	 * stanza, con almeno 3 prenotazioni e almeno metà del massimo.
	 */
	private ?array $hot_by_room = null;
	private function hot_times_by_room(): array {
		if ( null !== $this->hot_by_room ) {
			return $this->hot_by_room;
		}
		global $wpdb;
		$table = em_table( 'bookings' );
		$rows  = $wpdb->get_results(
			"SELECT room_id, DATE_FORMAT(start_datetime, '%H:%i') AS hhmm, COUNT(*) AS c
			 FROM {$table}
			 WHERE booking_status IN ('confirmed','completed') AND deleted_at IS NULL
			 GROUP BY room_id, hhmm",
			ARRAY_A
		);

		$by = array();
		foreach ( (array) $rows as $r ) {
			$by[ (int) $r['room_id'] ][] = array( 'hhmm' => $r['hhmm'], 'c' => (int) $r['c'] );
		}

		$hot = array();
		foreach ( $by as $rid => $list ) {
			usort( $list, static fn( $a, $b ) => $b['c'] <=> $a['c'] );
			$max = $list[0]['c'];
			$set = array();
			foreach ( $list as $i => $row ) {
				if ( $i < 2 && $row['c'] >= 3 && $row['c'] >= $max * 0.5 ) {
					$set[ $row['hhmm'] ] = true;
				}
			}
			$hot[ $rid ] = $set;
		}

		$this->hot_by_room = $hot;
		return $hot;
	}

	/**
	 * Disponibilità su un intervallo di giorni consecutivi (vista Settimana).
	 *
	 * @return array Lista per giorno: [{ date: 'Y-m-d', rooms: [...] }]
	 */
	public function slots_for_range( string $start_date, int $days = 1, ?int $room_id = null, ?int $location_id = null ): array {
		$days      = max( 1, min( 31, $days ) );
		$tz_string = em_setting( 'em_timezone', 'Europe/Rome' );
		$tz        = new \DateTimeZone( $tz_string );

		try {
			$start = new \DateTimeImmutable( $start_date . ' 00:00:00', $tz );
		} catch ( \Exception $e ) {
			return array();
		}

		$out = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$d = $start->modify( "+{$i} days" )->format( 'Y-m-d' );
			$out[] = array(
				'date'  => $d,
				'rooms' => $this->slots_for_date( $d, $room_id, $location_id ),
			);
		}

		return $out;
	}

	/**
	 * @param string   $date          'YYYY-MM-DD' (local timezone)
	 * @param int|null $room_id       Filtra una sola stanza
	 * @param int|null $location_id   Filtra location
	 * @return array Lista per stanza: [{ room_id, room_name, duration_minutes, slots: [...] }]
	 */
	public function slots_for_date( string $date, ?int $room_id = null, ?int $location_id = null ): array {
		$tz_string = em_setting( 'em_timezone', 'Europe/Rome' );
		$tz        = new \DateTimeZone( $tz_string );

		try {
			$day = new \DateTimeImmutable( $date . ' 00:00:00', $tz );
		} catch ( \Exception $e ) {
			return array();
		}

		$rooms = $room_id
			? array_filter( array( $this->rooms->find( $room_id ) ) )
			: $this->rooms->all_active( $location_id );

		$result = array();

		foreach ( $rooms as $room ) {
			$room_id_int  = (int) $room['id'];
			$duration     = (int) $room['duration_minutes'];
			$day_of_week  = (int) $day->format( 'w' );
			$slots_config = $this->time_slots->by_room_and_day( $room_id_int, $day_of_week );

			$slots = array();
			foreach ( $slots_config as $slot_cfg ) {
				$slot_start_local = $day->setTime(
					(int) substr( $slot_cfg['start_time'], 0, 2 ),
					(int) substr( $slot_cfg['start_time'], 3, 2 )
				);
				$slot_end_local   = $slot_start_local->modify( "+{$duration} minutes" );

				$slot_start_utc = $slot_start_local->setTimezone( new \DateTimeZone( 'UTC' ) );
				$slot_end_utc   = $slot_end_local->setTimezone( new \DateTimeZone( 'UTC' ) );

				$status = $this->compute_status( $room_id_int, $slot_start_utc, $slot_end_utc );

				$hot_map = $this->hot_times_by_room();
				$slot_entry = array(
					'start'         => $slot_start_local->format( 'c' ),
					'start_utc'     => $slot_start_utc->format( 'Y-m-d H:i:s' ),
					'end_utc'       => $slot_end_utc->format( 'Y-m-d H:i:s' ),
					'status'        => $status['status'],
					'hot'           => isset( $hot_map[ $room_id_int ][ $slot_start_utc->format( 'H:i' ) ] ),
				);
				if ( ! empty( $status['lock_expires_at'] ) ) {
					$slot_entry['lock_expires_at'] = $status['lock_expires_at'];
				}
				$slots[] = $slot_entry;
			}

			$loc = $this->location_for( (int) $room['location_id'] );
			$result[] = array(
				'room_id'          => $room_id_int,
				'room_name'        => $room['name'],
				'room_slug'        => $room['slug'],
				'duration_minutes' => $duration,
				'min_players'      => (int) $room['min_players'],
				'max_players'      => (int) $room['max_players'],
				'difficulty'       => isset( $room['difficulty'] ) && '' !== $room['difficulty'] ? (int) $room['difficulty'] : null,
				'image_url'        => $room['image_url'],
				'price_from_cents' => $this->pricing->calculate( $room_id_int, (int) $room['min_players'] ),
				'location_name'    => $loc['name'] ?? null,
				'location_address' => $loc['address'] ?? null,
				'location_city'    => $loc['city'] ?? null,
				'slots'            => $slots,
			);
		}

		return $result;
	}

	/**
	 * Stato di uno slot: available | locked | booked | blocked.
	 *
	 * @return array{ status: string, lock_expires_at?: string }
	 */
	private function compute_status( int $room_id, \DateTimeInterface $start_utc, \DateTimeInterface $end_utc ): array {
		$start_str = $start_utc->format( 'Y-m-d H:i:s' );
		$end_str   = $end_utc->format( 'Y-m-d H:i:s' );

		// 1) Booking attivo?
		if ( $this->bookings->has_overlap( $room_id, $start_str, $end_str ) ) {
			return array( 'status' => 'booked' );
		}

		// 2) Lock attivo?
		$locks_overlap = $this->locks->active_for_room_in_range( $room_id, $start_str, $end_str );
		if ( ! empty( $locks_overlap ) ) {
			return array(
				'status'         => 'locked',
				'lock_expires_at' => $locks_overlap[0]['expires_at'],
			);
		}

		// 3) Blocked periods (manutenzione)
		global $wpdb;
		$blocked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . em_table( 'room_blocked_periods' ) . "
				WHERE room_id = %d
				AND start_datetime < %s
				AND end_datetime > %s
				LIMIT 1",
				$room_id,
				$end_str,
				$start_str
			)
		);
		if ( $blocked ) {
			return array( 'status' => 'blocked' );
		}

		// 4) Slot nel passato o troppo a ridosso (anticipo minimo prenotazione)?
		// §cutoff — `em_booking_cutoff_minutes`: minuti di anticipo richiesti per
		// prenotare (es. 150 = 2h30). Uno slot entro quella finestra da adesso
		// risulta "blocked" nel booking pubblico. Default 0 = solo slot passati.
		try {
			$now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			$cutoff = (int) em_setting( 'em_booking_cutoff_minutes', 0 );
			$limit  = $cutoff > 0 ? $now->modify( "+{$cutoff} minutes" ) : $now;
			if ( $start_utc < $limit ) {
				return array( 'status' => 'blocked' );
			}
		} catch ( \Exception $e ) {}

		return array( 'status' => 'available' );
	}
}
