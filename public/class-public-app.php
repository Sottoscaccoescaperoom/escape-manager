<?php
namespace EscapeManager\Public_App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Public_App {

	public function register(): void {
		add_shortcode( 'escape_booking', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Il bundle usa import ESM (Preact/htm da CDN): va servito come modulo.
		add_filter( 'script_loader_tag', array( $this, 'module_script_tag' ), 10, 3 );
	}

	/**
	 * Forza type="module" sul bundle booking (necessario per gli import ESM).
	 */
	public function module_script_tag( string $tag, string $handle, string $src ): string {
		if ( 'em-booking-app' !== $handle ) {
			return $tag;
		}
		return sprintf(
			'<script type="module" src="%s" id="%s-js"></script>' . "\n",
			esc_url( $src ),
			esc_attr( $handle )
		);
	}

	public function enqueue_assets(): void {
		// Carichiamo gli asset solo quando lo shortcode è effettivamente presente.
		global $post;
		if ( ! $post ) {
			return;
		}
		if ( ! has_shortcode( $post->post_content, 'escape_booking' ) ) {
			return;
		}

		wp_register_script(
			'em-booking-app',
			EM_PLUGIN_URL . 'public/booking-app/booking-app.js',
			array(),
			EM_VERSION,
			true
		);

		wp_register_style(
			'em-booking-app',
			EM_PLUGIN_URL . 'public/booking-app/booking-app.css',
			array(),
			EM_VERSION
		);

		wp_enqueue_script( 'em-booking-app' );
		wp_enqueue_style( 'em-booking-app' );
	}

	public function render_shortcode( array $atts = array() ): string {
		$config = wp_json_encode( array(
			'apiBase'      => esc_url_raw( rest_url( EM_REST_NAMESPACE ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'timezone'     => em_setting( 'em_timezone', 'Europe/Rome' ),
			'currency'     => em_setting( 'em_currency', 'EUR' ),
			'lockTtlMin'   => (int) em_setting( 'em_lock_ttl_minutes', 10 ),
			'requiredFields' => em_setting( 'em_required_fields_public', array() ),
			'locationId'   => isset( $atts['location_id'] ) ? (int) $atts['location_id'] : null,
		) );

		ob_start();
		?>
		<div id="em-booking-root"></div>
		<script>
			window.EM_BOOKING_CONFIG = <?php echo $config; ?>;
		</script>
		<?php
		return ob_get_clean();
	}
}
