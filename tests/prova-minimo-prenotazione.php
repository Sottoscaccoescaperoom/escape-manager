<?php
/**
 * tests/prova-minimo-prenotazione.php — §Il minimo di 60 € non si spegne 2026-09-29
 *
 * ════════════════════════════════════════════════════════════════════════════
 * IL SITO HA VENDUTO UNA PARTITA A 22 €, E IL WIDGET NE PROMETTEVA 60.
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Segnalato dal titolare: «hanno prenotato un adulto e un bambino, il sito ha
 * detto 22 €; un'altra 45 €; poi sono venuti qua e gli abbiamo chiesto 60 € —
 * abbiamo fatto una figuraccia». In produzione `em_min_booking_cents` era
 * salvata VUOTA: `get_option` dà il valore di riserva solo quando la voce non
 * esiste, e una voce vuota tornava '' → `(int) ''` = 0 → nessun minimo.
 *
 * La prima prova qui sotto riproduce esattamente quella impostazione vuota:
 * è il caso da cui è nato il danno, e deve restare rosso per sempre.
 *
 * Si lancia: php tests/prova-minimo-prenotazione.php
 *
 * ⚠️ I `namespace { … }` con le graffe sono obbligati: questo file deve
 * definire finzioni in tre spazi di nomi diversi, e PHP non permette di
 * mescolare la forma con le graffe e quella senza.
 */

namespace {
	// ⚠️ La guardia standard di WordPress in cima ai file del plugin: senza
	// `ABSPATH` definita, `require` esce in silenzio con codice 0 — nessun
	// errore, nessun output, e il collaudo sembra non aver fatto niente.
	define( 'ABSPATH', 'C:/finto-wordpress/' );

	$GLOBALS['impostazioni'] = array();

	/**
	 * Riproduce `get_option`: il valore di riserva vale SOLO se la voce manca.
	 * Una voce che esiste ma è vuota torna vuota — ed è tutto il difetto.
	 */
	function em_setting( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $GLOBALS['impostazioni'] )
			? $GLOBALS['impostazioni'][ $key ]
			: $default;
	}

	class WP_Error {}

	function is_wp_error( $x ): bool { return $x instanceof \WP_Error; }
}

namespace EscapeManager\Repositories {
	final class Tariff_Repository {}
	final class Event_Extra_Repository {
		public function find_many( array $ids ): array { return array(); }
	}
}

namespace EscapeManager\Services {
	final class Promocode_Service {
		public function validate_and_compute( string $c, int $base ) { return new \WP_Error(); }
	}
	final class Voucher_Service {
		public function validate_and_compute( string $c, int $base ) { return new \WP_Error(); }
	}
}

namespace {
	require __DIR__ . '/../includes/services/class-pricing-service.php';

	$errori = 0;

	function prova( string $titolo, array $in, array $atteso ): void {
		global $errori;
		$q = ( new \EscapeManager\Services\Pricing_Service() )->quote_event( $in );
		foreach ( $atteso as $campo => $valore ) {
			$ottenuto = $q[ $campo ] ?? null;
			if ( $ottenuto !== $valore ) {
				$errori++;
				printf(
					"✗ %s — %s: atteso %s, ottenuto %s\n",
					$titolo, $campo, var_export( $valore, true ), var_export( $ottenuto, true )
				);
				return;
			}
		}
		printf( "✓ %s\n", $titolo );
	}

	// ── 1. L'impostazione salvata VUOTA: il caso di produzione ────────────────
	$GLOBALS['impostazioni'] = array( 'em_min_booking_cents' => '' );

	prova(
		'Impostazione vuota: 1 adulto + 1 bimbo (0-6) paga comunque 60 €',
		array( 'adults' => 1, 'children_free' => 1 ),
		array( 'subtotal_cents' => 6000, 'total_cents' => 6000, 'min_charge_applied' => true )
	);

	prova(
		'Impostazione vuota: 1 adulto + 1 ragazzo (7-10) paga 60 €, non 45 €',
		array( 'adults' => 1, 'children_reduced' => 1 ),
		array( 'subtotal_cents' => 6000, 'total_cents' => 6000 )
	);

	// ── 2. L'impostazione configurata, come dev'essere ────────────────────────
	$GLOBALS['impostazioni'] = array( 'em_min_booking_cents' => 6000 );

	prova(
		'Due adulti arrivano a 60 € da soli: nessun minimo da applicare',
		array( 'adults' => 2 ),
		array( 'subtotal_cents' => 6000, 'total_cents' => 6000, 'min_charge_applied' => false )
	);

	prova(
		'Quattro adulti: il minimo non tocca niente',
		array( 'adults' => 4 ),
		array( 'subtotal_cents' => 8800, 'total_cents' => 8800, 'min_charge_applied' => false )
	);

	/*
	 * ⚠️ Senza nessun adulto il prezzo NON cambia e non si blocca più niente:
	 * «la giocata minima è 60 €, a prescindere da che tipo di persone sono».
	 * Prima questo caso tornava `below_minimum` e il widget rispondeva
	 * «aggiungi almeno un adulto» — cioè un divieto al posto di un prezzo.
	 */
	prova(
		'Due ragazzi senza adulti: 60 €, e la prenotazione non viene respinta',
		array( 'children_reduced' => 2 ),
		array( 'subtotal_cents' => 6000, 'total_cents' => 6000, 'below_minimum' => false )
	);

	prova(
		'Un solo bimbo 0-6: non paga nessuno, e il minimo non inventa un totale',
		array( 'children_free' => 1 ),
		array( 'subtotal_cents' => 0, 'total_cents' => 0, 'min_charge_applied' => false )
	);

	// ── 3. Il minimo non è un'impostazione che si possa azzerare per sbaglio ──
	$GLOBALS['impostazioni'] = array( 'em_min_booking_cents' => 0 );

	prova(
		'Minimo impostato a zero: vale comunque il minimo di riserva',
		array( 'adults' => 1, 'children_free' => 1 ),
		array( 'subtotal_cents' => 6000, 'min_booking_cents' => 6000 )
	);

	echo $errori === 0 ? "\nTutto a posto.\n" : "\n$errori prove fallite.\n";
	exit( $errori === 0 ? 0 : 1 );
}
