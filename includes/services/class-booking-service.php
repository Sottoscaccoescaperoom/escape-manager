<?php
namespace EscapeManager\Services;

use EscapeManager\Domain\Booking;
use EscapeManager\Repositories\Booking_Repository;
use EscapeManager\Repositories\Customer_Repository;
use EscapeManager\Repositories\Lock_Repository;
use EscapeManager\Repositories\Room_Repository;
use EscapeManager\Repositories\Location_Repository;
use EscapeManager\Repositories\Promocode_Repository;
use EscapeManager\Repositories\Voucher_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestratore prenotazioni: creazione, transizioni stato, integrazione con lock.
 * UNICO punto di transizione stato: transition_to().
 */
final class Booking_Service {

	public function __construct(
		private Booking_Repository $bookings = new Booking_Repository(),
		private Customer_Repository $customers = new Customer_Repository(),
		private Lock_Repository $locks = new Lock_Repository(),
		private Room_Repository $rooms = new Room_Repository(),
		private Location_Repository $locations = new Location_Repository(),
		private Pricing_Service $pricing = new Pricing_Service(),
		private Activity_Logger $logger = new Activity_Logger()
	) {}

	/**
	 * Crea booking pubblico partendo da un temporary_lock.
	 *
	 * @param array $payload
	 *   - lock_id (int)
	 *   - session_id (string)
	 *   - customer: { first_name, last_name?, phone, email?, birthday?, address? }
	 *   - adults (int), children (int)
	 *   - customer_comment?
	 *   - payment_method ('on_site'|'online'...)
	 *
	 * @return array|\WP_Error  booking row su success
	 */
	public function create_from_public( array $payload ): array|\WP_Error {
		$lock_id    = (int) ( $payload['lock_id'] ?? 0 );
		$session_id = (string) ( $payload['session_id'] ?? '' );

		$lock = $this->locks->find_active( $lock_id );
		if ( ! $lock || $lock['session_id'] !== $session_id ) {
			return new \WP_Error( 'LOCK_INVALID', __( 'Il lock è scaduto o non valido. Riseleziona l\'orario.', 'escape-manager' ), array( 'status' => 409 ) );
		}

		$room = $this->rooms->find( (int) $lock['room_id'] );
		if ( ! $room ) {
			return new \WP_Error( 'ROOM_NOT_FOUND', __( 'Stanza non disponibile.', 'escape-manager' ), array( 'status' => 404 ) );
		}

		$customer_data = (array) ( $payload['customer'] ?? array() );
		if ( empty( $customer_data['first_name'] ) || empty( $customer_data['phone'] ) ) {
			return new \WP_Error( 'CUSTOMER_REQUIRED', __( 'Nome e telefono obbligatori.', 'escape-manager' ), array( 'status' => 400 ) );
		}

		// Fasce d'età: adulti (13+), ragazzi ridotti (7-12), bambini omaggio (0-6).
		// Retrocompat: se arriva solo `children`, lo trattiamo come ragazzi ridotti.
		$adults   = max( 0, (int) ( $payload['adults'] ?? 0 ) );
		$reduced  = max( 0, (int) ( $payload['children_reduced'] ?? $payload['children'] ?? 0 ) );
		$free     = max( 0, (int) ( $payload['children_free'] ?? 0 ) );
		$total    = $adults + $reduced + $free;

		if ( $total < (int) $room['min_players'] || $total > (int) $room['max_players'] ) {
			return new \WP_Error( 'PARTICIPANTS_OUT_OF_RANGE', sprintf(
				/* translators: 1: min 2: max */
				__( 'Numero giocatori deve essere tra %1$d e %2$d.', 'escape-manager' ),
				$room['min_players'],
				$room['max_players']
			), array( 'status' => 422 ) );
		}

		$customer_id = $this->customers->find_or_create( $customer_data );

		$event_type = (string) ( $payload['event_type'] ?? 'standard' );
		$pricing = $this->pricing->quote_event( array(
			'adults'           => $adults,
			'children_reduced' => $reduced,
			'children_free'    => $free,
			'event_type'       => $event_type,
			'addon_gift'       => ! empty( $payload['addon_gift'] ),
			'code'             => $payload['discount_code'] ?? $payload['promocode'] ?? $payload['voucher_code'] ?? null,
		) );
		$total_amount = $pricing['total_cents'];
		$method       = (string) ( $payload['payment_method'] ?? 'on_site' );
		$booking_status = $method === 'online'
			? Booking::STATUS_AWAITING_PAYMENT
			: Booking::STATUS_CONFIRMED;

		$booking_id = $this->bookings->create( array(
			'booking_code'    => Booking::generate_code( em_setting( 'em_booking_code_prefix', 'EM' ) ),
			'room_id'         => (int) $room['id'],
			'location_id'     => (int) $room['location_id'],
			'customer_id'     => $customer_id,
			'start_datetime'  => $lock['start_datetime'],
			'end_datetime'    => $lock['end_datetime'],
			'adults'          => $adults,
			'children'        => $reduced + $free,
			'children_reduced' => $reduced,
			'children_free'   => $free,
			'total_players'   => $total,
			'total_amount'    => $total_amount,
			'addons_amount'   => $pricing['addons_cents'],
			'event_type'      => $event_type,
			'event_label'     => isset( $payload['event_label'] ) ? sanitize_text_field( (string) $payload['event_label'] ) : null,
			'paid_amount'     => 0,
			'payment_method'  => $method,
			'payment_status'  => Booking::PAYMENT_UNPAID,
			'booking_status'  => $booking_status,
			'source'          => 'public',
			'customer_comment' => $payload['customer_comment'] ?? null,
		) );

		$this->locks->delete( (int) $lock['id'] );
		$this->customers->increment_booking_count( $customer_id, (int) $room['id'] );

		// Consume promocode/voucher se applicati
		if ( ! empty( $pricing['promocode_id'] ) ) {
			( new Promocode_Service() )->consume( $pricing['promocode_id'] );
		}
		if ( ! empty( $pricing['voucher_id'] ) ) {
			( new Voucher_Service() )->redeem( $pricing['voucher_id'] );
		}

		$booking = $this->bookings->find( $booking_id );

		$this->logger->log( 'booking_created_public', 'booking', $booking_id, null, $booking );
		do_action( 'em_booking_created', $booking );
		if ( $booking_status === Booking::STATUS_CONFIRMED ) {
			do_action( 'em_booking_status_changed', $booking, Booking::STATUS_TEMPORARY_LOCK, Booking::STATUS_CONFIRMED );
		}

		return $booking;
	}

