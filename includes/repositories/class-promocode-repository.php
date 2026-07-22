<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Promocode_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'promocodes';
	}

	public function find_by_code( string $code ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE code = %s LIMIT 1",
				$code
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function all(): array {
		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table} ORDER BY created_at DESC",
			ARRAY_A
		) ?: array();
	}

	public function create( array $data ): int {
		$row = array(
			'code'        => strtoupper( trim( (string) $data['code'] ) ),
			'type'        => (string) ( $data['type'] ?? 'percent' ),
			'value'       => (int) ( $data['value'] ?? 0 ),
			'usage_limit' => isset( $data['usage_limit'] ) ? (int) $data['usage_limit'] : null,
			'used_count'  => 0,
			'valid_from'  => $data['valid_from'] ?? null,
			'valid_to'    => $data['valid_to'] ?? null,
			'is_active'   => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
			'created_at'  => em_now_utc(),
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		if ( isset( $data['code'] ) ) {
			$data['code'] = strtoupper( trim( (string) $data['code'] ) );
		}
		unset( $data['id'], $data['created_at'], $data['used_count'] );
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	/**
	 * §SEC 2026-07-22 (audit em-plugin) — Incremento ATOMICO e CONDIZIONATO al
	 * limite: prima l'UPDATE incrementava incondizionatamente, così due richieste
	 * concorrenti che superavano entrambe il check `used_count < usage_limit` in
	 * validate_and_compute potevano portare used_count OLTRE il limite (double
	 * spend). Ora l'UPDATE incrementa solo se ancora sotto il limite; il numero di
	 * righe modificate dice se ha avuto effetto.
	 *
	 * @return bool true se l'incremento è avvenuto (codice ancora spendibile).
	 */
	public function increment_usage( int $id ): bool {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table} SET used_count = used_count + 1
				 WHERE id = %d AND ( usage_limit IS NULL OR used_count < usage_limit )",
				$id
			)
		);
		return (int) $this->wpdb->rows_affected > 0;
	}
}
