<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Room_Time_Slot_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'room_time_slots';
	}

	public function by_room( int $room_id ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE room_id = %d AND is_active = 1 ORDER BY day_of_week, start_time",
				$room_id
			),
			ARRAY_A
		) ?: array();
	}

	public function by_room_and_day( int $room_id, int $day_of_week ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE room_id = %d AND day_of_week = %d AND is_active = 1 ORDER BY start_time",
				$room_id,
				$day_of_week
			),
			ARRAY_A
		) ?: array();
	}

	public function create( array $data ): int {
		$row = array_merge(
			array(
				'room_id'     => (int) ( $data['room_id'] ?? 0 ),
				'day_of_week' => (int) ( $data['day_of_week'] ?? 0 ),
				'start_time'  => (string) ( $data['start_time'] ?? '15:00:00' ),
				'end_time'    => (string) ( $data['end_time'] ?? '16:00:00' ),
				'is_active'   => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
			),
			$this->timestamps_for_insert()
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function delete_by_room( int $room_id ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'room_id' => $room_id ), array( '%d' ) );
	}
}
