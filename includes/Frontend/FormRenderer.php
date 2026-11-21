<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Admin\SettingsManager;
use WMCyber\Newsletter\Database\DoiRepository;

/**
 * `[wm_dsgvo_newsletter]` and the confirmation page behind `?wm_action=confirm_newsletter&token=…`.
 *
 * Until 2026-09-15 the form carried a badge "100% DSGVO-Konform · Zero-Client-Leak" (a legal verdict
 * the plugin cannot give), B2B defaults ("NIS-2 & DORA Leitfäden") that contradicted the defaults the
 * admin screen documents, emoji, a script and a stylesheet on every page of the site, and a
 * confirmation page in inline styles that ignored the configured confirmation window.
 */
final class FormRenderer {
	public const STYLE_HANDLE  = 'wm-newsletter-form';
	public const SCRIPT_HANDLE = 'wm-newsletter-form-js';
	public const LEGAL_BASES   = [ 'art_6_1_a', 'art_6_1_b' ];

	private SettingsManager $settings;

	public function __construct( SettingsManager $settings ) {
		$this->settings = $settings;
	}

	public function boot(): void {
		add_shortcode( 'wm_dsgvo_newsletter', [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_frontend_assets' ] );
		add_action( 'template_redirect', [ $this, 'handle_confirmation_page' ] );
	}

	/**
	 * Signature over the shortcode's list and legal basis, so the REST route accepts exactly the
	 * values the site owner put into the shortcode.
	 */
	public static function form_signature( string $listId, string $legalBasis ): string {
		return hash_hmac( 'sha256', 'wm-newsletter-form|' . $listId . '|' . $legalBasis, wp_salt( 'nonce' ) );
	}

	/** The consent sentence as printed next to the checkbox (HTML with the privacy-policy link). */
	public static function consent_html( string $privacyUrl ): string {
		/* translators: %s: Privacy policy URL */
		$template = __( 'Ich willige in die Verarbeitung meiner E-Mail-Adresse zum Zweck des Newsletter-Versands ein. Der Widerruf ist gem. Art. 7 Abs. 3 DSGVO jederzeit möglich (<a href="%s" target="_blank" rel="noopener">Datenschutzerklärung</a>).', 'wm-dsgvo-newsletter-suite' );
		return wp_kses(
			sprintf( $template, esc_url( $privacyUrl ) ),
			[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
		);
	}

	/** What is stored as consent text: the printed sentence as plain text plus the linked policy URL. */
	public static function consent_record( string $privacyUrl ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( self::consent_html( $privacyUrl ) ) ) );
		return $text . ' [' . esc_url_raw( $privacyUrl ) . ']';
	}

	private static function asset_version( string $relative ): string {
		$mtime = @filemtime( WM_NEWSLETTER_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the plugin version.
		return $mtime ? (string) $mtime : WM_NEWSLETTER_VERSION;
	}

	/** Registered on every page, enqueued only where the form or the confirmation page is printed. */
	public function register_frontend_assets(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			WM_NEWSLETTER_PLUGIN_URL . 'assets/css/frontend-form.css',
			[],
			self::asset_version( 'assets/css/frontend-form.css' )
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			WM_NEWSLETTER_PLUGIN_URL . 'assets/js/frontend-form.js',
			[],
			self::asset_version( 'assets/js/frontend-form.js' ),
			true
		);

		wp_localize_script( self::SCRIPT_HANDLE, 'wmNewsletterData', [
			'restUrl' => esc_url_raw( rest_url( 'wm-newsletter/v1/' ) ),
			'strings' => [
				'submitting' => __( 'Wird übermittelt …', 'wm-dsgvo-newsletter-suite' ),
				'working'    => __( 'Sicherheitsprüfung läuft …', 'wm-dsgvo-newsletter-suite' ),
				'success'    => __( 'Bestätigungslink wurde gesendet. Bitte prüfen Sie Ihr Postfach.', 'wm-dsgvo-newsletter-suite' ),
				'error'      => __( 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.', 'wm-dsgvo-newsletter-suite' ),
				'consent'    => __( 'Bitte bestätigen Sie die Einwilligung.', 'wm-dsgvo-newsletter-suite' ),
				'noCrypto'   => __( 'Ihr Browser kann die Sicherheitsprüfung nicht ausführen (nur über HTTPS verfügbar).', 'wm-dsgvo-newsletter-suite' ),
			],
		] );

		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( (string) $post->post_content, 'wm_dsgvo_newsletter' ) ) {
				wp_enqueue_style( self::STYLE_HANDLE );
			}
		}
	}

