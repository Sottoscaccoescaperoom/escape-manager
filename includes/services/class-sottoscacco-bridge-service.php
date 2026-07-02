<?php
namespace EscapeManager\Services;

use EscapeManager\Domain\Booking;
use EscapeManager\Repositories\Webhook_Queue_Repository;
use EscapeManager\Repositories\Customer_Repository;
use EscapeManager\Repositories\Room_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emula PERFETTAMENTE Escape Navigator verso Sottoscacco.
 *
 * Sottoscacco riceverà i booking come se fossero EN — externalSource='escape_navigator'.
 * Zero modifiche a Sottoscacco. Vedi PROGETTO.md APPENDICE C.
 *
 * Eventi emessi:
 *   - new-order     → quando un booking diventa confirmed
 *   - update-order  → quando data/ora/players cambiano su booking confirmed
 *   - cancel-order  → quando un booking confirmed diventa cancelled
 */
final class Sottoscacco_Bridge_Service {

	public function __construct(
		private Webhook_Queue_Repository $queue = new Webhook_Queue_Repository(),
		private Customer_Repository $customers = new Customer_Repository(),
		private Room_Repository $rooms = new Room_Repository(),
		private Activity_Logger $logger = new Activity_Logger()
	) {}

	public function register_hooks(): void {
		add_action( 'em_booking_status_changed', array( $this, 'on_status_change' ), 20, 3 );
		add_action( 'em_booking_updated', array( $this, 'on_booking_updated' ), 20, 2 );
	}

	public function is_enabled(): bool {
		return (bool) em_setting( 'em_sottoscacco_bridge_enabled', 0 );
	}

	public function on_status_change( array $booking, ?string $old, string $new ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// new-order: booking diventa confirmed da qualsiasi stato precedente
		if ( $new === Booking::STATUS_CONFIRMED && $old !== Booking::STATUS_CONFIRMED ) {
			$this->enqueue_event( 'new-order', $booking );
			return;
		}

		// cancel-order: booking confirmed/awaiting_payment diventa cancelled
		if ( $new === Booking::STATUS_CANCELLED && in_array( $old, array( Booking::STATUS_CONFIRMED, Booking::STATUS_AWAITING_PAYMENT, Booking::STATUS_NOT_PAID ), true ) ) {
			$this->enqueue_event( 'cancel-order', $booking );
			return;
		}
	}

	public function on_booking_updated( array $new_booking, array $old_booking ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( $new_booking['booking_status'] !== Booking::STATUS_CONFIRMED ) {
			return;
		}

		$material_changes = (
			$new_booking['start_datetime'] !== $old_booking['start_datetime']
			|| (int) $new_booking['total_players'] !== (int) $old_booking['total_players']
			|| (int) $new_booking['assigned_staff_id'] !== (int) $old_booking['assigned_staff_id']
		);
		if ( $material_changes ) {
			$this->enqueue_event( 'update-order', $new_booking );
		}
	}

	private function enqueue_event( string $event, array $booking ): void {
		$payload = $this->build_payload( $event, $booking );
		$this->queue->enqueue( 'sottoscacco', $event, $payload, (int) $booking['id'] );
		$this->logger->log( 'webhook_enqueued', 'booking', (int) $booking['id'], null, array( 'event' => $event ) );

		// §Fix 2026-07-02 — INVIO ISTANTANEO. WP-cron gira solo quando c'e'
		// traffico o via cron di sistema esterno; per il booking pubblico il
		// cliente aspetta il messaggio WhatsApp SUBITO, se arriva dopo 5-15
		// minuti si preoccupa. Facciamo un tentativo inline: se va bene marca
		// come "sent" nel record appena creato; se fallisce, lascia in coda
		// (il cron si occupera' dei retry). Non blocchiamo mai il salvataggio
		// del booking per un webhook.
		try {
			( new Webhook_Dispatcher_Service() )->dispatch_due( 5 );
		} catch ( \Throwable $e ) {
			$this->logger->log( 'webhook_dispatch_immediate_error', 'booking', (int) $booking['id'], null,
				array( 'event' => $event, 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Costruisce payload nel formato Escape Navigator (vedi sottoscacco/services/escapeNavigatorService.ts).
	 */
	public function build_payload( string $event, array $booking ): array {
		$customer = $booking['customer_id'] ? $this->customers->find( (int) $booking['customer_id'] ) : null;
		$room     = $this->rooms->find( (int) $booking['room_id'] );

		$prefix      = (string) em_setting( 'em_sottoscacco_external_id_prefix', 'em-' );
		$external_id = $prefix . $booking['id'];

		// Converti start_datetime UTC in ISO con .000Z
		$utc_iso = '';
		try {
			$utc_iso = ( new \DateTimeImmutable( $booking['start_datetime'], new \DateTimeZone( 'UTC' ) ) )
				->format( 'Y-m-d\TH:i:s.000\Z' );
		} catch ( \Exception $e ) {}

		$client_name    = $customer['first_name'] ?? 'Cliente EM';
		$client_surname = $customer['last_name'] ?? null;
		$client_phone   = $customer['phone'] ?? '';
		$client_email   = $customer['email'] ?? null;

		$payload = array(
			'action' => $event,
			'data'   => array(
				'id'       => $external_id,
				'utcDate'  => $utc_iso,
				'players'  => (int) $booking['total_players'],
				'children' => (int) $booking['children'],
				'client'   => array(
					'name'    => $client_name,
					'surname' => $client_surname,
					'phone'   => $client_phone,
					'email'   => $client_email,
				),
				'questroom' => array(
					'title' => $room['name'] ?? '',
					'slug'  => $room['slug'] ?? '',
				),
				'comment'  => $booking['customer_comment'] ?? null,
			),
		);

		// Estensione EM: master pre-assegnato (Sottoscacco lo gestirà se presente)
		if ( ! empty( $booking['assigned_staff_id'] ) ) {
			$payload['data']['assigned_master'] = array(
				'em_employee_id' => (int) $booking['assigned_staff_id'],
			);
		}

		return $payload;
	}

	public function test_connection(): array|\WP_Error {
		$url    = (string) em_setting( 'em_sottoscacco_webhook_url', '' );
		$secret = (string) em_setting( 'em_sottoscacco_webhook_secret', '' );
		if ( ! $url || ! $secret ) {
			return new \WP_Error( 'NOT_CONFIGURED', __( 'URL o secret non configurati.', 'escape-manager' ) );
		}

		$response = wp_remote_post( $url, array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'action' => 'ping',
				'data'   => array( 'id' => 'em-ping-' . time(), 'utcDate' => gmdate( 'Y-m-d\TH:i:s.000\Z' ) ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'status_code' => wp_remote_retrieve_response_code( $response ),
			'body'        => wp_remote_retrieve_body( $response ),
		);
	}
}
