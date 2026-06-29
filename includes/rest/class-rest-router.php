<?php
namespace EscapeManager\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Router {

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
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
		);
		foreach ( $controllers as $c ) {
			$c->register_routes();
		}
	}
}
