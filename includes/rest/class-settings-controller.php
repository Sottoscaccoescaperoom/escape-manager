<?php
namespace EscapeManager\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Controller extends Rest_Controller_Base {

	private const ALLOWED_KEYS = array(
		'em_lock_ttl_minutes',
		'em_currency',
		'em_timezone',
		'em_idle_timeout_minutes',
		'em_required_fields_public',
		'em_required_fields_admin',
		'em_sottoscacco_bridge_enabled',
		'em_sottoscacco_webhook_url',
		'em_sottoscacco_webhook_secret',
		'em_sottoscacco_max_retries',
		'em_sottoscacco_external_id_prefix',
		'em_booking_code_prefix',
		'em_email_from_name',
		'em_email_from_address',
		'em_booking_cutoff_minutes',
		'em_promote_weak_rooms',
		'em_promo_enabled',
		'em_promo_percent',
		'em_promo_from',
		'em_promo_to',
		'em_promo_rooms',
	);

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get' ),
				'permission_callback' => $this->require_capability( 'em_view_settings' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => $this->require_capability( 'em_manage_settings' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/settings/sottoscacco/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'test_sottoscacco' ),
			'permission_callback' => $this->require_capability( 'em_manage_settings' ),
		) );
	}

	public function get( \WP_REST_Request $req ): \WP_REST_Response {
		$out = array();
		foreach ( self::ALLOWED_KEYS as $key ) {
			$value = em_setting( $key );
			// Mai esporre il secret nelle response
			if ( $key === 'em_sottoscacco_webhook_secret' ) {
				$out[ $key ] = $value ? '••• configurato •••' : '';
				continue;
			}
			$out[ $key ] = $value;
		}
		return em_json_data( $out );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $this->body( $req );
		foreach ( $body as $key => $value ) {
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				continue;
			}
			// Secret non aggiornato se valore placeholder
			if ( $key === 'em_sottoscacco_webhook_secret' && str_starts_with( (string) $value, '•••' ) ) {
				continue;
			}
			em_update_setting( $key, $value );
		}
		return $this->get( $req );
	}

	public function test_sottoscacco( \WP_REST_Request $req ): \WP_REST_Response {
		$bridge = new \EscapeManager\Services\Sottoscacco_Bridge_Service();
		$res    = $bridge->test_connection();
		if ( is_wp_error( $res ) ) return $this->wp_error_response( $res );
		return em_json_data( $res );
	}
}
