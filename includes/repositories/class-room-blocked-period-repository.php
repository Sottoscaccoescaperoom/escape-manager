<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * §Lavagna+ 2026-07-01 — Room_Blocked_Period_Repository
 *
 * Gestisce i "periodi bloccati" per stanza: intervalli in cui la stanza
 * risulta non prenotabile (manutenzione, chiusura straordinaria, blocco
 * cumulativo di un intero giorno). Availability service li verifica gia'
 * (SELECT su em_room_blocked_periods): qui esponiamo CRUD.
 */
final class Room_Blocked_Period_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'room_blocked_periods';
	}

	/** Lista i blocchi di una stanza in un range (usa lo stesso overlap
	 *  test della availability). */
	public function list_range( int $room_id, string $start, string $end ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE room_id = %d
				   AND start_datetime < %s
				   AND end_datetime   > %s
				 ORDER BY start_datetime ASC",
				$room_id, $end, $start
			),
			ARRAY_A
		) ?: array();
	}

	public function create( int $room_id, string $start, string $end, ?string $reason = null, ?int $user_id = null ): int {
		$this->wpdb->insert( $this->table, array(
			'room_id'        => $room_id,
			'start_datetime' => $start,
			'end_datetime'   => $end,
			'reason'         => $reason,
			'created_by'     => $user_id,
			'created_at'     => em_now_utc(),
		) );
		return (int) $this->wpdb->insert_id;
	}

	/** Cancella tutti i blocchi di una stanza che si sovrappongono al giorno indicato. */
	public function delete_day( int $room_id, string $day_start, string $day_end ): int {
		$rows = $this->list_range( $room_id, $day_start, $day_end );
		$n = 0;
		foreach ( $rows as $r ) {
			if ( $this->delete( (int) $r['id'] ) ) $n++;
		}
		return $n;
	}
}
