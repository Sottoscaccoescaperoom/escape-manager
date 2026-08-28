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
