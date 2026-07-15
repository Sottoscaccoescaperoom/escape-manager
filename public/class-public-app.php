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

		// §FIX 2026-07-13 (#4) — La pagina di prenotazione NON deve mai essere
		// full-page cache. Il nonce `wp_rest` è iniettato inline (vedi
		// render_shortcode): se LiteSpeed serve una copia cache, il nonce si
		// "congela" e scade. Per un visitatore LOGGATO (staff che testa la
		// pagina) WordPress core rifiuta allora TUTTE le REST con 403
		// (rest_cookie_invalid_nonce) e il widget mostra "Errore di rete".
		// Disabilitiamo la cache quando lo shortcode è presente: la pagina è
		// dinamica (disponibilità/lock in tempo reale) e non va comunque cachata.
		$this->disable_page_cache();
		$this->ensure_assets();
	}

	/**
	 * §FIX 2026-07-15 — Registra + accoda gli asset del widget. Chiamato sia da
	 * enqueue_assets (quando lo shortcode è nel post_content) SIA da
	 * render_shortcode: con Elementor lo shortcode vive nei dati Elementor, NON
	 * nel post_content, quindi `has_shortcode($post->post_content)` è false e lo
	 * script non veniva caricato → il widget non compariva. Accodando qui, allo
	 * scattare dello shortcode, gli asset vengono stampati (nel footer) ovunque
	 * lo shortcode sia usato (pagina normale, Elementor, blocchi, ecc.).
	 */
	private function ensure_assets(): void {
		if ( ! wp_script_is( 'em-booking-app', 'registered' ) ) {
			wp_register_script(
				'em-booking-app',
				EM_PLUGIN_URL . 'public/booking-app/booking-app.js',
				array(),
				EM_VERSION,
				true
			);
		}
		if ( ! wp_style_is( 'em-booking-app', 'registered' ) ) {
			wp_register_style(
				'em-booking-app',
				EM_PLUGIN_URL . 'public/booking-app/booking-app.css',
				array(),
				EM_VERSION
			);
		}
		wp_enqueue_script( 'em-booking-app' );
		wp_enqueue_style( 'em-booking-app' );
	}

	/**
	 * Impedisce la full-page cache (LiteSpeed / WP Super Cache / W3TC / generico)
	 * per la pagina che ospita il widget di prenotazione. Vedi nota in
	 * enqueue_assets: evita il nonce `wp_rest` scaduto → REST 403 → "Errore di rete".
	 */
	private function disable_page_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// LiteSpeed Cache: controllo esplicito no-cache (rispettato anche se
		// impostato durante il rendering della pagina).
		do_action( 'litespeed_control_set_nocache', 'escape-manager: pagina di prenotazione dinamica' );
		// WP Super Cache.
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}
	}

	public function render_shortcode( array $atts = array() ): string {
		// Ridondanza: se il tema non chiama wp_enqueue_scripts prima del
		// rendering dello shortcode, garantiamo comunque il no-cache qui.
		$this->disable_page_cache();
		// §FIX 2026-07-15 — Accoda gli asset ANCHE qui: con Elementor lo
		// shortcode non è nel post_content, quindi enqueue_assets non scatta.
		$this->ensure_assets();

		$config = wp_json_encode( array(
			'apiBase'      => esc_url_raw( rest_url( EM_REST_NAMESPACE ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'timezone'     => em_setting( 'em_timezone', 'Europe/Rome' ),
			'currency'     => em_setting( 'em_currency', 'EUR' ),
			'lockTtlMin'   => (int) em_setting( 'em_lock_ttl_minutes', 10 ),
			'requiredFields' => em_setting( 'em_required_fields_public', array() ),
			'locationId'   => isset( $atts['location_id'] ) ? (int) $atts['location_id'] : null,
			// §Singola stanza — [escape_booking room="slug"] mostra/preseleziona
			// solo quella stanza (usato nelle pagine dedicate di ogni stanza).
			'roomSlug'     => isset( $atts['room'] ) ? sanitize_title( $atts['room'] ) : null,
			// §Ordinamento stanze — promuove le stanze "deboli" (poche prenotazioni
			// nel giorno) in cima con un boost pesato, senza seppellire le forti,
			// ed evidenzia con un badge quelle con tanta disponibilità.
			'promoteWeakRooms' => (bool) em_setting( 'em_promote_weak_rooms', false ),
			// §Promo periodo — sconto % sui turni giocati in un intervallo, su stanze
			// scelte. Il widget mostra un badge "-X%"; il totale è ricalcolato lato
			// server (il badge è solo indicativo).
			'promo' => array(
				'enabled' => (bool) em_setting( 'em_promo_enabled', false ),
				'percent' => (int) em_setting( 'em_promo_percent', 0 ),
				'from'    => (string) em_setting( 'em_promo_from', '' ),
				'to'      => (string) em_setting( 'em_promo_to', '' ),
				'rooms'   => array_map( 'intval', (array) em_setting( 'em_promo_rooms', array() ) ),
			),
			// §Minimo prenotazione — prezzi per validare il minimo lato client (in centesimi).
			'pricing' => array(
				'adult2'       => (int) em_setting( 'em_price_adult_2', 3000 ),
				'adult3'       => (int) em_setting( 'em_price_adult_3', 2500 ),
				'adult4plus'   => (int) em_setting( 'em_price_adult_4plus', 2200 ),
				'childReduced' => (int) em_setting( 'em_price_child_reduced', 1500 ),
				'minCents'     => (int) em_setting( 'em_min_booking_cents', 6000 ),
			),
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
