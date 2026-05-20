<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tariff_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'tariffs';
	}

	public function for_room( int $room_id ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE room_id = %d OR room_id IS NULL ORDER BY min_players ASC",
				$room_id
			),
			ARRAY_A
		) ?: array();
	}

	public function all(): array {
		return $this->wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY title", ARRAY_A ) ?: array();
	}

	public function create( array $data ): int {
		$row = array_merge(
			array(
				'room_id'         => isset( $data['room_id'] ) ? (int) $data['room_id'] : null,
				'title'           => (string) ( $data['title'] ?? '' ),
				'min_players'     => (int) ( $data['min_players'] ?? 1 ),
				'max_players'     => (int) ( $data['max_players'] ?? 6 ),
				'price_type'      => (string) ( $data['price_type'] ?? 'fixed' ),
				'price_per_person' => (int) ( $data['price_per_person'] ?? 0 ),
				'fixed_price'     => (int) ( $data['fixed_price'] ?? 0 ),
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
}
