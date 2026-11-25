<?php
/**
 * Helper functions globali per Escape Manager.
 *
 * @package EscapeManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function em_setting( string $key, mixed $default = null ): mixed {
	$option_key = ( strpos( $key, 'em_' ) === 0 ) ? $key : 'em_' . $key;
	$value      = get_option( $option_key, $default );

	if ( is_string( $value ) && ( $value !== '' ) && ( $value[0] === '{' || $value[0] === '[' ) ) {
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}
	return $value;
}

function em_update_setting( string $key, mixed $value ): bool {
	$option_key = ( strpos( $key, 'em_' ) === 0 ) ? $key : 'em_' . $key;
	if ( is_array( $value ) || is_object( $value ) ) {
		$value = wp_json_encode( $value );
	}
	return update_option( $option_key, $value );
}

/**
 * §Badge «NUOVA» 2026-11-25 — La stanza è ancora «nuova» (badge nel widget)?
 *
 * Opzione `em_new_rooms_until`: JSON slug → ultimo giorno COMPRESO
 * (YYYY-MM-DD), es. {"michael-myers":"2027-06-11"}. Vale per qualunque
 * stanza futura: basta aggiungere una riga, e il badge si spegne da solo.
 *
 * ⚠️ Opzione assente o salvata vuota = nessuna stanza nuova (qui «vuoto» non
 * è una regola di listino: spegnere i badge è l'effetto giusto).
 * ⚠️ Il giorno si calcola nel fuso di ESCAPE MANAGER, mai con wp_date():
 * WordPress su questo sito è su UTC.
 */
function em_room_is_new( string $slug ): bool {
	$map = em_setting( 'em_new_rooms_until', array() );
	if ( ! is_array( $map ) || '' === $slug ) {
		return false;
	}
	$until = $map[ $slug ] ?? '';
	if ( ! is_string( $until ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
		return false;
	}
	$tz = (string) em_setting( 'em_timezone', 'Europe/Rome' );
	try {
		$oggi = ( new \DateTimeImmutable( 'now', new \DateTimeZone( '' !== $tz ? $tz : 'Europe/Rome' ) ) )->format( 'Y-m-d' );
	} catch ( \Exception $e ) {
		$oggi = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Europe/Rome' ) ) )->format( 'Y-m-d' );
	}
	return $oggi <= $until;
}

/**
 * §Apertura calendario 2026-11-25 — Pagina di una stanza ancora chiusa:
 * da che giorno deve partire il calendario?
 *
 * Ritorna il primo giorno (YYYY-MM-DD, ora di Roma) che NON è coperto per
 * intero dai periodi bloccati, ma solo se è DOPO oggi; altrimenti null, e il
 * widget parte da oggi come sempre. Il giorno va dalle 05:00 alle 05:00 del
 * giorno dopo (giornata operativa: i turni dopo mezzanotte sono della sera
 * prima). Esempio: Michael Myers, bloccata fino alla notte del 10/12 →
 * «2026-12-11»; dall'11/12 in poi → null, senza toccare niente.
 *
 * ⚠️ Fuso di EM, mai wp_date(): WordPress qui è su UTC.
 */
function em_first_bookable_day( string $slug ): ?string {
	global $wpdb;
	if ( '' === $slug ) {
		return null;
	}
	$room_id = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM ' . em_table( 'rooms' ) . ' WHERE slug = %s AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
		$slug
	) );
	if ( ! $room_id ) {
		return null;
	}
	$tz_name = (string) em_setting( 'em_timezone', 'Europe/Rome' );
	try {
		$tz = new \DateTimeZone( '' !== $tz_name ? $tz_name : 'Europe/Rome' );
	} catch ( \Exception $e ) {
		$tz = new \DateTimeZone( 'Europe/Rome' );
	}
	$utc  = new \DateTimeZone( 'UTC' );
	$now  = time();
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT start_datetime, end_datetime FROM ' . em_table( 'room_blocked_periods' )
		. ' WHERE room_id = %d AND end_datetime > %s ORDER BY start_datetime ASC',
		$room_id,
		gmdate( 'Y-m-d H:i:s', $now )
	), ARRAY_A ) ?: array();
	if ( empty( $rows ) ) {
		return null;
	}
	$blocchi = array();
	foreach ( $rows as $r ) {
		$blocchi[] = array(
			( new \DateTimeImmutable( $r['start_datetime'], $utc ) )->getTimestamp(),
			( new \DateTimeImmutable( $r['end_datetime'], $utc ) )->getTimestamp(),
		);
	}
	// Giornata operativa di adesso (prima delle 05:00 è ancora ieri).
	$giorno = ( new \DateTimeImmutable( '@' . ( $now - 5 * 3600 ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
	for ( $i = 0; $i < 180; $i++ ) {
		$da = new \DateTimeImmutable( $giorno . ' 05:00:00', $tz );
		$a  = $da->modify( '+1 day' );
		$cursore = max( $now, $da->getTimestamp() );
		foreach ( $blocchi as $b ) {
			if ( $b[0] <= $cursore && $b[1] > $cursore ) {
				$cursore = $b[1];
			}
		}
		if ( $cursore < $a->getTimestamp() ) {
			return 0 === $i ? null : $giorno;
		}
		$giorno = $a->format( 'Y-m-d' );
	}
	return null;
}

function em_now_utc(): string {
	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * Formatta un istante UTC ('Y-m-d H:i:s') nel fuso di ESCAPE MANAGER.
 *
 * 🚨 §Ora sbagliata 2026-08-28 — NON USARE `wp_date()` PER GLI ORARI DEI TURNI.
 *
 * `wp_date()` guarda il fuso di WordPress, che su questo sito è UTC: un orario
 * convertito con quella funzione esce due ore indietro. Il 28/08 la reception
 * si è vista rispondere «la fascia si libera entro le 11:01» mentre in Italia
 * erano le 12:57 — un'ora già passata, che fa sembrare rotto un sistema che
 * stava funzionando: il master ha smesso di riprovare e la prenotazione è
 * andata persa.
 *
 * Tutto il resto del plugin (disponibilità, calendario, email) ragiona su
 * `em_timezone`. Gli orari mostrati a un essere umano passano di qui.
 */
function em_local_time( string $utc_datetime, string $format = 'H:i' ): string {
	$utc_datetime = trim( $utc_datetime );
	if ( '' === $utc_datetime ) {
		return '';
	}
	try {
		$dt = new DateTimeImmutable( $utc_datetime, new DateTimeZone( 'UTC' ) );
	} catch ( Exception $e ) {
		return '';
	}
	$tz = (string) em_setting( 'em_timezone', 'Europe/Rome' );
	try {
		$dt = $dt->setTimezone( new DateTimeZone( $tz ) );
	} catch ( Exception $e ) {
		$dt = $dt->setTimezone( new DateTimeZone( 'Europe/Rome' ) );
	}
	return $dt->format( $format );
}

function em_format_money( int $cents, string $currency = 'EUR' ): string {
	return number_format( $cents / 100, 2, ',', '.' ) . ' ' . $currency;
}

function em_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'em_' . $name;
}

function em_json_error( string $code, string $message, int $status = 400, array $details = array() ): WP_REST_Response {
	return new WP_REST_Response(
		array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
				'details' => $details,
			),
		),
		$status
	);
}

function em_json_data( mixed $data, int $status = 200, array $meta = array() ): WP_REST_Response {
	$payload = array( 'data' => $data );
	if ( ! empty( $meta ) ) {
		$payload['meta'] = $meta;
	}
	return new WP_REST_Response( $payload, $status );
}
