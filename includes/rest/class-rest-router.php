<?php
namespace EscapeManager\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Router {

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// §FIX 2026-07-03 — Impedisce a LiteSpeed Cache (attivo su Hostinger) di
		// memorizzare le risposte REST di Escape Manager. Sintomi risolti:
		//  1) dopo aver cambiato l'icona/video di una stanza dal CRM, il widget
		//     booking pubblico continuava a mostrare la vecchia immagine
		//     (header `x-litespeed-cache: hit`);
		//  2) più in generale la DISPONIBILITÀ degli slot deve essere sempre
		//     fresca, altrimenti si rischiano doppie prenotazioni su dati cache.
		add_filter( 'rest_post_dispatch', array( $this, 'prevent_litespeed_cache' ), 10, 3 );
	}

	/**
	 * Marca come "no-cache" tutte le risposte REST del namespace escape-manager.
	 * Usa l'API ufficiale LiteSpeed (`litespeed_control_set_nocache`) e, come
	 * rinforzo, gli header standard/HTTP che i vari layer di cache rispettano.
	 */
	public function prevent_litespeed_cache( $response, $server, $request ) {
		$route = ( is_object( $request ) && method_exists( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
		if ( $route && strpos( $route, '/escape-manager/' ) === 0 ) {
			do_action( 'litespeed_control_set_nocache', 'escape-manager REST sempre fresca' );
			if ( $response instanceof \WP_REST_Response ) {
				$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
				$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
			}
		}
		return $response;
	}

	public function register_routes(): void {
		$controllers = array(
			new Me_Controller(),
			new Locations_Controller(),
			new Rooms_Controller(),
			new Availability_Controller(),
			new Locks_Controller(),
			new Bookings_Controller(),
			new Customers_Controller(),
			new Tariffs_Controller(),
			new Settings_Controller(),
			new Calendar_Controller(),
			new Webhooks_Admin_Controller(),
			new Promocodes_Controller(),
			new Vouchers_Controller(),
			new Statistics_Controller(),
			new Export_Controller(),
			new Event_Extras_Controller(),
			new Room_Blocked_Periods_Controller(),
		);
		foreach ( $controllers as $c ) {
			$c->register_routes();
		}
	}
}
