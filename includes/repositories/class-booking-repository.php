<?php
namespace EscapeManager\Repositories;

use EscapeManager\Domain\Booking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Booking_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'bookings';
	}

	public function find_by_code( string $code ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE booking_code = %s AND deleted_at IS NULL", $code ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function active_for_room_in_range( int $room_id, string $start_utc, string $end_utc ): array {
		$active = "'" . implode( "','", Booking::ACTIVE_STATUSES ) . "'";
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE room_id = %d
				AND deleted_at IS NULL
				AND booking_status IN ({$active})
				AND start_datetime < %s
				AND end_datetime > %s",
				$room_id,
				$end_utc,
				$start_utc
			),
			ARRAY_A
		) ?: array();
	}

	public function has_overlap( int $room_id, string $start_utc, string $end_utc, ?int $exclude_booking_id = null ): bool {
		$active = "'" . implode( "','", Booking::ACTIVE_STATUSES ) . "'";
		$sql = $this->wpdb->prepare(
			"SELECT id FROM {$this->table}
			WHERE room_id = %d
			AND deleted_at IS NULL
			AND booking_status IN ({$active})
			AND start_datetime < %s
			AND end_datetime > %s",
			$room_id,
			$end_utc,
			$start_utc
		);
		if ( $exclude_booking_id ) {
			$sql .= $this->wpdb->prepare( ' AND id != %d', $exclude_booking_id );
		}
		$row = $this->wpdb->get_var( $sql . ' LIMIT 1' );
		return ! empty( $row );
	}

	public function create( array $data ): int {
		$row = array_merge(
			array(
				'booking_code'     => (string) $data['booking_code'],
				'room_id'          => (int) $data['room_id'],
				'location_id'      => (int) ( $data['location_id'] ?? 0 ),
				'customer_id'      => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'start_datetime'   => (string) $data['start_datetime'],
				'end_datetime'    => (string) $data['end_datetime'],
				'timezone'        => (string) ( $data['timezone'] ?? em_setting( 'em_timezone', 'Europe/Rome' ) ),
				'adults'          => (int) ( $data['adults'] ?? 0 ),
				'children'        => (int) ( $data['children'] ?? 0 ),
				'children_reduced' => (int) ( $data['children_reduced'] ?? 0 ),
				'children_free'   => (int) ( $data['children_free'] ?? 0 ),
				'total_players'   => (int) ( $data['total_players'] ?? ( ( $data['adults'] ?? 0 ) + ( $data['children'] ?? 0 ) ) ),
				'total_amount'    => (int) ( $data['total_amount'] ?? 0 ),
				'addons_amount'   => (int) ( $data['addons_amount'] ?? 0 ),
				'extras_json'     => $data['extras_json'] ?? null,
				'event_type'      => $data['event_type'] ?? null,
				'event_label'     => $data['event_label'] ?? null,
				'paid_amount'     => (int) ( $data['paid_amount'] ?? 0 ),
				'payment_method'  => $data['payment_method'] ?? null,
				'payment_status'  => (string) ( $data['payment_status'] ?? Booking::PAYMENT_UNPAID ),
				'booking_status'  => (string) ( $data['booking_status'] ?? Booking::STATUS_BOOKING_IN_PROGRESS ),
				'source'          => $data['source'] ?? 'public',
				'customer_comment' => $data['customer_comment'] ?? null,
				'internal_notes'   => $data['internal_notes'] ?? null,
				'created_by'       => $data['created_by'] ?? null,
				'assigned_staff_id' => $data['assigned_staff_id'] ?? null,
				'expires_at'       => $data['expires_at'] ?? null,
			),
			$this->timestamps_for_insert()
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		$row = array_merge( $data, $this->timestamps_for_update() );
		unset( $row['id'], $row['created_at'], $row['booking_code'] );
		return false !== $this->wpdb->update( $this->table, $row, array( 'id' => $id ) );
	}

	public function add_payment_amount( int $id, int $cents ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table} SET paid_amount = paid_amount + %d, updated_at = %s WHERE id = %d",
				$cents,
				em_now_utc(),
				$id
			)
		);
	}

	public function for_date_range( string $start_utc, string $end_utc, array $filters = array() ): array {
		$where  = array( 'deleted_at IS NULL', 'start_datetime >= %s', 'start_datetime <= %s' );
		$params = array( $start_utc, $end_utc );

		if ( ! empty( $filters['room_id'] ) ) {
			$where[] = 'room_id = %d';
			$params[] = (int) $filters['room_id'];
		}
		if ( ! empty( $filters['location_id'] ) ) {
			$where[] = 'location_id = %d';
			$params[] = (int) $filters['location_id'];
		}
		if ( ! empty( $filters['booking_status'] ) ) {
			$where[] = 'booking_status = %s';
			$params[] = (string) $filters['booking_status'];
		}
		if ( ! empty( $filters['exclude_statuses'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $filters['exclude_statuses'] ), '%s' ) );
			$where[]      = "booking_status NOT IN ({$placeholders})";
			$params       = array_merge( $params, $filters['exclude_statuses'] );
		}

		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_datetime ASC';

		return $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $params ),
			ARRAY_A
		) ?: array();
	}

	public function paginate( array $filters = array(), int $page = 1, int $per_page = 50 ): array {
		$where  = array( 'deleted_at IS NULL' );
		$params = array();

		if ( ! empty( $filters['room_id'] ) ) {
			$where[] = 'room_id = %d';
			$params[] = (int) $filters['room_id'];
		}
		if ( ! empty( $filters['booking_status'] ) ) {
			$where[] = 'booking_status = %s';
			$params[] = (string) $filters['booking_status'];
		}
		if ( ! empty( $filters['payment_status'] ) ) {
			$where[] = 'payment_status = %s';
			$params[] = (string) $filters['payment_status'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[] = 'start_datetime >= %s';
			$params[] = (string) $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[] = 'start_datetime <= %s';
			$params[] = (string) $filters['to'];
		}
		// §Data di arrivo 2026-07-26 — `from`/`to` filtrano la data di GIOCO
		// (start_datetime). Questi due invece filtrano il momento in cui la
		// prenotazione e' ARRIVATA, per rispondere a "quante ne sono entrate
		// oggi?". Valori attesi in UTC, come start_datetime.
		if ( ! empty( $filters['created_from'] ) ) {
			$where[] = 'created_at >= %s';
			$params[] = (string) $filters['created_from'];
		}
		if ( ! empty( $filters['created_to'] ) ) {
			$where[] = 'created_at <= %s';
			$params[] = (string) $filters['created_to'];
		}
		if ( ! empty( $filters['customer_id'] ) ) {
			$where[] = 'customer_id = %d';
			$params[] = (int) $filters['customer_id'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like   = '%' . $this->wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = '( booking_code LIKE %s )';
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		// §Data di arrivo 2026-07-26 — Ordinamento selezionabile: per data di
		// gioco (default, com'era) oppure per data di arrivo della prenotazione.
		// Whitelist rigida: il valore finisce in SQL e non passa da prepare().
		$order_col = ( ( $filters['order_by'] ?? '' ) === 'created_at' ) ? 'created_at' : 'start_datetime';
		$rows_sql  = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY {$order_col} DESC LIMIT %d OFFSET %d";
		$total_sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";

		$rows_params  = array_merge( $params, array( $per_page, $offset ) );
		$rows         = $this->wpdb->get_results( $this->wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A ) ?: array();
		$total        = $params ? (int) $this->wpdb->get_var( $this->wpdb->prepare( $total_sql, $params ) ) : (int) $this->wpdb->get_var( $total_sql );

		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page );
	}

	public function by_customer( int $customer_id ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE customer_id = %d AND deleted_at IS NULL ORDER BY start_datetime DESC",
				$customer_id
			),
			ARRAY_A
		) ?: array();
	}
}
