<?php
/**
 * tests/prova-blocchi-sito.php — §Nona stanza 2026-11-24
 *
 * ════════════════════════════════════════════════════════════════════════════
 * UNO SLOT CON IL LUCCHETTO NON SI PRENDE DAL SITO, NEANCHE CHIAMANDO A MANO.
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Michael Myers si vende da prima ma apre l'11/12/2026: sul sito la tengono
 * chiusa i periodi bloccati. Il widget li mostra come lucchetti, ma il lock
 * pubblico non li controllava, quindi bastava chiamare la rotta a mano.
 *
 * Si lancia: php tests/prova-blocchi-sito.php
 */

namespace {
	define( 'ABSPATH', 'C:/finto-wordpress/' );

	/** Periodi bloccati finti: [room_id, inizio UTC, fine UTC]. */
	$GLOBALS['blocchi'] = array();

	function em_setting( string $key, mixed $default = null ): mixed { return $default; }
	function __( string $t, string $d = '' ): string { return $t; }

	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
	}
	function is_wp_error( $x ): bool { return $x instanceof \WP_Error; }

	$GLOBALS['wpdb'] = new class {
		public function prepare( string $q, ...$a ): string { return $q; }
		public function query( string $q ) { return 1; }
	};
}

namespace EscapeManager\Repositories {
	final class Lock_Repository {
		public function count_active_for_session( string $s ): int { return 0; }
		public function release_all_for_session( string $s ): void {}
		public function has_active_overlap( int $r, string $a, string $b, string $s ): bool { return false; }
		public function active_for_room_in_range( int $r, string $a, string $b ): array { return array(); }
		public function create( array $row ): int { return 7; }
		public function delete( int $id ): bool { return true; }
	}
	final class Booking_Repository {
		public function has_overlap( int $r, string $a, string $b ): bool { return false; }
	}
	final class Room_Repository {
		public function find( int $id ): ?array {
			return array( 'id' => $id, 'is_active' => 1, 'duration_minutes' => 60 );
		}
	}
	/** Stesso test di sovrapposizione della query vera: inizio < fine e fine > inizio. */
	final class Room_Blocked_Period_Repository {
		public function copre( int $room_id, string $start, string $end ): bool {
			foreach ( $GLOBALS['blocchi'] as $b ) {
				if ( $b[0] === $room_id && $b[1] < $end && $b[2] > $start ) return true;
			}
			return false;
		}
	}
}

namespace {
	require __DIR__ . '/../includes/services/class-lock-service.php';

	$errori = 0;

	function prova( string $titolo, $esito, ?string $codice_atteso ): void {
		global $errori;
		$codice = is_wp_error( $esito ) ? $esito->get_error_code() : null;
		if ( $codice !== $codice_atteso ) {
			$errori++;
			printf( "✗ %s — atteso %s, ottenuto %s\n", $titolo, var_export( $codice_atteso, true ), var_export( $codice, true ) );
			return;
		}
		printf( "✓ %s\n", $titolo );
	}

	$servizio = new \EscapeManager\Services\Lock_Service();

	// Stanza 9 bloccata per tutta la giornata del 10/12 (ora di Roma = UTC+1).
	$GLOBALS['blocchi'] = array( array( 9, '2026-12-09 23:00:00', '2026-12-10 23:00:00' ) );

	prova( 'Dal sito: slot dentro il blocco → rifiutato',
		$servizio->acquire( 9, '2026-12-10 19:00:00', 'sessione-a' ), 'SLOT_BLOCKED' );

	prova( 'Dal sito: slot che sfora nel blocco → rifiutato',
		$servizio->acquire( 9, '2026-12-09 22:30:00', 'sessione-a' ), 'SLOT_BLOCKED' );

	prova( 'Dal sito: subito dopo il blocco → libero',
		$servizio->acquire( 9, '2026-12-10 23:00:00', 'sessione-a' ), null );

	prova( 'Dal sito: un’altra stanza lo stesso giorno → libero',
		$servizio->acquire( 3, '2026-12-10 19:00:00', 'sessione-a' ), null );

	prova( 'Dalla reception (ignora_blocchi): il blocco non la ferma, come prima',
		$servizio->acquire( 9, '2026-12-10 19:00:00', 'sessione-b', null, array( 'ignora_blocchi' => true ) ), null );

	$GLOBALS['blocchi'] = array();
	prova( 'Nessun blocco: tutto come prima',
		$servizio->acquire( 9, '2026-12-10 19:00:00', 'sessione-a' ), null );

	echo $errori ? "\n{$errori} prove fallite\n" : "\nTutte le prove passate\n";
	exit( $errori ? 1 : 0 );
}
