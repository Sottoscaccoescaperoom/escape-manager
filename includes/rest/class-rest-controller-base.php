<?php
namespace EscapeManager\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base controller REST.
 * Espone helper di routing, permission, validation.
 */
abstract class Rest_Controller_Base {

	public const NAMESPACE = EM_REST_NAMESPACE;

	abstract public function register_routes(): void;

	protected function require_capability( string $cap ): callable {
		return static function () use ( $cap ): bool|\WP_Error {
			// Accesso server-to-server (dashboard Sottoscacco) via API key.
			if ( self::has_valid_api_key() ) {
				return true;
			}
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'rest_forbidden', __( 'Devi essere autenticato.', 'escape-manager' ), array( 'status' => 401 ) );
			}
			if ( ! current_user_can( $cap ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'Permessi insufficienti.', 'escape-manager' ), array( 'status' => 403 ) );
			}
			return true;
		};
	}

	/**
	 * Chiave API per accesso macchina-a-macchina (es. proxy della dashboard
	 * Sottoscacco). Inviata nell'header `X-EM-Api-Key`; confrontata con il
	 * setting `em_api_key`. Evita le Application Password WP (disabilitate /
	 * header Authorization strippato dal proxy). Solo server-side.
	 */
	protected static function has_valid_api_key(): bool {
		$expected = (string) em_setting( 'em_api_key', '' );
		if ( '' === $expected ) {
			return false;
		}
		$provided = isset( $_SERVER['HTTP_X_EM_API_KEY'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_EM_API_KEY'] ) : '';
		return '' !== $provided && hash_equals( $expected, $provided );
	}

	protected function public_permission(): callable {
		return static fn(): bool => true;
	}

	protected function body( \WP_REST_Request $req ): array {
		$json = $req->get_json_params();
		return is_array( $json ) ? $json : (array) $req->get_body_params();
	}

	protected function int_param( \WP_REST_Request $req, string $key, ?int $default = null ): ?int {
		$v = $req->get_param( $key );
		return ( $v === null || $v === '' ) ? $default : (int) $v;
	}

	protected function str_param( \WP_REST_Request $req, string $key, ?string $default = null ): ?string {
		$v = $req->get_param( $key );
		return ( $v === null || $v === '' ) ? $default : (string) $v;
	}

	protected function wp_error_response( \WP_Error $err ): \WP_REST_Response {
		$data = $err->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		return em_json_error( $err->get_error_code(), $err->get_error_message(), $status );
	}

	/**
	 * §SEC 2026-07-28 — Rate limit best-effort per IP sulle rotte PUBBLICHE
	 * (preventivo, prenotazione pubblica): frena il brute-force dei codici
	 * promo/voucher e lo spam. Finestra scorrevole via transient. Ritorna
	 * WP_Error 429 al superamento, true altrimenti.
	 *
	 * @return true|\WP_Error
	 */
	protected function rate_limit( string $bucket, int $max = 40, int $window = 60 ) {
		$key = 'em_rl_' . $bucket . '_' . md5( $this->client_ip() );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return new \WP_Error( 'em_rate_limited', 'Troppe richieste, riprova tra poco.', array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, $window );
		return true;
	}

	/**
	 * IP del chiamante: ULTIMO hop di X-Forwarded-For (scritto dal proxy fidato),
	 * altrimenti REMOTE_ADDR. Il PRIMO valore di XFF è scritto dal client ed è
	 * falsificabile, quindi non va usato.
	 */
	protected function client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$last  = end( $parts );
			if ( $last ) {
				return $last;
			}
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
	}
}