	/**
	 * Crea booking manuale da CRM (no lock necessario).
	 */
	public function create_manual( array $payload ): array|\WP_Error {
		$room_id  = (int) ( $payload['room_id'] ?? 0 );
		$room     = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return new \WP_Error( 'ROOM_NOT_FOUND', __( 'Stanza non trovata.', 'escape-manager' ), array( 'status' => 404 ) );
		}

		$start_utc = (string) ( $payload['start_datetime'] ?? '' );
		try {
			$start_dt = new \DateTimeImmutable( $start_utc, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'INVALID_DATETIME', __( 'Data/ora non valida.', 'escape-manager' ), array( 'status' => 400 ) );
		}
		$end_dt   = $start_dt->modify( '+' . (int) $room['duration_minutes'] . ' minutes' );
		$end_utc  = $end_dt->format( 'Y-m-d H:i:s' );
		$start_utc = $start_dt->format( 'Y-m-d H:i:s' );

		if ( $this->bookings->has_overlap( $room_id, $start_utc, $end_utc ) ) {
			return new \WP_Error( 'SLOT_UNAVAILABLE', __( 'Slot non disponibile.', 'escape-manager' ), array( 'status' => 409 ) );
		}

		$customer_data = (array) ( $payload['customer'] ?? array() );
		if ( ! empty( $payload['customer_id'] ) ) {
			$customer_id = (int) $payload['customer_id'];
		} else {
			if ( empty( $customer_data['first_name'] ) || empty( $customer_data['phone'] ) ) {
				return new \WP_Error( 'CUSTOMER_REQUIRED', __( 'Cliente: nome e telefono obbligatori.', 'escape-manager' ), array( 'status' => 400 ) );
			}
			$customer_id = $this->customers->find_or_create( $customer_data );
		}

