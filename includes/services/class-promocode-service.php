<?php
namespace EscapeManager\Services;

use EscapeManager\Repositories\Promocode_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Promocode_Service {

	public function __construct( private Promocode_Repository $repo = new Promocode_Repository() ) {}

	/**
	 * Valida un promocode e ritorna lo sconto applicabile in centesimi.
	 *
	 * @return array{ promocode_id:int, code:string, discount_cents:int }|\WP_Error
	 */
	public function validate_and_compute( string $code, int $amount_cents ): array|\WP_Error {
		$code = strtoupper( trim( $code ) );
		if ( ! $code ) {
			return new \WP_Error( 'EMPTY', __( 'Codice vuoto.', 'escape-manager' ), array( 'status' => 400 ) );
		}

		$promo = $this->repo->find_by_code( $code );
		if ( ! $promo || ! (int) $promo['is_active'] ) {
			return new \WP_Error( 'PROMO_INVALID', __( 'Codice non valido.', 'escape-manager' ), array( 'status' => 404 ) );
		}

		$now = em_now_utc();
		if ( $promo['valid_from'] && $promo['valid_from'] > $now ) {
			return new \WP_Error( 'PROMO_NOT_YET_VALID', __( 'Codice non ancora valido.', 'escape-manager' ), array( 'status' => 422 ) );
		}
		if ( $promo['valid_to'] && $promo['valid_to'] < $now ) {
			return new \WP_Error( 'PROMO_EXPIRED', __( 'Codice scaduto.', 'escape-manager' ), array( 'status' => 422 ) );
		}
		if ( $promo['usage_limit'] !== null && (int) $promo['used_count'] >= (int) $promo['usage_limit'] ) {
			return new \WP_Error( 'PROMO_LIMIT_REACHED', __( 'Codice esaurito.', 'escape-manager' ), array( 'status' => 422 ) );
		}

		$discount = 0;
		if ( $promo['type'] === 'percent' ) {
			$discount = (int) round( $amount_cents * (int) $promo['value'] / 100 );
		} else {
			// fixed (in centesimi salvati nel campo value)
			$discount = (int) $promo['value'];
		}
		$discount = min( $discount, $amount_cents );

		return array(
			'promocode_id'  => (int) $promo['id'],
			'code'          => $promo['code'],
			'discount_cents' => $discount,
		);
	}

	/**
	 * @return bool true se il codice è stato consumato (era ancora spendibile).
	 *              false se il limite era già raggiunto (race): il chiamante può
	 *              loggare/annullare lo sconto.
	 */
	public function consume( int $promocode_id ): bool {
		return $this->repo->increment_usage( $promocode_id );
	}
}
