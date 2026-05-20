<?php
namespace EscapeManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Repository extends Base_Repository {

	protected function table_name(): string {
		return 'payments';
	}

	public function create( array $data ): int {
		$row = array_merge(
			array(
				'booking_id'     => (int) $data['booking_id'],
				'amount'         => (int) $data['amount'],
				'payment_method' => (string) $data['payment_method'],
				'payment_status' => (string) ( $data['payment_status'] ?? 'paid' ),
				'transaction_id' => $data['transaction_id'] ?? null,
				'paid_at'        => $data['paid_at'] ?? em_now_utc(),
				'created_by'     => $data['created_by'] ?? null,
			),
			array( 'created_at' => em_now_utc() )
		);
		$this->wpdb->insert( $this->table, $row );
		return (int) $this->wpdb->insert_id;
	}

	public function by_booking( int $booking_id ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE booking_id = %d ORDER BY paid_at DESC",
				$booking_id
			),
			ARRAY_A
		) ?: array();
	}
}
