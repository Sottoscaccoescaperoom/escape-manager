<?php
/**
 * tests/prova-attribuzione.php — §Tracciamento conversioni 2026-11-26
 *
 * Il cookie `_ss_attr` arriva dal browser del cliente: si tiene solo la forma
 * prevista. E «Come ci avete conosciuto?» accetta solo le risposte dell'elenco.
 *
 * Si lancia: php tests/prova-attribuzione.php
 */

define( 'ABSPATH', 'C:/finto-wordpress/' );

// Finzione minima di WordPress: toglie tag e spazi come il vero.
function sanitize_text_field( string $s ): string {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $s ) ) );
}

require __DIR__ . '/../includes/helpers/functions.php';

$falliti = 0;
function verifica( string $nome, bool $ok ): void {
	global $falliti;
	echo ( $ok ? '  ok   ' : '  NO   ' ) . $nome . "\n";
	if ( ! $ok ) {
		$falliti++;
	}
}

// 1. La forma del beacon, così com'è nel cookie (JSON).
$cookie = json_encode( array(
	'v' => 1,
	'f' => array( 's' => 'google', 'm' => 'cpc', 'c' => 'natale26_a', 'gc' => 1, 'gb' => 0, 'wb' => 0, 'g' => 'ABC', 'l' => '/stanze/?email=a@b.it', 't' => '2026-12-01T10:00:00Z' ),
	'l' => array( 's' => 'instagram.com', 'm' => 'referral', 'c' => '', 'gc' => 0, 'l' => '/', 't' => '2026-12-03T10:00:00Z' ),
) );
$a = em_sanitize_attribution( $cookie );
verifica( 'prima: google/cpc/natale26_a con gclid', 'google' === $a['f']['s'] && 'natale26_a' === $a['f']['c'] && 1 === $a['f']['gc'] && 'ABC' === $a['f']['g'] );
verifica( 'pagina d\'arrivo senza parametri', '/stanze/' === $a['f']['l'] );
verifica( 'ultima: instagram, campagna vuota tolta', 'instagram.com' === $a['l']['s'] && ! isset( $a['l']['c'] ) );

// 2. Anche URL-encoded (come lo legge document.cookie senza decodifica).
$b = em_sanitize_attribution( rawurlencode( $cookie ) );
verifica( 'URL-encoded = stesso risultato', $a === $b );

// 3. Solo l'ultima: vale anche come prima.
$c = em_sanitize_attribution( array( 'l' => array( 's' => 'google', 'm' => 'cpc' ) ) );
verifica( 'manca la prima → copia dell\'ultima', $c['f'] === $c['l'] );

// 4. Spazzatura → null, mai un errore.
verifica( 'stringa vuota', null === em_sanitize_attribution( '' ) );
verifica( 'JSON rotto', null === em_sanitize_attribution( '{rotto' ) );
verifica( 'troppo lungo', null === em_sanitize_attribution( str_repeat( 'x', 5000 ) ) );
verifica( 'niente di utile', null === em_sanitize_attribution( array( 'f' => array( 'l' => '/' ) ) ) );
verifica( 'numero', null === em_sanitize_attribution( 42 ) );
verifica( 'chiavi sconosciute buttate', ! isset( em_sanitize_attribution( array( 'f' => array( 's' => 'x', 'email' => 'a@b.it' ) ) )['f']['email'] ) );
verifica( 'tag HTML tolti', 'x' === em_sanitize_attribution( array( 'f' => array( 's' => '<b>x</b>' ) ) )['f']['s'] );

// 5. «Come ci avete conosciuto?»
verifica( 'risposta prevista', 'google_ads' === em_sanitize_conosciuto( 'google_ads' ) );
verifica( 'maiuscole e spazi', 'passaparola' === em_sanitize_conosciuto( ' Passaparola ' ) );
verifica( 'fuori elenco → null', null === em_sanitize_conosciuto( 'boh' ) );
verifica( 'non stringa → null', null === em_sanitize_conosciuto( array( 'x' ) ) );

echo $falliti ? "\n{$falliti} prove fallite\n" : "\nTutto ok\n";
exit( $falliti ? 1 : 0 );
