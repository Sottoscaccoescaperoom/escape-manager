<?php
/**
 * Pagina admin: Bridge Sottoscacco (configurazione + diagnostica coda webhook).
 *
 * @package EscapeManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings_keys = array(
	'em_sottoscacco_bridge_enabled',
	'em_sottoscacco_webhook_url',
	'em_sottoscacco_webhook_secret',
	'em_sottoscacco_max_retries',
	'em_sottoscacco_external_id_prefix',
);

// Handle POST (save settings)
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer( 'em_bridge_save' ) ) {
	em_update_setting( 'em_sottoscacco_bridge_enabled', isset( $_POST['em_sottoscacco_bridge_enabled'] ) ? 1 : 0 );
	em_update_setting( 'em_sottoscacco_webhook_url', esc_url_raw( wp_unslash( $_POST['em_sottoscacco_webhook_url'] ?? '' ) ) );

	$secret = (string) wp_unslash( $_POST['em_sottoscacco_webhook_secret'] ?? '' );
	if ( $secret !== '' && strpos( $secret, '•••' ) === false ) {
		em_update_setting( 'em_sottoscacco_webhook_secret', $secret );
	}
	em_update_setting( 'em_sottoscacco_max_retries', max( 1, (int) ( $_POST['em_sottoscacco_max_retries'] ?? 5 ) ) );
	em_update_setting( 'em_sottoscacco_external_id_prefix', sanitize_text_field( wp_unslash( $_POST['em_sottoscacco_external_id_prefix'] ?? 'em-' ) ) );

	echo '<div class="notice notice-success"><p>' . esc_html__( 'Impostazioni salvate.', 'escape-manager' ) . '</p></div>';
}

if ( isset( $_GET['action'] ) && $_GET['action'] === 'test' && check_admin_referer( 'em_bridge_test' ) ) {
	$bridge = new \EscapeManager\Services\Sottoscacco_Bridge_Service();
	$res    = $bridge->test_connection();
	if ( is_wp_error( $res ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $res->get_error_message() ) . '</p></div>';
	} else {
		echo '<div class="notice notice-success"><p>' . sprintf(
			/* translators: 1: status code */
			esc_html__( 'Test riuscito. Sottoscacco ha risposto HTTP %d.', 'escape-manager' ),
			(int) $res['status_code']
		) . '</p></div>';
	}
}

if ( isset( $_GET['action'] ) && $_GET['action'] === 'replay' && check_admin_referer( 'em_bridge_replay' ) ) {
	$queue = new \EscapeManager\Repositories\Webhook_Queue_Repository();
	$count = $queue->replay_failed();
	echo '<div class="notice notice-success"><p>' . sprintf(
		esc_html__( 'Riaccodati %d webhook falliti.', 'escape-manager' ),
		$count
	) . '</p></div>';
}

if ( isset( $_GET['action'] ) && $_GET['action'] === 'dispatch' && check_admin_referer( 'em_bridge_dispatch' ) ) {
	$disp = new \EscapeManager\Services\Webhook_Dispatcher_Service();
	$res  = $disp->dispatch_due( 50 );
	echo '<div class="notice notice-success"><p>' . sprintf(
		esc_html__( 'Dispatch completato: %d inviati, %d rinviati, %d falliti.', 'escape-manager' ),
		(int) $res['sent'],
		(int) $res['rescheduled'],
		(int) $res['failed']
	) . '</p></div>';
}

$bridge_enabled = (bool) em_setting( 'em_sottoscacco_bridge_enabled', 0 );
$webhook_url    = (string) em_setting( 'em_sottoscacco_webhook_url', '' );
$has_secret     = (bool) em_setting( 'em_sottoscacco_webhook_secret', '' );
$max_retries    = (int) em_setting( 'em_sottoscacco_max_retries', 5 );
$id_prefix      = (string) em_setting( 'em_sottoscacco_external_id_prefix', 'em-' );

$queue   = new \EscapeManager\Repositories\Webhook_Queue_Repository();
$summary = $queue->summary();

