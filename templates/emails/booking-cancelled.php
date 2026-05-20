<?php
/**
 * @var array $booking
 * @var array $customer
 * @var array $room
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$site_name = get_bloginfo( 'name' );
?>
<!doctype html>
<html lang="it">
<body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; line-height:1.5; color:#1f2937; max-width:600px; margin:0 auto; padding:1rem;">
	<h2 style="color:#dc2626;">Prenotazione annullata</h2>
	<p>Ciao <?php echo esc_html( $customer['first_name'] ); ?>,</p>
	<p>la tua prenotazione <code><?php echo esc_html( $booking['booking_code'] ); ?></code> per la stanza <strong><?php echo esc_html( $room['name'] ?? '' ); ?></strong> è stata annullata.</p>

	<?php if ( ! empty( $booking['cancellation_reason'] ) ) : ?>
		<p><strong>Motivo:</strong> <?php echo esc_html( $booking['cancellation_reason'] ); ?></p>
	<?php endif; ?>

	<p>Per qualsiasi domanda o per riprenotare, contattaci.</p>
	<p style="color:#64748b; font-size:0.85rem;">— <?php echo esc_html( $site_name ); ?></p>
</body>
</html>
