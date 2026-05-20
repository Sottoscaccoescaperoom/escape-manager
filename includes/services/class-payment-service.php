<?php
namespace EscapeManager\Services;

use EscapeManager\Domain\Booking;
use EscapeManager\Repositories\Payment_Repository;
use EscapeManager\Repositories\Booking_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Service {

	public function __construct(
		private Payment_Repository $payments = new Payment_Repository(),
		private Booking_Repository $bookings = new Booking_Repository(),
		private Activity_Logger $logger = new Activity_Logger()
	) {}

	public function add_payment( int $booking_id, int $amount_cents, string $method, ?string $transaction_id = null ): array|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new \WP_Error( 'NOT_FOUND', __( 'Prenotazione non trovata.', 'escape-manager' ), array( 'status' => 404 ) );
		}

		$payment_id = $this->payments->create( array(
			'booking_id'     => $booking_id,
			'amount'         => $amount_cents,
			'payment_method' => $method,
			'payment_status' => 'paid',
			'transaction_id' => $transaction_id,
			'paid_at'        => em_now_utc(),
			'created_by'     => get_current_user_id() ?: null,
		) );

		$this->bookings->add_payment_amount( $booking_id, $amount_cents );

		$updated      = $this->bookings->find( $booking_id );
		$new_status   = (int) $updated['paid_amount'] >= (int) $updated['total_amount']
			? Booking::PAYMENT_PAID
			: Booking::PAYMENT_PARTIALLY_PAID;
		$this->bookings->update( $booking_id, array(
			'payment_status' => $new_status,
			'payment_method' => $method,
		) );

		// Se era awaiting_payment e ora è pagato → conferma
		if ( $updated['booking_status'] === Booking::STATUS_AWAITING_PAYMENT && $new_status === Booking::PAYMENT_PAID ) {
			$this->bookings->update( $booking_id, array( 'booking_status' => Booking::STATUS_CONFIRMED ) );
			do_action( 'em_booking_status_changed', $this->bookings->find( $booking_id ), Booking::STATUS_AWAITING_PAYMENT, Booking::STATUS_CONFIRMED );
		}

		$this->logger->log( 'payment_added', 'booking', $booking_id, null, array( 'amount' => $amount_cents, 'method' => $method ) );

		return array(
			'payment_id' => $payment_id,
			'booking'    => $this->bookings->find( $booking_id ),
		);
	}
}
