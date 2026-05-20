<?php
namespace EscapeManager\Services;

use EscapeManager\Repositories\Voucher_Repository;
use EscapeManager\Repositories\Customer_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Voucher_Service {

	public function __construct(
		private Voucher_Repository $repo = new Voucher_Repository(),
		private Customer_Repository $customers = new Customer_Repository(),
		private Activity_Logger $logger = new Activity_Logger()
	) {}

	/**
	 * Crea un voucher gift card e ritorna i dettagli.
	 */
	public function issue( array $data ): array|\WP_Error {
		$amount = (int) ( $data['amount_cents'] ?? 0 );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'INVALID_AMOUNT', __( 'Importo non valido.', 'escape-manager' ), array( 'status' => 400 ) );
		}

		$customer_id = null;
		if ( ! empty( $data['customer_id'] ) ) {
			$customer_id = (int) $data['customer_id'];
		} elseif ( ! empty( $data['customer'] ) ) {
			$customer_id = $this->customers->find_or_create( (array) $data['customer'] );
		}

		$voucher_id = $this->repo->create( array(
			'amount'      => $amount,
			'customer_id' => $customer_id,
			'status'      => 'active',
			'valid_until' => $data['valid_until'] ?? null,
		) );

		$voucher = $this->repo->find( $voucher_id );
		$this->logger->log( 'voucher_issued', 'voucher', $voucher_id, null, $voucher );
		return $voucher;
	}

	/**
	 * Valida un voucher e ritorna lo sconto applicabile (massimo amount voucher).
	 *
	 * @return array{ voucher_id:int, code:string, discount_cents:int }|\WP_Error
	 */
	public function validate_and_compute( string $code, int $amount_cents ): array|\WP_Error {
		$code = strtoupper( trim( $code ) );
		$v    = $this->repo->find_by_code( $code );
		if ( ! $v || $v['status'] !== 'active' ) {
			return new \WP_Error( 'VOUCHER_INVALID', __( 'Voucher non valido.', 'escape-manager' ), array( 'status' => 404 ) );
		}
		if ( $v['valid_until'] && $v['valid_until'] < em_now_utc() ) {
			return new \WP_Error( 'VOUCHER_EXPIRED', __( 'Voucher scaduto.', 'escape-manager' ), array( 'status' => 422 ) );
		}
		$discount = min( (int) $v['amount'], $amount_cents );
		return array(
			'voucher_id'     => (int) $v['id'],
			'code'           => $v['code'],
			'discount_cents' => $discount,
		);
	}

	public function redeem( int $voucher_id ): void {
		$this->repo->mark_used( $voucher_id );
	}
}
