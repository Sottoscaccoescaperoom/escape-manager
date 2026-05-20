<?php
namespace EscapeManager\Rest;

use EscapeManager\Repositories\Booking_Repository;
use EscapeManager\Repositories\Customer_Repository;
use EscapeManager\Repositories\Room_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export CSV bookings — per contabilità/commercialista.
 * Bypass del wrapping JSON: ritorna text/csv direttamente.
 */
final class Export_Controller extends Rest_Controller_Base {

	public function __construct(
		private Booking_Repository $bookings = new Booking_Repository(),
		private Customer_Repository $customers = new Customer_Repository(),
		private Room_Repository $rooms = new Room_Repository()
	) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/export/bookings.csv', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'bookings_csv' ),
			'permission_callback' => $this->require_capability( 'em_export_data' ),
		) );
	}

	public function bookings_csv( \WP_REST_Request $req ): void {
		$filters = array(
			'from'           => $this->str_param( $req, 'from' ),
			'to'             => $this->str_param( $req, 'to' ),
			'booking_status' => $this->str_param( $req, 'status' ),
			'room_id'        => $this->int_param( $req, 'room_id' ),
		);
		$filters = array_filter( $filters, static fn( $v ) => $v !== null && $v !== '' );

		$res = $this->bookings->paginate( $filters, 1, 10000 );

		$filename = 'bookings-' . gmdate( 'Y-m-d-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// BOM per Excel (UTF-8 detection)
		echo "\xEF\xBB\xBF";

		$out = fopen( 'php://output', 'w' );

		$headers = array(
			'ID', 'Codice', 'Data e ora (UTC)', 'Stanza', 'Stato', 'Pagamento',
			'Cliente', 'Telefono', 'Email', 'Adulti', 'Bambini', 'Totale (€)',
			'Pagato (€)', 'Metodo', 'Fonte', 'Note cliente', 'Creato il',
		);
		fputcsv( $out, $headers, ';' );

		foreach ( $res['rows'] as $b ) {
			$room     = $this->rooms->find( (int) $b['room_id'] );
			$customer = $b['customer_id'] ? $this->customers->find( (int) $b['customer_id'] ) : null;
			fputcsv( $out, array(
				$b['id'],
				$b['booking_code'],
				$b['start_datetime'],
				$room['name'] ?? '',
				$b['booking_status'],
				$b['payment_status'],
				$customer ? trim( $customer['first_name'] . ' ' . ( $customer['last_name'] ?? '' ) ) : '',
				$customer['phone'] ?? '',
				$customer['email'] ?? '',
				$b['adults'],
				$b['children'],
				number_format( ( $b['total_amount'] / 100 ), 2, ',', '' ),
				number_format( ( $b['paid_amount'] / 100 ), 2, ',', '' ),
				$b['payment_method'] ?? '',
				$b['source'] ?? '',
				$b['customer_comment'] ?? '',
				$b['created_at'],
			), ';' );
		}

		fclose( $out );
		exit;
	}
}
