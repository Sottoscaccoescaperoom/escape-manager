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
