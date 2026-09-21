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
	/**
	 * Il PRIMO indirizzo di `X-Forwarded-For`, cioè quello del cliente.
	 *
	 * ⚠️ Diverso da `client_ip()` dei controller REST, che prende l'ultimo:
	 * là serve un indirizzo che il cliente non possa falsificare (rate
	 * limiting), qui serve sapere CHI si è collegato. Due domande diverse,
	 * due estremi della stessa lista.
	 */
	private function client_ip_origine(): string {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$primo = reset( $parts );
			if ( $primo ) {
				return $primo;
			}
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	}

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
				/**
				 * §L'impronta del collegamento 2026-09-21 — DA DOVE ARRIVA.
				 *
				 * Segnalato dal titolare: «anche oggi due turni non si sono
				 * presentati e hanno prenotato con un numero di telefono
				 * falso». Sottoscacco ha da sempre un criterio «stesso
				 * collegamento» fra le spie delle prenotazioni sospette, e non
				 * ha mai potuto dire niente perché questo campo non c'era: su
				 * 629 prenotazioni da agosto, zero avevano l'indirizzo.
				 *
				 * 🚨 Si manda l'indirizzo GREZZO e Sottoscacco ne tiene solo
				 * un'impronta, calcolata con un sale che sta soltanto là. Se
				 * l'impronta la facessimo qui, il sale dovrebbe stare nei file
				 * del sito — e chi li legge potrebbe rifare l'impronta di un
				 * indirizzo qualunque e cercarla in archivio, cioè proprio la
				 * cosa da cui l'impronta dovrebbe proteggere.
				 *
				 * ⚠️ NON si usa `client_ip()`: quella prende l'ULTIMO hop,
				 * che è giusto per il rate limiting (non falsificabile) ma qui
				 * sarebbe l'indirizzo del proxy, uguale per tutti i clienti —
				 * e la spia segnalerebbe ogni prenotazione. Qui serve il
				 * PRIMO, che è il cliente: falsificabile, ma chi prenota con
				 * un numero finto raramente si mette anche a riscrivere le
				 * intestazioni HTTP.
				 */
				'clientIp' => $this->client_ip_origine(),
			),
		);

		// Estensione EM: master pre-assegnato (Sottoscacco lo gestirà se presente)
		if ( ! empty( $booking['assigned_staff_id'] ) ) {
			$payload['data']['assigned_master'] = array(
				'em_employee_id' => (int) $booking['assigned_staff_id'],
			);
		}

		// §Promo periodo 2026-07-15 — se il turno rientra nella promo (stanza + data),
		// mandiamo la percentuale al gestionale, che la mostra e la applica al
		// check-in in reception (pagamento in sede). Così lo sconto "arriva alla cassa".
		$game_date = '';
		try {
			$game_date = ( new \DateTimeImmutable( $booking['start_datetime'], new \DateTimeZone( 'UTC' ) ) )
				->setTimezone( new \DateTimeZone( 'Europe/Rome' ) )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {}
		$promo_pct = 0;
		if ( em_setting( 'em_promo_enabled', false ) ) {
			$pct   = max( 0, min( 100, (int) em_setting( 'em_promo_percent', 0 ) ) );
			$from  = (string) em_setting( 'em_promo_from', '' );
			$to    = (string) em_setting( 'em_promo_to', '' );
			$rooms = array_map( 'intval', (array) em_setting( 'em_promo_rooms', array() ) );
			if ( $pct > 0 && $from && $to && $game_date && $game_date >= $from && $game_date <= $to
				&& in_array( (int) $booking['room_id'], $rooms, true ) ) {
				$promo_pct = $pct;
			}
		}
		$payload['data']['promoPercent'] = $promo_pct;

		return $payload;
	}

	/**
	 * 🚨 §Un calendario solo 2026-09-10 — LO SLOT È LIBERO ANCHE PER IL
	 * GESTIONALE?
	 *
	 * ═════════════════════════════════════════════════════════════════
	 * I CALENDARI SONO DUE, E FINORA CIASCUNO GUARDAVA SOLO IL PROPRIO
	 * ═════════════════════════════════════════════════════════════════
	 *
	 * Chiesto dal titolare: «come si può fisicamente far prenotare due persone
	 * nello stesso turno? Il primo che prenota blocca il turno per tutti».
	 *
	 * Il controllo `has_overlap()` qui sopra guarda la tabella DI QUESTO SITO, che
	 * è una copia tenuta allineata da messaggi. Se un messaggio si perde o arriva
	 * sbagliato — il 10/09/2026 uno è arrivato con l'orario vecchio — la copia
	 * dice «libero» di una casella che in reception è già occupata, e il sito la
	 * vende in perfetta buona fede.
	 *
	 * Qui si chiede all'ALTRO calendario, che è quello dove lavora la reception.
	 *
	 * ⚠️ RISPOSTA A TRE VALORI, e la terza è quella che conta:
	 *   true  = occupato        → non si conferma
	 *   false = libero          → si prosegue
	 *   null  = non lo sappiamo → si prosegue lo stesso
	 *
	 * 🚨 IL `null` NON BLOCCA, ed è una scelta deliberata. Se il gestionale non
	 * risponde — è in aggiornamento, la rete fa i capricci — rifiutare vorrebbe
	 * dire spegnere le vendite online a ogni nostro intoppo. È lo stesso
	 * compromesso già scelto dal titolare per la reception: «far perdere una
	 * vendita certa per proteggerne una possibile è il compromesso sbagliato». Nel
	 * dubbio si vende, e il controllo automatico del gestionale se ne accorgerà
	 * entro mezz'ora — cioè si torna al comportamento di prima, non peggio.
	 *
	 * ⚠️ TIMEOUT CORTO (3 secondi). Dall'altra parte c'è un cliente fermo sul
	 * bottone «conferma»: un'attesa lunga è un carrello abbandonato, e il valore
	 * di questo controllo non vale dieci secondi di clessidra.
	 *
	 * ⚠️ L'indirizzo si RICAVA da quello del webhook invece di essere un'altra
	 * impostazione da compilare: un secondo campo da configurare a mano è un
	 * secondo campo che qualcuno dimenticherà, e questo controllo si spegnerebbe
	 * in silenzio.
	 */
	public function slot_occupato_nel_gestionale( array $room, string $start_datetime_utc ): ?bool {
		$url    = (string) em_setting( 'em_sottoscacco_webhook_url', '' );
		$secret = (string) em_setting( 'em_sottoscacco_webhook_secret', '' );
		if ( ! $url || ! $secret ) {
			return null; // ponte non configurato: non sappiamo niente, non blocchiamo.
		}

		$parti = wp_parse_url( $url );
		if ( empty( $parti['scheme'] ) || empty( $parti['host'] ) ) {
			return null;
		}
		$endpoint = $parti['scheme'] . '://' . $parti['host']
			. ( empty( $parti['port'] ) ? '' : ':' . $parti['port'] )
			. '/api/disponibilita-slot';

		$iso = '';
		try {
			$iso = ( new \DateTimeImmutable( $start_datetime_utc, new \DateTimeZone( 'UTC' ) ) )
				->format( 'Y-m-d\TH:i:s.000\Z' );
		} catch ( \Exception $e ) {
			return null;
		}

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 3,
			'headers' => array(
				'x-em-bridge-secret' => $secret,
				'Content-Type'       => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'roomName'    => $room['name'] ?? '',
				'roomId'      => $room['slug'] ?? '',
				'scheduledAt' => $iso,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$dati = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $dati ) || ! array_key_exists( 'libero', $dati ) ) {
			return null;
		}
		// ⚠️ `libero: null` è il «non lo sappiamo» del gestionale: si propaga come
		// null, NON come false. Confonderlo con «occupato» bloccherebbe le vendite.
		if ( null === $dati['libero'] ) {
			return null;
		}
		return ! $dati['libero'];
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
