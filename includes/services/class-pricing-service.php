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

	public function __construct( private Tariff_Repository $tariffs = new Tariff_Repository() ) {}

	public function calculate( int $room_id, int $total_players ): int {
		$matches = $this->tariffs->for_room( $room_id );
		// Prima tariffe specifiche per la stanza, poi globali (room_id NULL).
		usort( $matches, static fn( $a, $b ) => ( $b['room_id'] ? 1 : 0 ) <=> ( $a['room_id'] ? 1 : 0 ) );

		foreach ( $matches as $t ) {
			if ( $total_players >= (int) $t['min_players'] && $total_players <= (int) $t['max_players'] ) {
				if ( $t['price_type'] === 'per_person' ) {
					return (int) $t['price_per_person'] * $total_players;
				}
				return (int) $t['fixed_price'];
			}
		}

		// Fallback: nessuna tariffa configurata.
		return 0;
	}
}
