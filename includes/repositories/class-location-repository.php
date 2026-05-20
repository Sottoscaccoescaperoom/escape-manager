<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Location_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'locations';
	}

	public function all_active(): array {
		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table} WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name ASC",
			ARRAY_A
		) ?: array();
	}

	public function create( array $data ): int {
		$row = array_merge(
			array(
				'name'      => $data['name'] ?? '',
				'address'   => $data['address'] ?? null,
				'city'      => $data['city'] ?? null,
				'postal_code' => $data['postal_code'] ?? null,
				'country'   => $data['country'] ?? null,
				'latitude'  => $data['latitude'] ?? null,
				'longitude' => $data['longitude'] ?? null,
				'is_active' => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
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
