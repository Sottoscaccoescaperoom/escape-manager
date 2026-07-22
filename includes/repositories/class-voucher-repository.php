<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Voucher_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'vouchers';
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

	public function all( ?string $status = null ): array {
		$sql = "SELECT * FROM {$this->table}";
		if ( $status ) {
			$sql = $this->wpdb->prepare( $sql . ' WHERE status = %s', $status );
		}
		$sql .= ' ORDER BY created_at DESC';
		return $this->wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	public function create( array $data ): int {
		$row = array(
			'code'         => strtoupper( trim( (string) ( $data['code'] ?? '' ) ) ) ?: $this->generate_code(),
			'customer_id'  => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
			'amount'       => (int) ( $data['amount'] ?? 0 ),
			'status'       => (string) ( $data['status'] ?? 'active' ),
			'valid_until'  => $data['valid_until'] ?? null,
			'created_at'   => em_now_utc(),
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		unset( $data['id'], $data['created_at'], $data['code'] );
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	public function mark_used( int $id ): void {
		$this->wpdb->update(
			$this->table,
			array( 'status' => 'used' ),
			array( 'id' => $id )
		);
	}

	/**
	 * §SEC 2026-07-22 (audit em-plugin) — Consumo PARZIALE atomico. Prima
	 * `mark_used` marcava l'intero voucher come usato anche quando lo sconto
	 * applicato era INFERIORE al valore del voucher → il residuo andava perso.
	 * Ora scaliamo solo l'importo consumato e marchiamo 'used' solo a saldo zero.
	 * L'UPDATE condizionato (status='active') evita anche il doppio consumo.
	 *
	 * @return bool true se il consumo ha avuto effetto.
	 */
	public function consume( int $id, int $consumed_cents ): bool {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				    SET amount = GREATEST(0, amount - %d),
				        status = CASE WHEN amount - %d <= 0 THEN 'used' ELSE status END
				  WHERE id = %d AND status = 'active'",
				$consumed_cents,
				$consumed_cents,
				$id
			)
		);
		return (int) $this->wpdb->rows_affected > 0;
	}

	private function generate_code(): string {
		do {
			$code = 'GIFT-' . strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) );
		} while ( $this->find_by_code( $code ) );
		return $code;
	}
}
