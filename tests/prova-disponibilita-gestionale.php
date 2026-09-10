<?php
/**
 * tests/prova-disponibilita-gestionale.php — §Un calendario solo 2026-09-10
 *
 * Collaudo isolato di `slot_occupato_nel_gestionale`, con WordPress finto.
 *
 * Si lancia con il PHP di Local, senza WordPress e senza database:
 *
 *   php.exe tests/prova-disponibilita-gestionale.php
 *
 * (su questa macchina: ~/AppData/Roaming/Local/lightning-services/
 *  php-8.2.29+0/bin/win64/php.exe)
 *
 * ⚠️ La cosa da dimostrare non è che sappia leggere «occupato»: è che il
 * «NON LO SAPPIAMO» non diventi mai «occupato». Quello spegnerebbe le vendite
 * online a ogni intoppo del gestionale, ed è il modo in cui questo controllo
 * farebbe più danni di quanti ne evita.
 */

// ── WordPress finto: solo le funzioni che il metodo usa davvero ─────────────
$GLOBALS['finto'] = array( 'url' => '', 'secret' => '', 'risposta' => null, 'ultima' => null );

function em_setting( $k, $d = '' ) {
	if ( 'em_sottoscacco_webhook_url' === $k )    return $GLOBALS['finto']['url'];
	if ( 'em_sottoscacco_webhook_secret' === $k ) return $GLOBALS['finto']['secret'];
	return $d;
}
function wp_parse_url( $u ) { return parse_url( $u ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_remote_post( $url, $args ) {
	$GLOBALS['finto']['ultima'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['finto']['risposta'];
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
class WP_Error { public function __construct( public $code = '', public $msg = '' ) {} }

// ⚠️ La guardia standard di WordPress in cima al file: senza `ABSPATH`
// definita, `require` esce in silenzio con codice 0 — nessun errore, nessun
// output, e il collaudo sembra non aver fatto niente. È successo davvero.
define( 'ABSPATH', 'C:/finto-wordpress/' );

// ── La classe vera ──────────────────────────────────────────────────────────
require __DIR__ . '/../includes/services/class-sottoscacco-bridge-service.php';

$rc    = new ReflectionClass( 'EscapeManager\\Services\\Sottoscacco_Bridge_Service' );
$ponte = $rc->newInstanceWithoutConstructor();
$stanza = array( 'name' => 'Occhio di Ra', 'slug' => 'occhio-di-ra' );

$falliti = 0;
function prova( string $nome, $atteso, $ottenuto ) {
	global $falliti;
	$ok = ( $atteso === $ottenuto );
	if ( ! $ok ) $falliti++;
	$s = function( $v ) { return null === $v ? 'null' : ( true === $v ? 'true' : ( false === $v ? 'false' : var_export( $v, true ) ) ); };
	printf( "%s  %-58s atteso %-5s ottenuto %s\n", $ok ? 'OK ' : 'NO ', $nome, $s( $atteso ), $s( $ottenuto ) );
}

// ── 1. Il ponte non è configurato ───────────────────────────────────────────
$GLOBALS['finto']['url'] = ''; $GLOBALS['finto']['secret'] = '';
prova( 'ponte non configurato -> non lo sappiamo', null,
	$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' ) );

$GLOBALS['finto']['url']    = 'https://app.sottoscacco.it/api/webhooks/escape-navigator/abc123';
$GLOBALS['finto']['secret'] = 'segreto-di-prova';

// ── 2. Il gestionale non risponde ───────────────────────────────────────────
$GLOBALS['finto']['risposta'] = new WP_Error( 'timeout' );
prova( 'gestionale irraggiungibile -> non lo sappiamo (NON blocca)', null,
	$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' ) );

// ── 3. Risposta di errore ───────────────────────────────────────────────────
$GLOBALS['finto']['risposta'] = array( 'code' => 500, 'body' => 'boom' );
prova( 'gestionale in errore 500 -> non lo sappiamo', null,
	$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' ) );

$GLOBALS['finto']['risposta'] = array( 'code' => 401, 'body' => '{"error":"non autorizzato"}' );
prova( 'segreto rifiutato (401) -> non lo sappiamo', null,
	$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' ) );

// ── 4. Le risposte vere ─────────────────────────────────────────────────────
$GLOBALS['finto']['risposta'] = array( 'code' => 200, 'body' => '{"libero":false}' );
prova( 'gestionale dice OCCUPATO -> si blocca', true,
	$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' ) );

$GLOBALS['finto']['risposta'] = array( 'code' => 200, 'body' => '{"libero":true}' );
prova( 'gestionale dice LIBERO -> si prosegue', false,
	$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' ) );

// 🚨 LA PROVA CHE CONTA PIÙ DI TUTTE.
$GLOBALS['finto']['risposta'] = array( 'code' => 200, 'body' => '{"libero":null,"errore":"non lo sappiamo"}' );
prova( '🚨 gestionale dice NON LO SAPPIAMO -> NON diventa occupato', null,
	$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' ) );

$GLOBALS['finto']['risposta'] = array( 'code' => 200, 'body' => 'non-json' );
prova( 'risposta illeggibile -> non lo sappiamo', null,
	$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' ) );

$GLOBALS['finto']['risposta'] = array( 'code' => 200, 'body' => '{"altro":1}' );
prova( 'risposta senza il campo -> non lo sappiamo', null,
	$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' ) );

// ── 5. Che cosa ha chiesto davvero ──────────────────────────────────────────
$GLOBALS['finto']['risposta'] = array( 'code' => 200, 'body' => '{"libero":true}' );
$ponte->slot_occupato_nel_gestionale( $stanza, '2026-09-10 17:00:00' );
$u = $GLOBALS['finto']['ultima'];
prova( 'indirizzo ricavato dal webhook, senza impostazioni nuove',
	'https://app.sottoscacco.it/api/disponibilita-slot', $u['url'] );
prova( 'manda il segreto del ponte', 'segreto-di-prova',
	$u['args']['headers']['x-em-bridge-secret'] );
prova( 'attesa corta: il cliente e\' fermo sul bottone', 3, $u['args']['timeout'] );
$corpo = json_decode( $u['args']['body'], true );
prova( 'manda la data in UTC con lo stesso formato del ponte',
	'2026-09-10T17:00:00.000Z', $corpo['scheduledAt'] );
prova( 'manda il nome della stanza', 'Occhio di Ra', $corpo['roomName'] );

echo "\n" . ( $falliti ? "❌ $falliti prove fallite\n" : "✅ tutte le prove passate\n" );
exit( $falliti ? 1 : 0 );
