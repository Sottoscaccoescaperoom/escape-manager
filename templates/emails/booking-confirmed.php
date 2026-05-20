<?php
/**
 * @var array $booking
 * @var array $customer
 * @var array $room
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$start_local = '';
try {
	$dt = new DateTimeImmutable( $booking['start_datetime'], new DateTimeZone( 'UTC' ) );
	$dt = $dt->setTimezone( new DateTimeZone( em_setting( 'em_timezone', 'Europe/Rome' ) ) );
	$start_local = $dt->format( 'd/m/Y H:i' );
} catch ( \Exception $e ) {}

$site_name = get_bloginfo( 'name' );
?>
<!doctype html>
<html lang="it">
<body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; line-height:1.5; color:#1f2937; max-width:600px; margin:0 auto; padding:1rem;">
	<h2 style="color:#2563eb;">Prenotazione confermata</h2>
	<p>Ciao <?php echo esc_html( $customer['first_name'] ); ?>,</p>
	<p>la tua prenotazione presso <strong><?php echo esc_html( $site_name ); ?></strong> è confermata.</p>

	<table style="width:100%; border-collapse:collapse; margin:1rem 0;">
		<tr><td style="padding:0.5rem; background:#f8fafc;"><strong>Codice</strong></td><td style="padding:0.5rem;"><code><?php echo esc_html( $booking['booking_code'] ); ?></code></td></tr>
		<tr><td style="padding:0.5rem; background:#f8fafc;"><strong>Stanza</strong></td><td style="padding:0.5rem;"><?php echo esc_html( $room['name'] ?? '' ); ?></td></tr>
		<tr><td style="padding:0.5rem; background:#f8fafc;"><strong>Data e ora</strong></td><td style="padding:0.5rem;"><?php echo esc_html( $start_local ); ?></td></tr>
		<tr><td style="padding:0.5rem; background:#f8fafc;"><strong>Giocatori</strong></td><td style="padding:0.5rem;"><?php echo (int) $booking['total_players']; ?></td></tr>
		<tr><td style="padding:0.5rem; background:#f8fafc;"><strong>Totale</strong></td><td style="padding:0.5rem;"><?php echo esc_html( em_format_money( (int) $booking['total_amount'] ) ); ?></td></tr>
	</table>

	<?php if ( ! empty( $room['important_info'] ) ) : ?>
		<h3>Informazioni importanti</h3>
		<p><?php echo nl2br( esc_html( $room['important_info'] ) ); ?></p>
	<?php endif; ?>

	<p>Ti aspettiamo!</p>
	<p style="color:#64748b; font-size:0.85rem;">Per modifiche o cancellazioni, rispondi a questa email o contattaci.</p>
</body>
</html>