	public function render_shortcode( $atts = [] ): string {
		$args = shortcode_atts( [
			'title'       => __( 'Newsletter abonnieren', 'wm-dsgvo-newsletter-suite' ),
			'description' => '',
			// Empty: the server uses the list from the settings. Until 2026-09-15 the default was the literal
			// 'default', which the form signed and posted, so the configured list was never used.
			'list_id'     => '',
			'legal_basis' => 'art_6_1_a',
			'button_text' => __( 'Anmelden', 'wm-dsgvo-newsletter-suite' ),
		], is_array( $atts ) ? $atts : [], 'wm_dsgvo_newsletter' );

		$listId     = sanitize_text_field( (string) $args['list_id'] );
		$legalBasis = in_array( $args['legal_basis'], self::LEGAL_BASES, true ) ? (string) $args['legal_basis'] : 'art_6_1_a';

		$config     = $this->settings->get_all();
		$privacyUrl = (string) ( $config['privacy_policy_url'] ?: home_url( '/datenschutz/' ) );
		$uniqueId   = 'wm-nl-' . wp_rand( 1000, 9999 );

		// Late enqueue covers widgets and templates where the shortcode is not in the post content.
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		ob_start();
		?>
		<section class="wm-newsletter-box" id="<?php echo esc_attr( $uniqueId ); ?>" aria-labelledby="<?php echo esc_attr( $uniqueId ); ?>-title">
			<h2 class="wm-nl-title" id="<?php echo esc_attr( $uniqueId ); ?>-title"><?php echo esc_html( $args['title'] ); ?></h2>
			<?php if ( '' !== trim( (string) $args['description'] ) ) : ?>
				<p class="wm-nl-desc"><?php echo esc_html( $args['description'] ); ?></p>
			<?php endif; ?>

			<form class="wm-nl-form" method="post" action="#" novalidate>
				<input type="hidden" name="list_id" value="<?php echo esc_attr( $listId ); ?>">
				<input type="hidden" name="legal_basis" value="<?php echo esc_attr( $legalBasis ); ?>">
				<input type="hidden" name="form_sig" value="<?php echo esc_attr( self::form_signature( $listId, $legalBasis ) ); ?>">

				<div class="wm-nl-hp" aria-hidden="true">
					<label><?php esc_html_e( 'Nicht ausfüllen', 'wm-dsgvo-newsletter-suite' ); ?> <input type="text" name="wm_hp_name" tabindex="-1" autocomplete="off"></label>
					<label><?php esc_html_e( 'Nicht ausfüllen', 'wm-dsgvo-newsletter-suite' ); ?> <input type="text" name="website_field" tabindex="-1" autocomplete="off"></label>
				</div>

				<div class="wm-nl-fields">
					<div class="wm-nl-field-wrap">
						<label for="<?php echo esc_attr( $uniqueId ); ?>-email" class="wm-nl-label"><?php esc_html_e( 'E-Mail-Adresse', 'wm-dsgvo-newsletter-suite' ); ?></label>
						<input type="email" id="<?php echo esc_attr( $uniqueId ); ?>-email" name="email" class="wm-nl-input" autocomplete="email" required>
					</div>
					<button type="submit" class="wm-nl-btn">
						<span class="wm-nl-btn-text"><?php echo esc_html( $args['button_text'] ); ?></span>
					</button>
				</div>

				<div class="wm-nl-consent-wrap">
					<label class="wm-nl-checkbox-label">
						<input type="checkbox" name="consent" value="1" class="wm-nl-checkbox" required>
						<span><?php echo self::consent_html( $privacyUrl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by wp_kses in consent_html(). ?></span>
					</label>
				</div>

				<p class="wm-nl-feedback" role="status" aria-live="polite" hidden></p>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public function handle_confirmation_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token itself is the credential of this link.
		if ( ! isset( $_GET['wm_action'] ) || 'confirm_newsletter' !== $_GET['wm_action'] || empty( $_GET['token'] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( (string) $_GET['token'] ) );
		// phpcs:enable

		$res = ( new DoiRepository() )->confirm_token_atomic( $token, $this->settings->ttl_hours() );

		wp_enqueue_style( self::STYLE_HANDLE );
		nocache_headers();
		status_header( $res['success'] ? 200 : (int) ( $res['code'] ?? 400 ) );

		get_header();
		?>
		<main class="wm-nl-confirm" id="wm-newsletter-bestaetigung">
			<section class="wm-newsletter-box wm-nl-confirm-box <?php echo esc_attr( $res['success'] ? 'is-success' : 'is-error' ); ?>" aria-labelledby="wm-nl-confirm-title">
				<h1 class="wm-nl-title" id="wm-nl-confirm-title">
					<?php echo esc_html( $res['success'] ? __( 'E-Mail-Adresse bestätigt', 'wm-dsgvo-newsletter-suite' ) : __( 'Bestätigung nicht möglich', 'wm-dsgvo-newsletter-suite' ) ); ?>
				</h1>
				<p class="wm-nl-confirm-message"><?php echo esc_html( $res['message'] ); ?></p>
				<a class="wm-nl-btn wm-nl-btn-link" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Zur Startseite', 'wm-dsgvo-newsletter-suite' ); ?></a>
			</section>
		</main>
		<?php
		get_footer();
		exit;
	}
}
