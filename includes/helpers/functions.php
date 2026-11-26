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

/**
 * §Tracciamento conversioni 2026-11-26 — DA DOVE È ARRIVATO CHI PRENOTA.
 *
 * Il beacon del gestionale (`ss-analytics.js`, caricato su sottoscacco.it) tiene
 * nel cookie `_ss_attr` la prima e l'ultima provenienza del visitatore: sorgente,
 * mezzo, campagna, contenuto e termine (utm_*), se c'era un gclid/gbraid/wbraid, la pagina d'arrivo e
 * la data. Il widget lo rimanda con la prenotazione; qui lo si ripulisce prima di
 * salvarlo e di passarlo al gestionale.
 *
 * ⚠️ Arriva dal browser del cliente: si tiene SOLO la forma prevista (chiavi
 * note, testi corti, niente parametri nella pagina d'arrivo). Tutto il resto si
 * butta. `null` se non c'è niente di utile: la prenotazione va avanti uguale.
 *
 * @param mixed $raw Stringa JSON (anche URL-encoded) o array già decodificato.
 */
function em_sanitize_attribution( mixed $raw ): ?array {
	if ( is_string( $raw ) ) {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 4000 ) {
			return null;
		}
		if ( 0 === stripos( $raw, '%7B' ) ) {
			$raw = rawurldecode( $raw );
		}
		$raw = json_decode( $raw, true );
	}
	if ( ! is_array( $raw ) ) {
		return null;
	}
	$tocco = static function ( mixed $t ): ?array {
		if ( ! is_array( $t ) ) {
			return null;
		}
		$o      = array();
		$limiti = array( 's' => 80, 'm' => 80, 'c' => 120, 'ct' => 120, 'tm' => 120, 'g' => 200, 'l' => 200, 't' => 40 );
		foreach ( $limiti as $k => $max ) {
			if ( isset( $t[ $k ] ) && is_scalar( $t[ $k ] ) ) {
				$v = (string) $t[ $k ];
				if ( 'l' === $k ) {
					// Solo il percorso: i parametri dell'indirizzo possono contenere di tutto.
					$v = (string) strtok( $v, '?#' );
				}
				$v = substr( sanitize_text_field( $v ), 0, $max );
				if ( '' !== $v ) {
					$o[ $k ] = $v;
				}
			}
		}
		foreach ( array( 'gc', 'gb', 'wb' ) as $k ) {
			$o[ $k ] = empty( $t[ $k ] ) ? 0 : 1;
		}
		if ( empty( $o['s'] ) && empty( $o['m'] ) && empty( $o['c'] ) && ! $o['gc'] && ! $o['gb'] && ! $o['wb'] ) {
			return null;
		}
		return $o;
	};
	$f = $tocco( $raw['f'] ?? null );
	$l = $tocco( $raw['l'] ?? null );
	if ( ! $f && ! $l ) {
		return null;
	}
	return array( 'v' => 1, 'f' => $f ?? $l, 'l' => $l ?? $f );
}

/**
 * §Tracciamento conversioni 2026-11-26 — «Come ci avete conosciuto?» chiesto
 * dallo staff nel CRM del gestionale. Solo le risposte previste (stesso elenco
 * di `lib/attribuzione.ts` nel gestionale); altrimenti null. Mai un errore.
 */
function em_sanitize_conosciuto( mixed $raw ): ?string {
	if ( ! is_string( $raw ) ) {
		return null;
	}
	$v       = strtolower( trim( $raw ) );
	$ammessi = array( 'google_ads', 'google_ricerca', 'social_meta', 'tiktok', 'passaparola', 'gia_cliente', 'locale', 'gift_card', 'altro' );
	return in_array( $v, $ammessi, true ) ? $v : null;
}
