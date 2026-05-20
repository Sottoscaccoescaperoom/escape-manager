<?php
namespace EscapeManager\Services;

use EscapeManager\Repositories\Tariff_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calcolo prezzo: cerca la tariffa che matcha numero giocatori (per stanza o globale).
 * Ritorna importo in CENTESIMI.
 */
final class Pricing_Service {

	public function __construct(
		private Tariff_Repository $tariffs = new Tariff_Repository(),
		private ?Promocode_Service $promocodes = null,
		private ?Voucher_Service $vouchers = null
	) {
		$this->promocodes ??= new Promocode_Service();
		$this->vouchers   ??= new Voucher_Service();
	}

	public function calculate( int $room_id, int $total_players ): int {
		$matches = $this->tariffs->for_room( $room_id );
		usort( $matches, static fn( $a, $b ) => ( $b['room_id'] ? 1 : 0 ) <=> ( $a['room_id'] ? 1 : 0 ) );

		foreach ( $matches as $t ) {
			if ( $total_players >= (int) $t['min_players'] && $total_players <= (int) $t['max_players'] ) {
				if ( $t['price_type'] === 'per_person' ) {
					return (int) $t['price_per_person'] * $total_players;
				}
				return (int) $t['fixed_price'];
			}
		}
		return 0;
	}

	/**
	 * Calcola con eventuale sconto promocode/voucher applicato.
	 *
	 * @return array{
	 *   base_cents:int, discount_cents:int, total_cents:int,
	 *   promocode_id?:int, voucher_id?:int, applied_code?:string
	 * }
	 */
	public function calculate_with_discounts( int $room_id, int $total_players, ?string $promocode = null, ?string $voucher_code = null ): array {
		$base    = $this->calculate( $room_id, $total_players );
		$out     = array( 'base_cents' => $base, 'discount_cents' => 0, 'total_cents' => $base );

		if ( $promocode ) {
			$r = $this->promocodes->validate_and_compute( $promocode, $base );
			if ( ! is_wp_error( $r ) ) {
				$out['discount_cents'] += $r['discount_cents'];
				$out['total_cents']    -= $r['discount_cents'];
				$out['promocode_id']    = $r['promocode_id'];
				$out['applied_code']    = $r['code'];
			}
		} elseif ( $voucher_code ) {
			$r = $this->vouchers->validate_and_compute( $voucher_code, $base );
			if ( ! is_wp_error( $r ) ) {
				$out['discount_cents'] += $r['discount_cents'];
				$out['total_cents']    -= $r['discount_cents'];
				$out['voucher_id']      = $r['voucher_id'];
				$out['applied_code']    = $r['code'];
			}
		}

		$out['total_cents'] = max( 0, $out['total_cents'] );
		return $out;
	}
}
