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
	public function acquire( int $room_id, string $start_utc, string $session_id, ?string $phone = null, array $opts = array() ): array|\WP_Error {
		global $wpdb;

		$room = $this->rooms->find( $room_id );
		if ( ! $room || ! (int) $room['is_active'] ) {
			return new \WP_Error( 'ROOM_NOT_FOUND', __( 'Stanza non trovata o disattivata.', 'escape-manager' ), array( 'status' => 404 ) );
		}

		/**
		 * §Precedenza alla reception 2026-08-28 — Il banco batte il sito.
		 *
		 * ════════════════════════════════════════════════════════════════════
		 * PERCHE' IL MASTER DEVE POTER SCAVALCARE
		 * ════════════════════════════════════════════════════════════════════
		 *
		 * Il proprietario: «quando un master seleziona uno slot dalla dashboard
		 * per creare una prenotazione, deve avere la precedenza su chi in quel
		 * momento sta guardando lo stesso slot sul sito».
		 *
		 * Non e' una preferenza di comodo: il master ha in mano un cliente che
		 * sta parlando, spesso al telefono o davanti al banco, e a cui sta per
		 * dire «sì, alle 21 c'è posto». Chi sul sito ha aperto quello slot
		 * potrebbe non concludere mai — la maggioranza dei lock scade senza
		 * prenotazione. Far perdere una vendita certa per proteggere una
		 * possibile e' il compromesso sbagliato.
		 *
		 * ⚠️ Si scavalca un LOCK, mai una PRENOTAZIONE. Una prenotazione
		 * confermata e' un impegno preso con qualcuno: quella resta 409 per
		 * tutti, master compreso.
		 *
		 * ⚠️ Chi scavalca lo viene a sapere: `preempted` torna a chi chiama,
		 * perche' la dashboard possa dirlo al master. Il caso in cui aspettare
		 * e' giusto esiste — e' il cliente al telefono che sta prenotando da
		 * solo mentre parla con la reception — e chi ha in mano la situazione
		 * puo' riconoscerlo solo se sa che e' successo.
		 */
		$preempt      = ! empty( $opts['preempt'] );
		$ttl_richiesto = isset( $opts['ttl_minutes'] ) ? (int) $opts['ttl_minutes'] : 0;
		$ttl_minutes  = $ttl_richiesto > 0 ? $ttl_richiesto : (int) em_setting( 'em_lock_ttl_minutes', 10 );
		$ttl_minutes  = max( 1, min( 60, $ttl_minutes ) );
		$ttl_seconds  = $ttl_minutes * 60;

		// §Fix 2026-07-15 — L'utente deve poter tornare indietro e scegliere un
		// ALTRO orario liberamente, senza pop-up "hai già un orario in fase di
		// prenotazione". Invece di rifiutare, RILASCIAMO l'eventuale lock
		// precedente della sessione: il lock "si sposta" sul nuovo slot.
		if ( $this->locks->count_active_for_session( $session_id ) >= 1 ) {
			$this->locks->release_all_for_session( $session_id );
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
			$preempted = 0;
			if ( $this->locks->has_active_overlap( $room_id, $start_utc, $end_utc, $session_id ) ) {
				if ( ! $preempt ) {
					$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex_key ) );
					return new \WP_Error( 'SLOT_UNAVAILABLE', __( 'Slot già in fase di prenotazione da altri.', 'escape-manager' ), array( 'status' => 409 ) );
				}
				// Si tolgono di mezzo i lock altrui su questo slot, uno per uno:
				// quelli di questa stessa sessione non si contano come scavalcati.
				foreach ( $this->locks->active_for_room_in_range( $room_id, $start_utc, $end_utc ) as $altrui ) {
					if ( ( $altrui['session_id'] ?? '' ) === $session_id ) {
						continue;
					}
					$this->locks->delete( (int) $altrui['id'] );
					$preempted++;
				}
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
				// Quanti stavano guardando questo slot dal sito e sono stati
				// scavalcati: zero nel caso normale.
				'preempted'   => $preempted,
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
