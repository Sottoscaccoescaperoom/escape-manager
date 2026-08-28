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
			'extras'           => isset( $payload['extras'] ) && is_array( $payload['extras'] ) ? $payload['extras'] : array(),
			'code'             => $payload['discount_code'] ?? $payload['promocode'] ?? $payload['voucher_code'] ?? null,
			// §Promo periodo — servono stanza e data del turno per lo sconto %.
			'room_id'          => (int) $room['id'],
			'game_date'        => (string) ( $payload['game_date'] ?? '' ),
			'start_datetime'   => (string) $lock['start_datetime'],
		) );
		// §Minimo prenotazione — con soli ridotti/bimbi sotto il minimo non si può
		// confermare (con un adulto il minimo è arrotondato in quote_event).
		if ( ! empty( $pricing['below_minimum'] ) ) {
			$min_eur = number_format( ( (int) $pricing['min_booking_cents'] ) / 100, 0 );
			return new \WP_Error(
				'BELOW_MINIMUM',
				sprintf( __( 'Per prenotare serve un minimo di %s€: aggiungi almeno un adulto oppure aumenta i ragazzi.', 'escape-manager' ), $min_eur ),
				array( 'status' => 422 )
			);
		}
		$total_amount = $pricing['total_cents'];
		$extras_json  = ! empty( $pricing['extras'] ) ? wp_json_encode( $pricing['extras'] ) : null;
		$method       = (string) ( $payload['payment_method'] ?? 'on_site' );
		$booking_status = $method === 'online'
			? Booking::STATUS_AWAITING_PAYMENT
			: Booking::STATUS_CONFIRMED;

		/**
		 * §Doppia prenotazione 2026-08-17 — SI RICONTROLLA QUI, alla conferma.
		 *
		 * Il lock protegge dagli altri utenti del sito, ma NON da chi scrive sul
		 * calendario da un'altra porta: il gestionale crea le prenotazioni della
		 * reception via API, e fra il momento in cui il cliente blocca lo slot e
		 * quello in cui conferma passano i minuti che gli servono per compilare
		 * il modulo.
		 *
		 * È successo il 17/08/2026 su Occhio di Ra alle 17:30: la reception ha
		 * inserito un turno alle 13:17:26, il cliente ha confermato il suo alle
		 * 13:17:53 — ventisette secondi dopo — e nessuno dei due percorsi si è
		 * accorto dell'altro. Due squadre diverse sulla stessa stanza alla stessa
		 * ora, scoperte solo dalla segnalazione automatica del gestionale.
		 */
		if ( $this->bookings->has_overlap( (int) $room['id'], (string) $lock['start_datetime'], (string) $lock['end_datetime'] ) ) {
			$this->locks->delete( (int) $lock['id'] );
			return new \WP_Error(
				'SLOT_TAKEN',
				__( 'Questo orario è stato appena prenotato da qualcun altro. Scegli un altro orario: non ti abbiamo addebitato nulla.', 'escape-manager' ),
				array( 'status' => 409 )
			);
		}

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
			'addons_amount'   => (int) $pricing['addons_cents'] + (int) ( $pricing['extras_cents'] ?? 0 ),
			'extras_json'     => $extras_json,
			'event_type'      => $event_type,
			'event_label'     => isset( $payload['event_label'] ) ? sanitize_text_field( (string) $payload['event_label'] ) : null,
			'paid_amount'     => 0,
			'payment_method'  => $method,
			'payment_status'  => Booking::PAYMENT_UNPAID,
			'booking_status'  => $booking_status,
			'source'          => 'public',
			'customer_comment' => $payload['customer_comment'] ?? null,
		) );

		/**
		 * §Doppia prenotazione 2026-08-17 — LA RETE SOTTO LA RETE.
		 *
		 * Il controllo qui sopra lascia aperta una finestra di millisecondi: due
		 * richieste possono superarlo insieme e inserire entrambe. Invece di
		 * bloccare le tabelle — costoso e facile da sbagliare — si guarda DOPO:
		 * se sullo slot c'è finita anche un'altra prenotazione attiva, vince la
		 * più vecchia e questa si annulla da sola.
		 *
		 * ⚠️ Il criterio è l'id più basso, non l'orario: due insert nello stesso
		 * secondo hanno lo stesso `created_at`, e serve una regola che dia lo
		 * stesso risultato a chiunque la applichi — altrimenti si annullano a
		 * vicenda e la fascia resta vuota con due clienti convinti di averla.
		 */
		$conflitto = $this->bookings->has_overlap(
			(int) $room['id'], (string) $lock['start_datetime'], (string) $lock['end_datetime'], (int) $booking_id
		);
		if ( $conflitto ) {
			$this->bookings->update( (int) $booking_id, array(
				'booking_status'  => Booking::STATUS_CANCELLED,
				'internal_notes'  => 'Annullata: slot occupato da una prenotazione precedente (doppia prenotazione evitata).',
			) );
			$this->locks->delete( (int) $lock['id'] );
			return new \WP_Error(
				'SLOT_TAKEN',
				__( 'Questo orario è stato appena prenotato da qualcun altro. Scegli un altro orario: non ti abbiamo addebitato nulla.', 'escape-manager' ),
				array( 'status' => 409 )
			);
		}

		$this->locks->delete( (int) $lock['id'] );
		$this->customers->increment_booking_count( $customer_id, (int) $room['id'] );

		// Consume promocode/voucher se applicati
		// §SEC 2026-07-22 (audit em-plugin) — consumo atomico/condizionato al
		// limite e, per i voucher, PARZIALE (scala solo l'importo usato).
		//
		// §SEC 2026-07-27 (audit F47, terzo giro) — COMPENSAZIONE.
		// Il consumo atomico protegge il CONTATORE, ma non proteggeva il DENARO:
		// la prenotazione viene creata PRIMA del consumo, con lo sconto gia'
		// applicato. Se il consumo falliva — codice esaurito da una prenotazione
		// concorrente, voucher gia' speso — il promocode si limitava a scrivere una
		// riga di log e il voucher ignorava del tutto l'esito: in entrambi i casi la
		// prenotazione RESTAVA scontata. Il limite d'uso risultava rispettato nei
		// numeri e violato nei fatti.
		//
		// Ora, se il consumo non ha effetto, lo sconto viene tolto dalla
		// prenotazione appena creata e il totale torna quello pieno.
		$code_discount = (int) ( $pricing['code_discount_cents'] ?? 0 );
		$sconto_da_revocare = 0;
		$motivo_revoca      = '';

		if ( ! empty( $pricing['promocode_id'] ) ) {
			$ok = ( new Promocode_Service() )->consume( (int) $pricing['promocode_id'] );
			if ( ! $ok ) {
				$sconto_da_revocare = $code_discount;
				$motivo_revoca      = sprintf( 'promocode #%d oltre il limite d\'uso', (int) $pricing['promocode_id'] );
			}
		}
		// Il voucher si riscatta solo se c'e' davvero un importo da scalare: con 0
		// `redeem()` ricadrebbe su `mark_used()` e brucerebbe l'INTERO voucher.
		if ( ! empty( $pricing['voucher_id'] ) && $code_discount > 0 ) {
			$ok = ( new Voucher_Service() )->redeem( (int) $pricing['voucher_id'], $code_discount );
			if ( ! $ok ) {
				$sconto_da_revocare = $code_discount;
				$motivo_revoca      = sprintf( 'voucher #%d non piu\' attivo o gia\' consumato', (int) $pricing['voucher_id'] );
			}
		}

		if ( $sconto_da_revocare > 0 ) {
			$total_amount = $total_amount + $sconto_da_revocare;
			$this->bookings->update( $booking_id, array( 'total_amount' => $total_amount ) );
			error_log( sprintf(
				'[escape-manager] Sconto revocato su booking #%d (%s): totale riportato a %d centesimi.',
				(int) $booking_id,
				$motivo_revoca,
				$total_amount
			) );
			$this->logger->log( 'booking_discount_revoked', 'booking', $booking_id, null, array(
				'reason'          => $motivo_revoca,
				'discount_cents'  => $sconto_da_revocare,
				'new_total_cents' => $total_amount,
			) );
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

		/**
		 * §Doppia prenotazione 2026-08-17 — ANCHE UN CLIENTE A META' MODULO OCCUPA.
		 *
		 * Questa è la porta da cui entrano le prenotazioni della reception. Finora
		 * guardava solo le prenotazioni già confermate, non i lock: un cliente che
		 * sul sito ha scelto lo slot e sta compilando i suoi dati era invisibile,
		 * e la reception gli passava davanti senza saperlo. È esattamente ciò che
		 * è successo il 17/08 su Occhio di Ra delle 17:30.
		 *
		 * Il blocco dura quanto il lock (minuti, non ore): il messaggio dice fino
		 * a quando, perché «slot non disponibile» su una fascia che il calendario
		 * mostra libera manderebbe la reception a cercare un guasto che non c'è.
		 *
		 * 🚨 §2026-08-28 — IL MESSAGGIO DICEVA UN'ORA GIÀ PASSATA.
		 *
		 * `wp_date()` formatta col fuso di WordPress, qui UTC: alle 12:57 la
		 * reception ha letto «si libera entro le 11:01». Un orario nel passato
		 * significa una cosa sola per chi legge — è rotto — e infatti il master
		 * ha smesso di riprovare: quella prenotazione (Redroom 29/08 22:00) non
		 * è mai entrata. Ora l'ora è quella italiana (`em_local_time`) e accanto
		 * ci sono i MINUTI che mancano, che restano leggibili anche se un giorno
		 * il fuso tornasse storto.
		 *
		 * E se il lock è dello STESSO numero che la reception sta inserendo, lo
		 * dice: non è una gara con uno sconosciuto, è il cliente al telefono che
		 * sta prenotando da solo. Aspettare è la risposta giusta.
		 */
		$lock_attivo = $this->locks->active_for_room_in_range( $room_id, $start_utc, $end_utc );
		if ( ! empty( $lock_attivo ) ) {
			$fino_a  = (string) ( $lock_attivo[0]['expires_at'] ?? '' );
			$ora     = em_local_time( $fino_a );
			$minuti  = $fino_a ? (int) ceil( ( strtotime( $fino_a . ' UTC' ) - time() ) / 60 ) : 0;
			$minuti  = max( 1, $minuti );

			$solo_cifre  = static fn( $v ) => preg_replace( '/\D/', '', (string) $v );
			$tel_lock    = $solo_cifre( $lock_attivo[0]['customer_phone'] ?? '' );
			$tel_payload = $solo_cifre( ( (array) ( $payload['customer'] ?? array() ) )['phone'] ?? '' );
			$stesso_cliente = strlen( $tel_lock ) >= 8 && strlen( $tel_payload ) >= 8
				&& substr( $tel_lock, -8 ) === substr( $tel_payload, -8 );

			if ( $stesso_cliente ) {
				$messaggio = $ora
					? sprintf( __( 'Questo stesso cliente sta prenotando l\'orario dal sito proprio adesso: lascialo finire (ancora %1$d min, entro le %2$s) invece di inserirlo tu, o vi sovrapponete.', 'escape-manager' ), $minuti, $ora )
					: __( 'Questo stesso cliente sta prenotando l\'orario dal sito proprio adesso: lascialo finire invece di inserirlo tu.', 'escape-manager' );
			} else {
				$messaggio = $ora
					? sprintf( __( 'Un cliente sta prenotando questo orario proprio adesso dal sito. Se non conferma, la fascia si libera fra %1$d min (entro le %2$s): riprova allora.', 'escape-manager' ), $minuti, $ora )
					: __( 'Un cliente sta prenotando questo orario proprio adesso dal sito: riprova fra qualche minuto.', 'escape-manager' );
			}

			return new \WP_Error(
				'SLOT_LOCKED',
				$messaggio,
				array(
					'status'     => 409,
					'expires_at' => $fino_a,
				)
			);
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
			'event_type'     => $payload['event_type'] ?? null,
			'event_label'    => isset( $payload['event_label'] ) ? sanitize_text_field( (string) $payload['event_label'] ) : null,
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
