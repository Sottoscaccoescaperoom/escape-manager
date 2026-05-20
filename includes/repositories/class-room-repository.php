<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Room_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'rooms';
	}

	public function all_active( ?int $location_id = null ): array {
		$sql = "SELECT * FROM {$this->table} WHERE is_active = 1 AND deleted_at IS NULL";
		if ( $location_id ) {
			$sql = $this->wpdb->prepare( $sql . ' AND location_id = %d', $location_id );
		}
		$sql .= ' ORDER BY sort_order ASC, name ASC';
		return $this->wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	public function all_for_admin(): array {
		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table} WHERE deleted_at IS NULL ORDER BY sort_order ASC, name ASC",
			ARRAY_A
		) ?: array();
	}

	public function find_by_slug( string $slug ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE slug = %s AND deleted_at IS NULL", $slug ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function create( array $data ): int {
		$row = array_merge(
			array(
				'location_id'      => (int) ( $data['location_id'] ?? 0 ),
				'name'             => (string) ( $data['name'] ?? '' ),
				'slug'             => (string) ( $data['slug'] ?? sanitize_title( $data['name'] ?? '' ) ),
				'image_url'        => $data['image_url'] ?? null,
				'description'      => $data['description'] ?? null,
				'teaser'           => $data['teaser'] ?? null,
				'important_info'   => $data['important_info'] ?? null,
				'duration_minutes' => (int) ( $data['duration_minutes'] ?? 60 ),
				'min_players'      => (int) ( $data['min_players'] ?? 2 ),
				'max_players'      => (int) ( $data['max_players'] ?? 6 ),
				'minimum_age'      => $data['minimum_age'] ?? null,
				'difficulty'       => $data['difficulty'] ?? null,
				'fear_level'       => $data['fear_level'] ?? null,
				'room_type'        => $data['room_type'] ?? null,
				'has_actors'       => isset( $data['has_actors'] ) ? (int) $data['has_actors'] : 0,
				'tags'             => isset( $data['tags'] ) ? wp_json_encode( $data['tags'] ) : null,
				'sort_order'       => (int) ( $data['sort_order'] ?? 0 ),
				'is_active'        => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
			),
			$this->timestamps_for_insert()
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$data['tags'] = wp_json_encode( $data['tags'] );
		}
		$row = array_merge( $data, $this->timestamps_for_update() );
		unset( $row['id'], $row['created_at'] );
		return false !== $this->wpdb->update( $this->table, $row, array( 'id' => $id ) );
	}
}
