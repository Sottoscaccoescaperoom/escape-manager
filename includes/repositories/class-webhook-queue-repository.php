<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Webhook_Queue_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'webhook_queue';
	}

	public function enqueue( string $target, string $event_type, array $payload, ?int $booking_id = null ): int {
		$row = array(
			'target'          => $target,
			'event_type'      => $event_type,
			'payload'         => wp_json_encode( $payload ),
			'status'          => 'pending',
			'attempts'        => 0,
			'next_attempt_at' => em_now_utc(),
			'booking_id'      => $booking_id,
			'created_at'      => em_now_utc(),
			'updated_at'      => em_now_utc(),
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function fetch_due( int $limit = 50 ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE status = 'pending'
				AND next_attempt_at <= %s
				ORDER BY id ASC
				LIMIT %d",
				em_now_utc(),
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	public function mark_sent( int $id ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'status'     => 'sent',
				'sent_at'    => em_now_utc(),
				'updated_at' => em_now_utc(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function reschedule( int $id, int $attempts, string $next_attempt_at, string $error ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'attempts'        => $attempts,
				'next_attempt_at' => $next_attempt_at,
				'last_error'      => $error,
				'updated_at'      => em_now_utc(),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function mark_failed( int $id, string $error ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'status'     => 'failed',
				'last_error' => $error,
				'updated_at' => em_now_utc(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function summary(): array {
		$res = $this->wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status",
			ARRAY_A
		) ?: array();
		$out = array( 'pending' => 0, 'sent' => 0, 'failed' => 0 );
		foreach ( $res as $r ) {
			$out[ $r['status'] ] = (int) $r['count'];
		}
		return $out;
	}

	public function replay_failed(): int {
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				SET status = 'pending', attempts = 0, next_attempt_at = %s, last_error = NULL, updated_at = %s
				WHERE status = 'failed'",
				em_now_utc(),
				em_now_utc()
			)
		);
	}
}
