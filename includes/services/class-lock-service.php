<?php
namespace EscapeManager\Services;

use EscapeManager\Repositories\Lock_Repository;
use EscapeManager\Repositories\Booking_Repository;
use EscapeManager\Repositories\Room_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestisce i temporary_locks: lifecycle, transazione anti-collision, cleanup scadenze.
 */
final class Lock_Service {

	public function __construct(
		private Lock_Repository $locks = new Lock_Repository(),
		private Booking_Repository $bookings = new Booking_Repository(),
		private Room_Repository $rooms = new Room_Repository()
	) {}

	/**
	 * Tenta di creare un lock. Ritorna lock_id + expires_at oppure WP_Error in caso di collisione.
	 *
	 * @param int    $room_id
	 * @param string $start_utc 'Y-m-d H:i:s' UTC
	 * @param string $session_id UUID client
	 * @param string|null $phone
	 *
	 * @return array{lock_id:int, expires_at:string, ttl_seconds:int}|\WP_Error
	 */
	public function acquire( int $room_id, string $start_utc, string $session_id, ?string $phone = null ): array|\WP_Error {
		global $wpdb;

		$room = $this->rooms->find( $room_id );
		if ( ! $room || ! (int) $room['is_active'] ) {
			return new \WP_Error( 'ROOM_NOT_FOUND', __( 'Stanza non trovata o disattivata.', 'escape-manager' ), array( 'status' => 404 ) );
		}

		$ttl_minutes  = (int) em_setting( 'em_lock_ttl_minutes', 10 );
		$ttl_minutes  = max( 1, min( 60, $ttl_minutes ) );
		$ttl_seconds  = $ttl_minutes * 60;

		// Rate limiting morbido: massimo 1 lock attivo per session.
		if ( $this->locks->count_active_for_session( $session_id ) >= 1 ) {
			return new \WP_Error( 'TOO_MANY_LOCKS', __( 'Hai già un orario in fase di prenotazione. Termina o annulla quello in corso.', 'escape-manager' ), array( 'status' => 429 ) );
		}

		// Calcola end_datetime in base a duration_minutes della stanza
		try {
			$start_dt = new \DateTimeImmutable( $start_utc, new \DateTimeZone( 'UTC' ) );
			$end_dt   = $start_dt->modify( '+' . (int) $room['duration_minutes'] . ' minutes' );
			$expires  = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( "+{$ttl_minutes} minutes" );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'INVALID_DATETIME', __( 'Data/ora non valida.', 'escape-manager' ), array( 'status' => 400 ) );
		}

		$end_utc      = $end_dt->format( 'Y-m-d H:i:s' );
		$expires_str  = $expires->format( 'Y-m-d H:i:s' );

		// Transazione: SERIALIZABLE non sempre disponibile su shared hosting,
		// usiamo InnoDB default + GET_LOCK MySQL per linearizzare richieste sullo stesso slot.
		$mutex_key = sprintf( 'em_slot_%d_%s', $room_id, md5( $start_utc ) );
		$wpdb->query( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $mutex_key ) );

		try {
			// Check overlap booking + lock
			if ( $this->bookings->has_overlap( $room_id, $start_utc, $end_utc ) ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex_key ) );
				return new \WP_Error( 'SLOT_UNAVAILABLE', __( 'Slot già prenotato.', 'escape-manager' ), array( 'status' => 409 ) );
			}
			if ( $this->locks->has_active_overlap( $room_id, $start_utc, $end_utc, $session_id ) ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex_key ) );
				return new \WP_Error( 'SLOT_UNAVAILABLE', __( 'Slot già in fase di prenotazione da altri.', 'escape-manager' ), array( 'status' => 409 ) );
			}

			$lock_id = $this->locks->create( array(
				'room_id'        => $room_id,
				'start_datetime' => $start_utc,
				'end_datetime'   => $end_utc,
				'session_id'     => $session_id,
				'customer_phone' => $phone,
				'expires_at'     => $expires_str,
			) );

			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex_key ) );

			return array(
				'lock_id'     => $lock_id,
				'expires_at'  => $expires_str,
				'ttl_seconds' => $ttl_seconds,
				'end_utc'     => $end_utc,
			);
		} catch ( \Throwable $t ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex_key ) );
			return new \WP_Error( 'LOCK_FAILED', $t->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function release( int $lock_id, string $session_id ): bool {
		$lock = $this->locks->find( $lock_id );
		if ( ! $lock || $lock['session_id'] !== $session_id ) {
			return false;
		}
		return (bool) $this->locks->delete( $lock_id );
	}

	public function find_valid_for_session( int $lock_id, string $session_id ): ?array {
		$lock = $this->locks->find_active( $lock_id );
		if ( ! $lock ) {
			return null;
		}
		if ( $lock['session_id'] !== $session_id ) {
			return null;
		}
		return $lock;
	}

	public function cleanup(): int {
		return $this->locks->cleanup_expired();
	}
}