		$adults   = max( 0, (int) ( $payload['adults'] ?? 0 ) );
		$children = max( 0, (int) ( $payload['children'] ?? 0 ) );
		$total    = $adults + $children;
		$total_amount = (int) ( $payload['total_amount'] ?? $this->pricing->calculate( $room_id, $total ) );
		$paid_amount  = (int) ( $payload['paid_amount'] ?? 0 );

		$payment_status = Booking::PAYMENT_UNPAID;
		if ( $paid_amount >= $total_amount && $total_amount > 0 ) {
			$payment_status = Booking::PAYMENT_PAID;
		} elseif ( $paid_amount > 0 ) {
			$payment_status = Booking::PAYMENT_PARTIALLY_PAID;
		}

		$booking_id = $this->bookings->create( array(
			'booking_code'   => Booking::generate_code( em_setting( 'em_booking_code_prefix', 'EM' ) ),
			'room_id'        => $room_id,
			'location_id'    => (int) $room['location_id'],
			'customer_id'    => $customer_id,
			'start_datetime' => $start_utc,
			'end_datetime'   => $end_utc,
			'adults'         => $adults,
			'children'       => $children,
			'total_players'  => $total,
			'total_amount'   => $total_amount,
			'paid_amount'    => $paid_amount,
			'payment_method' => $payload['payment_method'] ?? 'on_site',
			'payment_status' => $payment_status,
			'booking_status' => Booking::STATUS_CONFIRMED,
			'source'         => 'manual',
			'customer_comment' => $payload['customer_comment'] ?? null,
			'internal_notes'   => $payload['internal_notes'] ?? null,
			'created_by'       => get_current_user_id() ?: null,
			'assigned_staff_id' => $payload['assigned_staff_id'] ?? null,
		) );

		$this->customers->increment_booking_count( $customer_id, $room_id );

		$booking = $this->bookings->find( $booking_id );
		$this->logger->log( 'booking_created_manual', 'booking', $booking_id, null, $booking );
		do_action( 'em_booking_created', $booking );
		do_action( 'em_booking_status_changed', $booking, null, Booking::STATUS_CONFIRMED );

		return $booking;
	}

	/**
	 * Transizione di stato (UNICO punto autorizzato).
	 */
	public function transition_to( int $booking_id, string $new_status, array $context = array() ): array|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new \WP_Error( 'NOT_FOUND', __( 'Prenotazione non trovata.', 'escape-manager' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $new_status, Booking::ALL_STATUSES, true ) ) {
			return new \WP_Error( 'INVALID_STATUS', __( 'Stato non valido.', 'escape-manager' ), array( 'status' => 400 ) );
		}

		$old_status = $booking['booking_status'];
		$update     = array( 'booking_status' => $new_status );

		if ( $new_status === Booking::STATUS_CANCELLED && ! empty( $context['reason'] ) ) {
			$update['cancellation_reason'] = (string) $context['reason'];
		}

		$this->bookings->update( $booking_id, $update );

		$updated = $this->bookings->find( $booking_id );

		$this->logger->log( 'booking_status_change', 'booking', $booking_id, array( 'status' => $old_status ), array( 'status' => $new_status ) );
		do_action( 'em_booking_status_changed', $updated, $old_status, $new_status );

		return $updated;
	}

	public function add_note( int $booking_id, string $note ): array|\WP_Error {
		global $wpdb;
		$wpdb->insert(
			em_table( 'notes' ),
			array(
				'entity_type' => 'booking',
				'entity_id'   => $booking_id,
				'note'        => $note,
				'created_by'  => get_current_user_id() ?: null,
				'created_at'  => em_now_utc(),
			)
		);
		return array( 'id' => (int) $wpdb->insert_id );
	}

	public function assign_staff( int $booking_id, int $staff_id ): array|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new \WP_Error( 'NOT_FOUND', __( 'Prenotazione non trovata.', 'escape-manager' ), array( 'status' => 404 ) );
		}
		$this->bookings->update( $booking_id, array( 'assigned_staff_id' => $staff_id ) );
		$updated = $this->bookings->find( $booking_id );
		$this->logger->log( 'booking_assign_staff', 'booking', $booking_id, array( 'assigned_staff_id' => $booking['assigned_staff_id'] ), array( 'assigned_staff_id' => $staff_id ) );
		do_action( 'em_booking_updated', $updated, $booking );
		return $updated;
	}
}
