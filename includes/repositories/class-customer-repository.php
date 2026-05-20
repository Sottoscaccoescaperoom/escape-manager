<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Customer_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'customers';
	}

	public function find_by_phone( string $phone ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE phone = %s AND deleted_at IS NULL LIMIT 1", $phone ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function find_or_create( array $data ): int {
		if ( ! empty( $data['phone'] ) ) {
			$existing = $this->find_by_phone( $data['phone'] );
			if ( $existing ) {
				// Aggiorna eventuali campi mancanti (email, nome).
				$update = array();
				foreach ( array( 'first_name', 'last_name', 'email', 'birthday', 'address' ) as $field ) {
					if ( ! empty( $data[ $field ] ) && empty( $existing[ $field ] ) ) {
						$update[ $field ] = $data[ $field ];
					}
				}
				if ( $update ) {
					$this->update( (int) $existing['id'], $update );
				}
				return (int) $existing['id'];
			}
		}
		return $this->create( $data );
	}

	public function create( array $data ): int {
		$row = array_merge(
			array(
				'first_name' => (string) ( $data['first_name'] ?? '' ),
				'last_name'  => $data['last_name'] ?? null,
				'phone'      => $data['phone'] ?? null,
				'email'      => $data['email'] ?? null,
				'birthday'   => $data['birthday'] ?? null,
				'address'    => $data['address'] ?? null,
			),
			$this->timestamps_for_insert()
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		$row = array_merge( $data, $this->timestamps_for_update() );
		unset( $row['id'], $row['created_at'] );
		return false !== $this->wpdb->update( $this->table, $row, array( 'id' => $id ) );
	}

	public function increment_booking_count( int $customer_id, ?int $last_room_id = null ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				SET total_bookings = total_bookings + 1,
				    last_booking_date = %s,
				    last_room_id = %d,
				    updated_at = %s
				WHERE id = %d",
				em_now_utc(),
				$last_room_id ?? 0,
				em_now_utc(),
				$customer_id
			)
		);
	}

	public function search( string $query, int $limit = 20 ): array {
		$like = '%' . $this->wpdb->esc_like( $query ) . '%';
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE deleted_at IS NULL
				AND ( first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR email LIKE %s )
				ORDER BY last_booking_date DESC
				LIMIT %d",
				$like, $like, $like, $like, $limit
			),
			ARRAY_A
		) ?: array();
	}

	public function paginate( int $page = 1, int $per_page = 50 ): array {
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE deleted_at IS NULL ORDER BY last_booking_date DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		) ?: array();
		$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE deleted_at IS NULL" );
		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page );
	}
}