global $wpdb;
$table  = em_table( 'webhook_queue' );
$recent = $wpdb->get_results(
	"SELECT id, event_type, status, attempts, last_error, next_attempt_at, sent_at, created_at, booking_id
	FROM {$table}
	ORDER BY id DESC
	LIMIT 20",
	ARRAY_A
) ?: array();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Bridge Sottoscacco', 'escape-manager' ); ?></h1>

	<p>
		<?php esc_html_e( 'Questo bridge invia le prenotazioni di Escape Manager a Sottoscacco emulando perfettamente i webhook di Escape Navigator. Sottoscacco non distingue: continua a vedere booking come se arrivassero da EN.', 'escape-manager' ); ?>
	</p>

	<h2><?php esc_html_e( 'Configurazione', 'escape-manager' ); ?></h2>

	<form method="post">
		<?php wp_nonce_field( 'em_bridge_save' ); ?>
		<table class="form-table">
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Stato bridge', 'escape-manager' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="em_sottoscacco_bridge_enabled" value="1" <?php checked( $bridge_enabled ); ?> />
						<?php esc_html_e( 'Attivo (i nuovi booking confermati vengono inviati a Sottoscacco)', 'escape-manager' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="em_sottoscacco_webhook_url"><?php esc_html_e( 'URL webhook Sottoscacco', 'escape-manager' ); ?></label></th>
				<td>
					<input type="url" id="em_sottoscacco_webhook_url" name="em_sottoscacco_webhook_url"
						value="<?php echo esc_attr( $webhook_url ); ?>"
						class="regular-text"
						placeholder="https://sottoscacco.app/api/webhooks/escape-navigator" />
					<p class="description"><?php esc_html_e( 'Endpoint Next.js esistente. Il bridge POSTa qui.', 'escape-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="em_sottoscacco_webhook_secret"><?php esc_html_e( 'Secret webhook', 'escape-manager' ); ?></label></th>
				<td>
					<input type="password" id="em_sottoscacco_webhook_secret" name="em_sottoscacco_webhook_secret"
						value="<?php echo $has_secret ? '••• configurato •••' : ''; ?>"
						class="regular-text"
						autocomplete="new-password" />
					<p class="description"><?php esc_html_e( 'Stesso valore di ESCAPE_NAVIGATOR_WEBHOOK_SECRET su Sottoscacco. Sovrascrivi per cambiare; lascia il placeholder per mantenere quello attuale.', 'escape-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="em_sottoscacco_max_retries"><?php esc_html_e( 'Max tentativi', 'escape-manager' ); ?></label></th>
				<td>
					<input type="number" id="em_sottoscacco_max_retries" name="em_sottoscacco_max_retries"
						value="<?php echo esc_attr( $max_retries ); ?>" min="1" max="10" class="small-text" />
					<p class="description"><?php esc_html_e( 'Numero di retry con backoff esponenziale prima di marcare il webhook come failed.', 'escape-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="em_sottoscacco_external_id_prefix"><?php esc_html_e( 'Prefisso external_id', 'escape-manager' ); ?></label></th>
				<td>
					<input type="text" id="em_sottoscacco_external_id_prefix" name="em_sottoscacco_external_id_prefix"
						value="<?php echo esc_attr( $id_prefix ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Per evitare collisioni con i vecchi booking EN (es: em-, em-staging-).', 'escape-manager' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 'escape-manager' ); ?></button>

			<?php
			$test_url = wp_nonce_url(
				admin_url( 'admin.php?page=escape-manager-bridge&action=test' ),
				'em_bridge_test'
			);
			?>
			<a href="<?php echo esc_url( $test_url ); ?>" class="button"><?php esc_html_e( 'Test connessione', 'escape-manager' ); ?></a>
		</p>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Stato coda webhook', 'escape-manager' ); ?></h2>
	<p>
		<span class="em-pill em-pill-pending"><?php echo (int) $summary['pending']; ?> <?php esc_html_e( 'pending', 'escape-manager' ); ?></span>
		<span class="em-pill em-pill-sent"><?php echo (int) $summary['sent']; ?> <?php esc_html_e( 'sent', 'escape-manager' ); ?></span>
		<span class="em-pill em-pill-failed"><?php echo (int) $summary['failed']; ?> <?php esc_html_e( 'failed', 'escape-manager' ); ?></span>
	</p>

	<style>
		.em-pill { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 999px; font-weight: 600; margin-right: 0.5rem; }
		.em-pill-pending { background: #fef3c7; color: #92400e; }
		.em-pill-sent { background: #d1fae5; color: #065f46; }
		.em-pill-failed { background: #fee2e2; color: #991b1b; }
	</style>

	<p>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=escape-manager-bridge&action=dispatch' ), 'em_bridge_dispatch' ) ); ?>" class="button">
			<?php esc_html_e( 'Esegui dispatch ora', 'escape-manager' ); ?>
		</a>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=escape-manager-bridge&action=replay' ), 'em_bridge_replay' ) ); ?>" class="button">
			<?php esc_html_e( 'Replay falliti', 'escape-manager' ); ?>
		</a>
	</p>

	<h3><?php esc_html_e( 'Ultimi 20 webhook', 'escape-manager' ); ?></h3>
	<table class="widefat striped">
		<thead>
			<tr>
				<th>ID</th>
				<th>Evento</th>
				<th>Booking</th>
				<th>Stato</th>
				<th>Tentativi</th>
				<th>Prossimo</th>
				<th>Inviato</th>
				<th>Ultimo errore</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $recent ) ) : ?>
				<tr><td colspan="8"><em><?php esc_html_e( 'Nessun webhook in coda.', 'escape-manager' ); ?></em></td></tr>
			<?php endif; ?>
			<?php foreach ( $recent as $r ) : ?>
				<tr>
					<td><?php echo (int) $r['id']; ?></td>
					<td><code><?php echo esc_html( $r['event_type'] ); ?></code></td>
					<td><?php echo $r['booking_id'] ? '#' . (int) $r['booking_id'] : '—'; ?></td>
					<td><span class="em-pill em-pill-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span></td>
					<td><?php echo (int) $r['attempts']; ?></td>
					<td><?php echo esc_html( $r['next_attempt_at'] ?? '—' ); ?></td>
					<td><?php echo esc_html( $r['sent_at'] ?? '—' ); ?></td>
					<td><small><?php echo esc_html( mb_substr( $r['last_error'] ?? '', 0, 120 ) ); ?></small></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
