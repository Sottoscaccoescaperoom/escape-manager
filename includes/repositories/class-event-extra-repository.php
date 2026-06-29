<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Servizi extra "Rendi speciale il tuo evento".
 * event_types: CSV di tipi evento applicabili (es. "compleanno,addio_nubilato") oppure "all".
 */
final class Event_Extra_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'event_extras';
	}

	public function all(): array {
		return $this->wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY sort_order ASC, id ASC", ARRAY_A ) ?: array();
	}

	public function active(): array {
		return $this->wpdb->get_results( "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC", ARRAY_A ) ?: array();
	}

	/** Recupera per lista di id (per il calcolo prezzo autorevole lato server). */
	public function find_many( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$place = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id IN ($place)", ...$ids ),
			ARRAY_A
		) ?: array();
	}

	public function create( array $data ): int {
		$row = array_merge(
			array(
				'title'       => (string) ( $data['title'] ?? '' ),
				'description' => isset( $data['description'] ) ? (string) $data['description'] : null,
				'price_cents' => (int) ( $data['price_cents'] ?? 0 ),
				'event_types' => (string) ( $data['event_types'] ?? 'all' ),
				'is_active'   => isset( $data['is_active'] ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1,
				'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
			),
			$this->timestamps_for_insert()
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		$row = array_merge( $data, $this->timestamps_for_update() );
		unset( $row['id'], $row['created_at'] );
		if ( isset( $row['is_active'] ) ) {
			$row['is_active'] = ! empty( $row['is_active'] ) ? 1 : 0;
		}
		return false !== $this->wpdb->update( $this->table, $row, array( 'id' => $id ) );
	}
}
