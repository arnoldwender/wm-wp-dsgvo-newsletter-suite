<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Database\DoiRepository;
use WMCyber\Newsletter\Queue\SyncQueue;

/**
 * Admin screen (menu "DSGVO Newsletter", page `wm-newsletter-suite`) and dashboard widget.
 *
 * Until 2026-09-15 the overview printed a table of statutes and judgments with the verdicts
 * "RECHTSKONFORM" and "BARRIEREFREI", the widget counted subscribers with status `confirmed` (the
 * column only holds subscribed / unsubscribed / cleaned, so it always showed 0) next to fixed tiles
 * "DOI Protokoll" and "100% EU Hosting", provider options carried flags and data-centre locations
 * nobody measured, the CSV export wrote cells unescaped although the changelog claimed formula-injection
 * protection, and the settings saved any provider name.
 */
final class AdminDashboard {
	private const PAGE = 'wm-newsletter-suite';

	private DoiRepository $repo;
	private SettingsManager $settings;
	private SyncQueue $queue;

	public function __construct( DoiRepository $repo, SettingsManager $settings, SyncQueue $queue ) {
		$this->repo     = $repo;
		$this->settings = $settings;
		$this->queue    = $queue;
	}

	public function boot(): void {
		add_action( 'admin_init', [ $this->settings, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_wm_newsletter_save_settings', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_wm_newsletter_run_sync', [ $this, 'handle_run_sync' ] );
		add_action( 'admin_post_wm_newsletter_export_csv', [ $this, 'handle_export_csv' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	/** Provider names as shown in the settings; no locations or flags. */
	public static function provider_labels(): array {
		return [
			'local'       => __( 'Nur lokal (keine Übergabe an einen Anbieter)', 'wm-dsgvo-newsletter-suite' ),
			'brevo'       => 'Brevo',
			'rapidmail'   => 'Rapidmail',
			'cleverreach' => 'CleverReach',
			'kit'         => 'Kit (ConvertKit)',
			'mailchimp'   => 'Mailchimp',
		];
	}

	/**
	 * CSV cell that a spreadsheet will not evaluate: values starting with = + - @ or a control
	 * character get a leading apostrophe (CWE-1236).
	 */
	public static function csv_cell( $value ): string {
		$value = (string) $value;
		return ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) ? "'" . $value : $value;
	}

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wm_dsgvo_newsletter_widget',
			__( 'WM DSGVO Newsletter Suite', 'wm-dsgvo-newsletter-suite' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget(): void {
		$stats    = $this->repo->get_stats();
		$missing  = $this->settings->missing_provider_settings();
		$provider = (string) $this->settings->get( 'active_provider' );
		$warn     = [] !== $missing || $stats['sync_failed'] > 0;
		$labels   = self::provider_labels();
		?>
		<div class="wm-dash-widget-box">
			<div class="wm-dash-banner <?php echo $warn ? 'is-warning' : 'is-valid'; ?>">
				<span class="dashicons <?php echo $warn ? 'dashicons-warning' : 'dashicons-email-alt'; ?>" aria-hidden="true"></span>
				<div>
					<div class="wm-dash-banner-title">
						<?php
						if ( [] !== $missing ) {
							esc_html_e( 'Anbieter nicht vollständig eingerichtet', 'wm-dsgvo-newsletter-suite' );
						} elseif ( $stats['sync_failed'] > 0 ) {
							esc_html_e( 'Übergaben an den Anbieter fehlgeschlagen', 'wm-dsgvo-newsletter-suite' );
						} else {
							esc_html_e( 'Double-Opt-In aktiv', 'wm-dsgvo-newsletter-suite' );
						}
						?>
					</div>
					<div class="wm-dash-banner-sub"><?php echo esc_html( $labels[ $provider ] ?? $provider ); ?></div>
				</div>
			</div>

			<div class="wm-dash-kpi-grid">
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( number_format_i18n( (int) $stats['active_subscribers'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Abonniert', 'wm-dsgvo-newsletter-suite' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( number_format_i18n( (int) $stats['pending_doi'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Unbestätigt', 'wm-dsgvo-newsletter-suite' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( number_format_i18n( (int) $stats['sync_failed'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Übergabe fehlgeschlagen', 'wm-dsgvo-newsletter-suite' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="wm-dash-btn">
					<?php esc_html_e( 'Newsletter Suite öffnen', 'wm-dsgvo-newsletter-suite' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme. Placeholder until the family
	 * icon is decided (hackaton plan D12).
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'WM DSGVO Newsletter', 'wm-dsgvo-newsletter-suite' ),
			__( 'DSGVO Newsletter', 'wm-dsgvo-newsletter-suite' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_dashboard' ],
			self::family_menu_icon(),
			32
		);
	}

	/** Family sheets on the plugin's page and on the dashboard (widget). */
	public function enqueue_assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( 'index.php' !== $hook && ! str_contains( $hook, 'wm-newsletter' ) && ! str_contains( $page, 'wm-newsletter' ) && ! str_contains( $page, 'newsletter-suite' ) ) {
			return;
		}

		$root = dirname( __DIR__, 2 ) . '/assets/css/';
		wp_enqueue_style(
			'wm-admin-tokens',
			WM_NEWSLETTER_PLUGIN_URL . 'assets/css/wm-admin-tokens.css',
			[],
			(string) ( @filemtime( $root . 'wm-admin-tokens.css' ) ?: WM_NEWSLETTER_VERSION ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file falls back to the version.
		);
		wp_enqueue_style(
			'wm-admin-ui',
			WM_NEWSLETTER_PLUGIN_URL . 'assets/css/wm-admin-ui.css',
			[ 'wm-admin-tokens' ],
			(string) ( @filemtime( $root . 'wm-admin-ui.css' ) ?: WM_NEWSLETTER_VERSION ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file falls back to the version.
		);
	}

	public function handle_save_settings(): void {
		check_admin_referer( 'wm_newsletter_settings_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-dsgvo-newsletter-suite' ) );
		}

		$post = isset( $_POST['wm_settings'] ) && is_array( $_POST['wm_settings'] ) ? wp_unslash( $_POST['wm_settings'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised below.
		$this->save_submitted_settings( $post );
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'settings', 'saved' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Persist validated settings without echoing stored secrets or clobbering untouched keys on empty input. */
	public function save_submitted_settings( array $post ): void {
		$provider      = sanitize_key( (string) ( $post['active_provider'] ?? 'local' ) );
		$clear_secrets = isset( $post['clear_secrets'] ) && is_array( $post['clear_secrets'] ) ? $post['clear_secrets'] : [];

		$resolve_secret = function ( string $key ) use ( $post, $clear_secrets ): string {
			if ( ! empty( $clear_secrets[ $key ] ) ) {
				return '';
			}
			$submitted = sanitize_text_field( (string) ( $post[ $key ] ?? '' ) );
			if ( '' !== $submitted ) {
				return $submitted;
			}
			return $this->settings->get_stored_value( $key );
		};

		$rapidmail_user = isset( $post['rapidmail_username'] )
			? sanitize_text_field( (string) $post['rapidmail_username'] )
			: ( $this->settings->has_env_constant( 'rapidmail_username' ) ? $this->settings->get_stored_value( 'rapidmail_username' ) : '' );

		$data = [
			'active_provider'     => in_array( $provider, SettingsManager::PROVIDERS, true ) ? $provider : 'local',
			'doi_ttl_hours'       => max( 1, min( 168, (int) ( $post['doi_ttl_hours'] ?? 48 ) ) ),
			'sender_name'         => sanitize_text_field( (string) ( $post['sender_name'] ?? '' ) ),
			'sender_email'        => sanitize_email( (string) ( $post['sender_email'] ?? '' ) ),
			'altcha_enabled'      => ! empty( $post['altcha_enabled'] ) ? 1 : 0,
			'enable_rfc8058'      => ! empty( $post['enable_rfc8058'] ) ? 1 : 0,
			'default_list_id'     => sanitize_text_field( (string) ( $post['default_list_id'] ?? 'default' ) ),
			'legal_notice_url'    => esc_url_raw( (string) ( $post['legal_notice_url'] ?? '' ) ),
			'privacy_policy_url'  => esc_url_raw( (string) ( $post['privacy_policy_url'] ?? '' ) ),
			'doi_email_subject'   => sanitize_text_field( (string) ( $post['doi_email_subject'] ?? '' ) ),
			'brevo_api_key'       => $resolve_secret( 'brevo_api_key' ),
			'rapidmail_username'  => $rapidmail_user,
			'rapidmail_password'  => $resolve_secret( 'rapidmail_password' ),
			'cleverreach_token'   => $resolve_secret( 'cleverreach_token' ),
			'kit_api_key'         => $resolve_secret( 'kit_api_key' ),
			'mailchimp_api_key'   => $resolve_secret( 'mailchimp_api_key' ),
		];

		$this->settings->update_all( $data );
		$this->settings->set_delete_data_on_uninstall( ! empty( $post['delete_data_on_uninstall'] ) );
	}

	/** Render a password secret row without echoing the stored credential back into the DOM. */
	private function render_secret_row( string $id, string $key, string $label, array $settings ): void {
		$has_env    = $this->settings->has_env_constant( $key );
		$has_stored = '' !== $this->settings->get_stored_value( $key );
		$configured = $has_env || '' !== (string) ( $settings[ $key ] ?? '' );
		?>
		<div class="wm-form-row">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="password" id="<?php echo esc_attr( $id ); ?>" name="wm_settings[<?php echo esc_attr( $key ); ?>]" value="" class="wm-input" autocomplete="new-password"<?php echo $configured ? ' placeholder="' . esc_attr( '••••••••' ) . '"' : ''; ?><?php echo $has_env ? ' disabled="disabled"' : ''; ?>>
			<?php if ( $has_env ) : ?>
				<p class="description"><code>wp-config.php</code>: <code><?php echo esc_html( SettingsManager::env_constant_name( $key ) ); ?></code></p>
			<?php elseif ( $has_stored ) : ?>
				<label style="display:inline-flex;align-items:center;gap:6px;margin-top:4px;">
					<input type="checkbox" name="wm_settings[clear_secrets][<?php echo esc_attr( $key ); ?>]" value="1">
					<?php echo esc_html( $label . ': ' . __( 'aus', 'wm-dsgvo-newsletter-suite' ) ); ?>
				</label>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_run_sync(): void {
		check_admin_referer( 'wm_newsletter_sync_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-dsgvo-newsletter-suite' ) );
		}

		$this->queue->process_queue( 50 );
		$run = $this->queue->last_run();
		wp_safe_redirect( add_query_arg( [
			'page'        => self::PAGE,
			'sync_ran'    => 1,
			'sync_extern' => $run['external'],
			'sync_local'  => $run['local'],
			'sync_failed' => $run['failed'],
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_export_csv(): void {
		check_admin_referer( 'wm_newsletter_export_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-dsgvo-newsletter-suite' ) );
		}

		$records = $this->repo->get_staging_records( 5000 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="wm-newsletter-doi-' . gmdate( 'Y-m-d_His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- HTTP response stream.
		fputcsv( $out, [ 'ID', 'Email', 'Blinded IP (HMAC)', 'Consent Hash (SHA-256)', 'Consent Text', 'Legal Basis', 'List', 'Status', 'Created At', 'Confirmed At', 'Synced At', 'Provider' ] );

		foreach ( $records as $r ) {
			fputcsv( $out, array_map( [ self::class, 'csv_cell' ], [
				$r['id'],
				$r['email'],
				$r['blinded_ip'],
				$r['consent_snapshot_hash'],
				$r['consent_text'],
				$r['legal_basis'],
				$r['list_id'],
				$r['status'],
				$r['created_at'],
				$r['confirmed_at'] ?? '',
				$r['synced_at'] ?? '',
				$r['provider_adapter'],
			] ) );
		}
		// php://output is the HTTP response stream, not the filesystem; WP_Filesystem does not apply.
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Notice after the sync button. Rows kept by the provider "local" are named as such: until 2026-09-15
	 * they were reported as "an den Anbieter übergeben", although nothing left the site.
	 */
	public static function sync_notice( int $external, int $local, int $failed ): string {
		if ( 0 === $external + $local + $failed ) {
			return __( 'Keine bestätigte Anmeldung wartete auf Übergabe.', 'wm-dsgvo-newsletter-suite' );
		}
		$parts = [];
		if ( $external > 0 ) {
			/* translators: %d: number of contacts an external provider accepted */
			$parts[] = sprintf( __( '%d Kontakte an den Anbieter übergeben.', 'wm-dsgvo-newsletter-suite' ), $external );
		}
		if ( $local > 0 ) {
			/* translators: %d: number of confirmed addresses kept in the local table */
			$parts[] = sprintf( __( '%d bestätigte Adressen nur in der lokalen Tabelle geführt (Einstellung „Nur lokal“, nichts verlässt die Website).', 'wm-dsgvo-newsletter-suite' ), $local );
		}
		if ( $failed > 0 ) {
			/* translators: %d: number of failed pushes */
			$parts[] = sprintf( __( '%d Übergaben fehlgeschlagen; die Antwort des Anbieters steht im PHP-Fehlerprotokoll.', 'wm-dsgvo-newsletter-suite' ), $failed );
		}
		return implode( ' ', $parts );
	}

	private static function tab_url( string $tab ): string {
		return add_query_arg( [ 'page' => self::PAGE, 'tab' => $tab ], admin_url( 'admin.php' ) );
	}

	public function render_dashboard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$activeTab = sanitize_key( wp_unslash( (string) ( $_GET['tab'] ?? 'overview' ) ) );
		$tabs      = [
			'overview'  => __( 'Übersicht', 'wm-dsgvo-newsletter-suite' ),
			'staging'   => __( 'Anmeldungen', 'wm-dsgvo-newsletter-suite' ),
			'settings'  => __( 'Einstellungen', 'wm-dsgvo-newsletter-suite' ),
			'generator' => __( 'Shortcode', 'wm-dsgvo-newsletter-suite' ),
		];
		if ( ! isset( $tabs[ $activeTab ] ) ) {
			$activeTab = 'overview';
		}

		$stats    = $this->repo->get_stats();
		$settings = $this->settings->get_all();
		$missing  = $this->settings->missing_provider_settings();
		$labels   = self::provider_labels();
		$nextSync = wp_next_scheduled( SyncQueue::SYNC_HOOK );
		?>
		<div class="wrap wm-admin">
			<header class="wm-admin-header">
				<div class="wm-header-title">
					<h1><?php esc_html_e( 'WM DSGVO Newsletter Suite', 'wm-dsgvo-newsletter-suite' ); ?></h1>
					<p class="wm-subtitle"><?php esc_html_e( 'Newsletter-Anmeldung mit Double-Opt-In: Formular, Bestätigungsmail, Protokoll je Anmeldung und Übergabe bestätigter Adressen an den eingestellten Anbieter. Es ersetzt keine rechtliche Prüfung von Einwilligungstext und Versand.', 'wm-dsgvo-newsletter-suite' ); ?></p>
				</div>
				<div class="wm-header-badges">
					<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-dsgvo-newsletter-suite' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
						<?php esc_html_e( 'WM Suite', 'wm-dsgvo-newsletter-suite' ); ?>
						<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-dsgvo-newsletter-suite' ) : esc_html__( 'Standalone', 'wm-dsgvo-newsletter-suite' ); ?></span>
					</span>
				</div>
				<div class="wm-header-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wm_newsletter_sync_nonce' ); ?>
						<input type="hidden" name="action" value="wm_newsletter_run_sync">
						<button type="submit" class="wm-btn wm-btn-primary"><?php esc_html_e( 'Übergabe jetzt ausführen', 'wm-dsgvo-newsletter-suite' ); ?></button>
					</form>
				</div>
			</header>

			<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Bereiche', 'wm-dsgvo-newsletter-suite' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $slug ) ); ?>" class="wm-tab-link <?php echo $activeTab === $slug ? 'active' : ''; ?>"<?php echo $activeTab === $slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags from the redirect after a nonce-checked action. ?>
			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="wm-alert wm-alert-success" role="status"><?php esc_html_e( 'Einstellungen gespeichert.', 'wm-dsgvo-newsletter-suite' ); ?></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['sync_ran'] ) ) : ?>
				<div class="wm-alert wm-alert-info" role="status">
					<?php echo esc_html( self::sync_notice( absint( wp_unslash( $_GET['sync_extern'] ?? 0 ) ), absint( wp_unslash( $_GET['sync_local'] ?? 0 ) ), absint( wp_unslash( $_GET['sync_failed'] ?? 0 ) ) ) ); ?>
				</div>
			<?php endif; ?>
			<?php // phpcs:enable ?>

			<?php if ( 'overview' === $activeTab ) : ?>
				<div class="wm-kpi-grid">
					<div class="wm-kpi-card">
						<div class="wm-kpi-val"><?php echo esc_html( number_format_i18n( (int) $stats['active_subscribers'] ) ); ?></div>
						<div class="wm-kpi-lbl"><?php esc_html_e( 'Abonniert (lokale Tabelle)', 'wm-dsgvo-newsletter-suite' ); ?></div>
					</div>
					<div class="wm-kpi-card">
						<div class="wm-kpi-val"><?php echo esc_html( number_format_i18n( (int) $stats['pending_doi'] ) ); ?></div>
						<div class="wm-kpi-lbl"><?php esc_html_e( 'Unbestätigte Anmeldungen', 'wm-dsgvo-newsletter-suite' ); ?></div>
					</div>
					<div class="wm-kpi-card">
						<?php if ( 'local' === (string) $settings['active_provider'] ) : ?>
							<div class="wm-kpi-val"><?php echo esc_html( number_format_i18n( (int) $stats['kept_local'] ) ); ?></div>
							<div class="wm-kpi-lbl"><?php esc_html_e( 'Nur lokal geführt (keine Übergabe an einen Anbieter)', 'wm-dsgvo-newsletter-suite' ); ?></div>
						<?php else : ?>
							<div class="wm-kpi-val"><?php echo esc_html( number_format_i18n( (int) $stats['synced_to_provider'] ) ); ?></div>
							<div class="wm-kpi-lbl"><?php esc_html_e( 'An den Anbieter übergeben', 'wm-dsgvo-newsletter-suite' ); ?></div>
						<?php endif; ?>
					</div>
					<div class="wm-kpi-card">
						<div class="wm-kpi-val"><?php echo esc_html( number_format_i18n( (int) $stats['unsubscribers'] ) ); ?></div>
						<div class="wm-kpi-lbl"><?php esc_html_e( 'Abgemeldet (lokale Tabelle)', 'wm-dsgvo-newsletter-suite' ); ?></div>
					</div>
				</div>

				<section class="wm-card" aria-labelledby="wm-nl-state">
					<h2 id="wm-nl-state"><?php esc_html_e( 'Stand dieser Website', 'wm-dsgvo-newsletter-suite' ); ?></h2>
					<p class="wm-card-caption"><?php esc_html_e( 'Beim Aufruf dieser Seite gelesen.', 'wm-dsgvo-newsletter-suite' ); ?></p>
					<table class="wm-table">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Anbieter', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td>
									<?php echo esc_html( $labels[ $settings['active_provider'] ] ?? (string) $settings['active_provider'] ); ?>
									<?php if ( [] !== $missing ) : ?>
										<br /><span class="wm-status-pill wm-status-needs">
										<?php
										/* translators: %s: comma-separated setting names */
										echo esc_html( sprintf( __( 'Es fehlt: %s', 'wm-dsgvo-newsletter-suite' ), implode( ', ', $missing ) ) );
										?>
										</span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'An einen externen Anbieter übergeben', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td><?php echo esc_html( number_format_i18n( (int) $stats['synced_to_provider'] ) ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Nur in der lokalen Tabelle geführt (Einstellung „Nur lokal“, nichts verlässt die Website)', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td><?php echo esc_html( number_format_i18n( (int) $stats['kept_local'] ) ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Bestätigt, wartet auf den nächsten Übergabe-Lauf', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td><?php echo esc_html( number_format_i18n( (int) $stats['waiting_for_sync'] ) ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Übergabe fehlgeschlagen (wird bei jedem Lauf erneut versucht)', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td><span class="wm-status-pill <?php echo $stats['sync_failed'] > 0 ? 'wm-status-degraded' : 'wm-status-healthy'; ?>"><?php echo esc_html( number_format_i18n( (int) $stats['sync_failed'] ) ); ?></span></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Nicht übergeben, weil die Adresse lokal abgemeldet ist', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td><?php echo esc_html( number_format_i18n( (int) $stats['held_unsubscribed'] ) ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Nächster automatischer Übergabe-Lauf (WP-Cron, alle fünf Minuten)', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td><?php echo $nextSync ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $nextSync ) ) : '<span class="wm-status-pill wm-status-degraded">' . esc_html__( 'nicht geplant', 'wm-dsgvo-newsletter-suite' ) . '</span>'; ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Bestätigungsfenster; danach werden unbestätigte Anmeldungen gelöscht', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td>
									<?php
									/* translators: %d: hours */
									echo esc_html( sprintf( __( '%d Stunden', 'wm-dsgvo-newsletter-suite' ), $this->settings->ttl_hours() ) );
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Proof-of-Work im Formular', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td><?php echo ! empty( $settings['altcha_enabled'] ) ? esc_html__( 'an', 'wm-dsgvo-newsletter-suite' ) : esc_html__( 'aus', 'wm-dsgvo-newsletter-suite' ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'List-Unsubscribe-Header in der Bestätigungsmail', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<td><?php echo ! empty( $settings['enable_rfc8058'] ) ? esc_html__( 'an', 'wm-dsgvo-newsletter-suite' ) : esc_html__( 'aus', 'wm-dsgvo-newsletter-suite' ); ?></td>
							</tr>
						</tbody>
					</table>
				</section>

				<section class="wm-card" aria-labelledby="wm-nl-limits">
					<h2 id="wm-nl-limits"><?php esc_html_e( 'Was das Plugin tut und was nicht', 'wm-dsgvo-newsletter-suite' ); ?></h2>
					<ul class="wm-card-body">
						<li><?php esc_html_e( 'Jede Anmeldung wird mit dem angezeigten Einwilligungstext, dessen SHA-256-Hash, einem geschlüsselten Hash der IP-Adresse und dem Zeitpunkt gespeichert. Die Zeilen sind in der Datenbank änderbar; es gibt keine Signatur oder Kette.', 'wm-dsgvo-newsletter-suite' ); ?></li>
						<li><?php esc_html_e( 'Erst nach Aufruf des Bestätigungslinks wird die Adresse als abonniert geführt und beim nächsten Lauf an den Anbieter übergeben. Unbestätigte Anmeldungen werden nach dem Bestätigungsfenster gelöscht.', 'wm-dsgvo-newsletter-suite' ); ?></li>
						<li><?php esc_html_e( 'Eine Abmeldung über den Link aus der Bestätigungsmail oder über den Webhook ändert nur die lokale Tabelle. Beim Anbieter bereits übergebene Kontakte bleiben dort angemeldet.', 'wm-dsgvo-newsletter-suite' ); ?></li>
						<li><?php esc_html_e( 'Newsletter versendet das Plugin nicht; das macht der Anbieter.', 'wm-dsgvo-newsletter-suite' ); ?></li>
						<li><?php esc_html_e( 'Ob Einwilligungstext, Bestätigungsmail und Anbieter den rechtlichen Anforderungen genügen, beurteilt das Plugin nicht.', 'wm-dsgvo-newsletter-suite' ); ?></li>
					</ul>
				</section>

			<?php elseif ( 'staging' === $activeTab ) : ?>
				<?php $records = $this->repo->get_staging_records( 50 ); ?>
				<section class="wm-card" aria-labelledby="wm-nl-staging">
					<div class="wm-card-header">
						<h2 id="wm-nl-staging"><?php esc_html_e( 'Letzte 50 Anmeldungen', 'wm-dsgvo-newsletter-suite' ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'wm_newsletter_export_nonce' ); ?>
							<input type="hidden" name="action" value="wm_newsletter_export_csv">
							<button type="submit" class="wm-btn wm-btn-secondary"><?php esc_html_e( 'CSV-Export (bis 5.000 Zeilen)', 'wm-dsgvo-newsletter-suite' ); ?></button>
						</form>
					</div>
					<table class="wm-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'ID', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'E-Mail', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'IP-Hash', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Hash des Einwilligungstexts', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Anbieter', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Angemeldet', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Bestätigt', 'wm-dsgvo-newsletter-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $records ) ) : ?>
								<tr><td colspan="8"><?php esc_html_e( 'Keine Anmeldungen vorhanden.', 'wm-dsgvo-newsletter-suite' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $records as $r ) : ?>
									<?php
									$pill = match ( (string) $r['status'] ) {
										'CONFIRMED', 'SYNCED_TO_PROVIDER' => 'wm-status-healthy',
										'PENDING_CONFIRMATION'            => 'wm-status-needs',
										'SYNC_FAILED'                     => 'wm-status-error',
										default                           => 'wm-status-degraded',
									};
									?>
									<tr>
										<td><?php echo esc_html( (string) $r['id'] ); ?></td>
										<td><?php echo esc_html( (string) $r['email'] ); ?></td>
										<td><code><?php echo esc_html( substr( (string) $r['blinded_ip'], 0, 16 ) ); ?>…</code></td>
										<td><code><?php echo esc_html( substr( (string) $r['consent_snapshot_hash'], 0, 12 ) ); ?>…</code></td>
										<td><span class="wm-status-pill <?php echo esc_attr( $pill ); ?>"><?php echo esc_html( (string) $r['status'] ); ?></span></td>
										<td><?php echo esc_html( $labels[ (string) ( $r['provider_adapter'] ?? '' ) ] ?? (string) ( $r['provider_adapter'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) $r['created_at'] ); ?></td>
										<td><?php echo esc_html( (string) ( $r['confirmed_at'] ?? '—' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</section>

			<?php elseif ( 'settings' === $activeTab ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wm_newsletter_settings_nonce' ); ?>
					<input type="hidden" name="action" value="wm_newsletter_save_settings">

					<section class="wm-card" aria-labelledby="wm-nl-general">
						<h2 id="wm-nl-general"><?php esc_html_e( 'Allgemein und Absender', 'wm-dsgvo-newsletter-suite' ); ?></h2>
						<div class="wm-form-row">
							<label for="wm-nl-provider"><?php esc_html_e( 'Anbieter', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<select id="wm-nl-provider" name="wm_settings[active_provider]" class="wm-input">
								<?php foreach ( $labels as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['active_provider'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="wm-form-row">
							<label for="wm-nl-sender-name"><?php esc_html_e( 'Absendername', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<input type="text" id="wm-nl-sender-name" name="wm_settings[sender_name]" value="<?php echo esc_attr( (string) $settings['sender_name'] ); ?>" class="wm-input">
						</div>
						<div class="wm-form-row">
							<label for="wm-nl-sender-email"><?php esc_html_e( 'Absenderadresse', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<input type="email" id="wm-nl-sender-email" name="wm_settings[sender_email]" value="<?php echo esc_attr( (string) $settings['sender_email'] ); ?>" class="wm-input">
						</div>
						<div class="wm-form-row">
							<label for="wm-nl-subject"><?php esc_html_e( 'Betreff der Bestätigungsmail', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<input type="text" id="wm-nl-subject" name="wm_settings[doi_email_subject]" value="<?php echo esc_attr( (string) $settings['doi_email_subject'] ); ?>" class="wm-input">
						</div>
						<div class="wm-form-row">
							<label for="wm-nl-ttl"><?php esc_html_e( 'Bestätigungsfenster in Stunden (1–168); danach werden unbestätigte Anmeldungen gelöscht', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<input type="number" id="wm-nl-ttl" name="wm_settings[doi_ttl_hours]" value="<?php echo esc_attr( (string) $this->settings->ttl_hours() ); ?>" min="1" max="168" class="wm-input">
						</div>
						<div class="wm-form-row">
							<label for="wm-nl-legal"><?php esc_html_e( 'Impressum-URL (Fuß der Bestätigungsmail)', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<input type="url" id="wm-nl-legal" name="wm_settings[legal_notice_url]" value="<?php echo esc_attr( (string) $settings['legal_notice_url'] ); ?>" class="wm-input">
						</div>
						<div class="wm-form-row">
							<label for="wm-nl-privacy"><?php esc_html_e( 'Datenschutzerklärung-URL (Formular und Mail)', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<input type="url" id="wm-nl-privacy" name="wm_settings[privacy_policy_url]" value="<?php echo esc_attr( (string) $settings['privacy_policy_url'] ); ?>" class="wm-input">
						</div>
						<div class="wm-form-row">
							<label for="wm-nl-altcha">
								<input type="checkbox" id="wm-nl-altcha" name="wm_settings[altcha_enabled]" value="1" <?php checked( (int) $settings['altcha_enabled'], 1 ); ?>>
								<?php esc_html_e( 'Proof-of-Work im Formular verlangen (der Browser rechnet vor dem Absenden; ohne HTTPS nicht möglich)', 'wm-dsgvo-newsletter-suite' ); ?>
							</label>
						</div>
						<div class="wm-form-row">
							<label for="wm-nl-rfc8058">
								<input type="checkbox" id="wm-nl-rfc8058" name="wm_settings[enable_rfc8058]" value="1" <?php checked( (int) $settings['enable_rfc8058'], 1 ); ?>>
								<?php esc_html_e( 'List-Unsubscribe- und List-Unsubscribe-Post-Header in die Bestätigungsmail setzen', 'wm-dsgvo-newsletter-suite' ); ?>
							</label>
						</div>
					</section>

					<section class="wm-card" aria-labelledby="wm-nl-credentials">
						<h2 id="wm-nl-credentials"><?php esc_html_e( 'Zugangsdaten der Anbieter', 'wm-dsgvo-newsletter-suite' ); ?></h2>
						<?php
						$this->render_secret_row( 'wm-nl-brevo', 'brevo_api_key', __( 'Brevo API-Schlüssel', 'wm-dsgvo-newsletter-suite' ), $settings );
						?>
						<div class="wm-form-row">
							<label for="wm-nl-rapidmail-user"><?php esc_html_e( 'Rapidmail API-Benutzer', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<input type="text" id="wm-nl-rapidmail-user" name="wm_settings[rapidmail_username]" value="<?php echo esc_attr( $this->settings->has_env_constant( 'rapidmail_username' ) ? '' : (string) $settings['rapidmail_username'] ); ?>" class="wm-input" autocomplete="off"<?php echo $this->settings->has_env_constant( 'rapidmail_username' ) ? ' placeholder="••••••••" disabled="disabled"' : ''; ?>>
							<?php if ( $this->settings->has_env_constant( 'rapidmail_username' ) ) : ?>
								<p class="description"><code>wp-config.php</code>: <code><?php echo esc_html( SettingsManager::env_constant_name( 'rapidmail_username' ) ); ?></code></p>
							<?php endif; ?>
						</div>
						<?php
						$this->render_secret_row( 'wm-nl-rapidmail-pass', 'rapidmail_password', __( 'Rapidmail API-Passwort', 'wm-dsgvo-newsletter-suite' ), $settings );
						$this->render_secret_row( 'wm-nl-cleverreach', 'cleverreach_token', __( 'CleverReach REST-Token', 'wm-dsgvo-newsletter-suite' ), $settings );
						$this->render_secret_row( 'wm-nl-kit', 'kit_api_key', __( 'Kit API-Schlüssel (v4)', 'wm-dsgvo-newsletter-suite' ), $settings );
						$this->render_secret_row( 'wm-nl-mailchimp', 'mailchimp_api_key', __( 'Mailchimp API-Schlüssel (mit Rechenzentrum, z. B. …-us6)', 'wm-dsgvo-newsletter-suite' ), $settings );
						?>
						<div class="wm-form-row">
							<label for="wm-nl-list"><?php esc_html_e( 'Standard-Liste bzw. Gruppen-ID (Rapidmail, CleverReach und Mailchimp brauchen eine echte ID)', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<input type="text" id="wm-nl-list" name="wm_settings[default_list_id]" value="<?php echo esc_attr( (string) $settings['default_list_id'] ); ?>" class="wm-input">
						</div>
					</section>

					<section class="wm-card" aria-labelledby="wm-nl-webhook">
						<h2 id="wm-nl-webhook"><?php esc_html_e( 'Webhook für Abmeldungen beim Anbieter', 'wm-dsgvo-newsletter-suite' ); ?></h2>
						<p class="wm-card-caption"><?php esc_html_e( 'Tragen Sie diese Adresse beim Anbieter ein. Ein Ereignis unsubscribe, spam, contact_deleted, unsub, complaint oder deleted (oder keines) mit einer E-Mail-Adresse setzt den lokalen Eintrag auf abgemeldet. Die Adresse enthält den Schlüssel: nicht weitergeben.', 'wm-dsgvo-newsletter-suite' ); ?></p>
						<div class="wm-form-row">
							<label for="wm-nl-webhook-url"><?php esc_html_e( 'Webhook-URL', 'wm-dsgvo-newsletter-suite' ); ?></label>
							<input type="text" id="wm-nl-webhook-url" class="wm-input" readonly value="<?php echo esc_attr( add_query_arg( 'key', (string) $settings['webhook_secret'], rest_url( 'wm-newsletter/v1/webhook' ) ) ); ?>">
						</div>
					</section>

					<section class="wm-card" aria-labelledby="wm-nl-uninstall">
						<h2 id="wm-nl-uninstall"><?php esc_html_e( 'Deinstallation und Datenaufbewahrung', 'wm-dsgvo-newsletter-suite' ); ?></h2>
						<p class="wm-card-caption"><?php esc_html_e( 'Standardmäßig bleiben Einstellungen, Abonnenten und Einwilligungsprotokolle beim Löschen des Plugins erhalten.', 'wm-dsgvo-newsletter-suite' ); ?></p>
						<div class="wm-form-row">
							<label for="wm-nl-delete-uninstall">
								<input type="checkbox" id="wm-nl-delete-uninstall" name="wm_settings[delete_data_on_uninstall]" value="1" <?php checked( $this->settings->delete_data_on_uninstall(), true ); ?>>
								<?php esc_html_e( 'Alle Einstellungen, Abonnenten und Einwilligungsprotokolle beim Deinstallieren unwiderruflich löschen', 'wm-dsgvo-newsletter-suite' ); ?>
							</label>
						</div>
					</section>

					<p><button type="submit" class="wm-btn wm-btn-primary"><?php esc_html_e( 'Einstellungen speichern', 'wm-dsgvo-newsletter-suite' ); ?></button></p>
				</form>

			<?php else : ?>
				<section class="wm-card" aria-labelledby="wm-nl-generator">
					<h2 id="wm-nl-generator"><?php esc_html_e( 'Formular einbinden', 'wm-dsgvo-newsletter-suite' ); ?></h2>
					<p class="wm-card-caption"><?php esc_html_e( 'Der Shortcode gibt das Anmeldeformular mit Einwilligungs-Checkbox und Link zur Datenschutzerklärung aus. Einen Gutenberg-Block gibt es nicht.', 'wm-dsgvo-newsletter-suite' ); ?></p>
					<pre class="wm-code-block"><code>[wm_dsgvo_newsletter title="Newsletter abonnieren" button_text="Anmelden"]</code></pre>
					<table class="wm-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Attribut', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Standardwert', 'wm-dsgvo-newsletter-suite' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Bedeutung', 'wm-dsgvo-newsletter-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr><td><code>title</code></td><td>Newsletter abonnieren</td><td><?php esc_html_e( 'Überschrift des Formulars', 'wm-dsgvo-newsletter-suite' ); ?></td></tr>
							<tr><td><code>description</code></td><td>—</td><td><?php esc_html_e( 'Text unter der Überschrift; leer bleibt er weg', 'wm-dsgvo-newsletter-suite' ); ?></td></tr>
							<tr><td><code>list_id</code></td><td>—</td><td><?php esc_html_e( 'Liste beim Anbieter; ohne Angabe (oder mit dem Wert default) gilt die Standard-Liste aus den Einstellungen', 'wm-dsgvo-newsletter-suite' ); ?></td></tr>
							<tr><td><code>legal_basis</code></td><td>art_6_1_a</td><td><?php esc_html_e( 'art_6_1_a oder art_6_1_b; wird mit der Anmeldung gespeichert, das Plugin prüft sie nicht', 'wm-dsgvo-newsletter-suite' ); ?></td></tr>
							<tr><td><code>button_text</code></td><td>Anmelden</td><td><?php esc_html_e( 'Beschriftung des Absende-Buttons', 'wm-dsgvo-newsletter-suite' ); ?></td></tr>
						</tbody>
					</table>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}
}
