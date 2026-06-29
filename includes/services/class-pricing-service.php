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

	/** Tipi evento che richiedono il nome del festeggiato e danno diritto allo sconto "1 gratis". */
	public const CELEBRATIONS = array( 'compleanno', 'addio_celibato', 'addio_nubilato' );

	/** Prezzo per adulto in base alla dimensione del gruppo PAGANTE (adulti + ragazzi ridotti). */
	public function price_per_adult( int $group_size ): int {
		if ( $group_size >= 4 ) return (int) em_setting( 'em_price_adult_4plus', 2200 );
		if ( $group_size === 3 ) return (int) em_setting( 'em_price_adult_3', 2500 );
		if ( $group_size === 2 ) return (int) em_setting( 'em_price_adult_2', 3000 );
		return (int) em_setting( 'em_price_adult_4plus', 2200 );
	}

	/**
	 * Preventivo a fasce d'età + evento (stessa logica del check-in Sottoscacco).
	 * Importi in CENTESIMI. Non tocca il DB: usabile sia per il quote live sia alla conferma.
	 *
	 * @param array $in adults, children_reduced (7-12), children_free (0-6),
	 *                  event_type, addon_gift (bool), promocode, voucher_code
	 */
	public function quote_event( array $in ): array {
		$adults   = max( 0, (int) ( $in['adults'] ?? 0 ) );
		$reduced  = max( 0, (int) ( $in['children_reduced'] ?? 0 ) );
		$free     = max( 0, (int) ( $in['children_free'] ?? 0 ) );
		$event    = (string) ( $in['event_type'] ?? 'standard' );
		$group    = $adults + $reduced;
		$players  = $adults + $reduced + $free;

		$ppa            = $this->price_per_adult( $group );
		$child_reduced  = (int) em_setting( 'em_price_child_reduced', 1500 );
		$subtotal       = $adults * $ppa + $reduced * $child_reduced;

		// Celebrazione: se i giocatori totali ≥ soglia, una persona non paga.
		$threshold      = (int) em_setting( 'em_celebration_threshold', 6 );
		$celeb_discount = (int) em_setting( 'em_celebration_discount', 2200 );
		$is_celebration = in_array( $event, self::CELEBRATIONS, true );
		$celeb_eligible = $is_celebration && $players >= $threshold;
		$event_discount = $celeb_eligible ? min( $subtotal, $celeb_discount ) : 0;

		// Add-on legacy (es. regalo) — mantenuto per compatibilità.
		$addons = ! empty( $in['addon_gift'] ) ? (int) em_setting( 'em_addon_gift', 500 ) : 0;

		// §extras — servizi extra "Rendi speciale il tuo evento". Prezzo AUTOREVOLE
		// preso dal DB per id (mai dal client). Solo extra attivi.
		$extras_total = 0;
		$extras_out   = array();
		$extra_ids    = isset( $in['extras'] ) && is_array( $in['extras'] ) ? $in['extras'] : array();
		if ( ! empty( $extra_ids ) ) {
			$rows = ( new \EscapeManager\Repositories\Event_Extra_Repository() )->find_many( $extra_ids );
			foreach ( $rows as $r ) {
				if ( empty( $r['is_active'] ) ) {
					continue;
				}
				$extras_total += (int) $r['price_cents'];
				$extras_out[]  = array( 'id' => (int) $r['id'], 'title' => $r['title'], 'price_cents' => (int) $r['price_cents'] );
			}
		}

		// Promo/voucher applicati al costo giocatori al netto dello sconto evento.
		// Campo unico "codice": si prova prima come promocode, poi come voucher.
		$promo_base = max( 0, $subtotal - $event_discount );
		$code = $in['code'] ?? $in['discount_code'] ?? $in['promocode'] ?? $in['voucher_code'] ?? null;
		$code_discount = 0;
		$out = array();
		if ( ! empty( $code ) ) {
			$r = $this->promocodes->validate_and_compute( (string) $code, $promo_base );
			if ( ! is_wp_error( $r ) ) {
				$code_discount = $r['discount_cents']; $out['promocode_id'] = $r['promocode_id']; $out['applied_code'] = $r['code'];
			} else {
				$r = $this->vouchers->validate_and_compute( (string) $code, $promo_base );
				if ( ! is_wp_error( $r ) ) { $code_discount = $r['discount_cents']; $out['voucher_id'] = $r['voucher_id']; $out['applied_code'] = $r['code']; }
			}
		}

		$total = max( 0, $promo_base - $code_discount ) + $addons + $extras_total;

		return array_merge( array(
			'adults'              => $adults,
			'children_reduced'    => $reduced,
			'children_free'       => $free,
			'total_players'       => $players,
			'price_per_adult'     => $ppa,
			'child_reduced_price' => $child_reduced,
			'subtotal_cents'      => $subtotal,
			'event_type'          => $event,
			'is_celebration'      => $is_celebration,
			'celebration_threshold' => $threshold,
			'celebration_eligible'  => $celeb_eligible,
			'event_discount_cents'  => $event_discount,
			'addons_cents'        => $addons,
			'extras_cents'        => $extras_total,
			'extras'              => $extras_out,
			'code_discount_cents' => $code_discount,
			'total_cents'         => $total,
		), $out );
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
