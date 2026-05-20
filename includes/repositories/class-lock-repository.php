<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lock_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'temporary_locks';
	}

	public function active_for_room_in_range( int $room_id, string $start_utc, string $end_utc ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE room_id = %d
				AND expires_at > %s
				AND start_datetime < %s
				AND end_datetime > %s",
				$room_id,
				em_now_utc(),
				$end_utc,
				$start_utc
			),
			ARRAY_A
		) ?: array();
	}

	public function has_active_overlap( int $room_id, string $start_utc, string $end_utc, ?string $exclude_session_id = null ): bool {
		$sql = $this->wpdb->prepare(
			"SELECT id FROM {$this->table}
			WHERE room_id = %d
			AND expires_at > %s
			AND start_datetime < %s
			AND end_datetime > %s",
			$room_id,
			em_now_utc(),
			$end_utc,
			$start_utc
		);
		if ( $exclude_session_id ) {
			$sql .= $this->wpdb->prepare( ' AND session_id != %s', $exclude_session_id );
		}
		return ! empty( $this->wpdb->get_var( $sql . ' LIMIT 1' ) );
	}

	public function count_active_for_session( string $session_id ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE session_id = %s AND expires_at > %s",
				$session_id,
				em_now_utc()
			)
		);
	}

	public function create( array $data ): int {
		$row = array_merge(
			array(
				'room_id'        => (int) $data['room_id'],
				'start_datetime' => (string) $data['start_datetime'],
				'end_datetime'   => (string) $data['end_datetime'],
				'session_id'     => (string) $data['session_id'],
				'customer_phone' => $data['customer_phone'] ?? null,
				'expires_at'     => (string) $data['expires_at'],
			),
			array( 'created_at' => em_now_utc() )
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function delete_for_session_and_room( string $session_id, int $room_id, string $start_utc ): int {
		return (int) $this->wpdb->delete(
			$this->table,
			array(
				'session_id'     => $session_id,
				'room_id'        => $room_id,
				'start_datetime' => $start_utc,
			),
			array( '%s', '%d', '%s' )
		);
	}

	public function find_active( int $lock_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND expires_at > %s",
				$lock_id,
				em_now_utc()
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function cleanup_expired(): int {
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table} WHERE expires_at <= %s",
				em_now_utc()
			)
		);
	}
}
