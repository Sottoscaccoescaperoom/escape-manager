<?php
namespace EscapeManager\Rest;

use EscapeManager\Auth\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Me_Controller extends Rest_Controller_Base {

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/me', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'me' ),
			'permission_callback' => $this->require_capability( 'em_view_dashboard' ),
		) );
	}

	public function me( \WP_REST_Request $req ): \WP_REST_Response {
		$user        = wp_get_current_user();
		$permissions = array();
		foreach ( Capabilities::all() as $cap ) {
			$permissions[ $cap ] = current_user_can( $cap );
		}
		return em_json_data( array(
			'id'           => $user->ID,
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'roles'        => $user->roles,
			'permissions'  => $permissions,
			'settings'     => array(
				'timezone'              => em_setting( 'em_timezone', 'Europe/Rome' ),
				'currency'              => em_setting( 'em_currency', 'EUR' ),
				'idle_timeout_minutes'  => (int) em_setting( 'em_idle_timeout_minutes', 15 ),
			),
		) );
	}
}
