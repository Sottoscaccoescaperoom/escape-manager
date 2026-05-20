<?php
namespace EscapeManager\Services;

use EscapeManager\Domain\Booking;
use EscapeManager\Repositories\Booking_Repository;
use EscapeManager\Repositories\Customer_Repository;
use EscapeManager\Repositories\Room_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invio email transazionali base.
 * In MVP 1 usa wp_mail. In MVP 3 si aggiungeranno WhatsApp via WATI.
 */
final class Notification_Service {

	public function __construct(
		private Booking_Repository $bookings = new Booking_Repository(),
		private Customer_Repository $customers = new Customer_Repository(),
		private Room_Repository $rooms = new Room_Repository()
	) {}

	public function register_hooks(): void {
		add_action( 'em_booking_status_changed', array( $this, 'on_status_change' ), 10, 3 );
	}

	public function on_status_change( array $booking, ?string $old, string $new ): void {
		if ( $old === $new ) {
			return;
		}
		if ( $new === Booking::STATUS_CONFIRMED ) {
			$this->send_confirmation( $booking );
		} elseif ( $new === Booking::STATUS_CANCELLED ) {
			$this->send_cancellation( $booking );
		}
	}

	private function send_confirmation( array $booking ): void {
		$customer = $booking['customer_id'] ? $this->customers->find( (int) $booking['customer_id'] ) : null;
		if ( ! $customer || empty( $customer['email'] ) ) {
			return;
		}
		$room    = $this->rooms->find( (int) $booking['room_id'] );
		$subject = sprintf( __( 'Conferma prenotazione %s', 'escape-manager' ), $booking['booking_code'] );
		$body    = $this->render_template( 'booking-confirmed', compact( 'booking', 'customer', 'room' ) );
		$this->send_email( $customer['email'], $subject, $body );
	}

	private function send_cancellation( array $booking ): void {
		$customer = $booking['customer_id'] ? $this->customers->find( (int) $booking['customer_id'] ) : null;
		if ( ! $customer || empty( $customer['email'] ) ) {
			return;
		}
		$room    = $this->rooms->find( (int) $booking['room_id'] );
		$subject = sprintf( __( 'Prenotazione %s annullata', 'escape-manager' ), $booking['booking_code'] );
		$body    = $this->render_template( 'booking-cancelled', compact( 'booking', 'customer', 'room' ) );
		$this->send_email( $customer['email'], $subject, $body );
	}

	private function send_email( string $to, string $subject, string $body ): void {
		$from_name    = (string) em_setting( 'em_email_from_name', get_bloginfo( 'name' ) );
		$from_address = (string) em_setting( 'em_email_from_address', get_option( 'admin_email' ) );

		$headers   = array();
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		if ( $from_address ) {
			$headers[] = "From: {$from_name} <{$from_address}>";
		}
		wp_mail( $to, $subject, $body, $headers );
	}

	private function render_template( string $name, array $vars ): string {
		$template = EM_PLUGIN_DIR . 'templates/emails/' . $name . '.php';
		if ( ! file_exists( $template ) ) {
			return '';
		}
		ob_start();
		extract( $vars, EXTR_SKIP );
		include $template;
		return ob_get_clean();
	}
}
