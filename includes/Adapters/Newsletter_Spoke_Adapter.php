<?php
/**
 * WM Suite DSGVO Newsletter Suite — Suite Hub spoke adapter.
 *
 * Until 2026-09-15 health returned `WARNING` (a value the hub does not accept) and never reported a
 * missing table or provider key; the settings link pointed at `wm-newsletter-settings`, a page that
 * does not exist; telemetry carried the fixed strings "ENABLED" and "100% IMMUTABLE_HMAC"; the SBOM
 * properties named judgments and statutes; and `self_test` only hashed a random string.
 *
 * @package WMCyber\Newsletter
 */

declare(strict_types=1);

namespace WMCyber\Newsletter\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;
use WMCyber\Newsletter\Database\DoiRepository;
use WMCyber\Newsletter\Database\Schema;
use WMCyber\Newsletter\Admin\SettingsManager;
use WMCyber\Newsletter\Queue\SyncQueue;
use WMCyber\Newsletter\Security\AltchaVerifier;

final class Newsletter_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-dsgvo-newsletter-suite';
	}

	public function get_name(): string {
		return 'WM DSGVO Newsletter Suite';
	}

	public function get_version(): string {
		return defined( 'WM_NEWSLETTER_VERSION' ) ? WM_NEWSLETTER_VERSION : '1.2.1';
	}

	public function get_description(): string {
		return 'Newsletter-Anmeldung mit Double-Opt-In, Protokoll je Anmeldung und serverseitiger Übergabe an den eingestellten Anbieter.';
	}

	/** True when both tables exist. */
	private function tables_present(): bool {
		global $wpdb;
		foreach ( [ Schema::get_staging_table_name(), Schema::get_subscribers_table_name() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live check for the hub.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	/** ERROR without tables, NEEDS_CONFIG without the active provider's settings, DEGRADED with failed syncs or no sync event. */
	public function get_health_status(): string {
		if ( ! $this->tables_present() ) {
			return 'ERROR';
		}
		if ( [] !== ( new SettingsManager() )->missing_provider_settings() ) {
			return 'NEEDS_CONFIG';
		}
		$metrics = $this->get_telemetry_metrics();
		if ( (int) $metrics['failed_esp_syncs'] > 0 || ! $metrics['sync_event_scheduled'] ) {
			return 'DEGRADED';
		}
		return 'HEALTHY';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-dsgvo-newsletter-suite@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', WM_NEWSLETTER_FILE ),
				],
			],
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			// Features, not legal labels: the plugin makes no assessment of consent or advertising law.
			'properties'  => [
				[
					'name'  => 'wm:features:doi',
					'value' => 'staging row per sign-up with consent text, SHA-256 of that text and HMAC of the IP; confirmation link valid for the configured hours; unconfirmed rows deleted after that window',
				],
				[
					'name'  => 'wm:features:providers',
					'value' => 'server-side push of confirmed addresses to local, Brevo, Rapidmail, CleverReach, Kit or Mailchimp every five minutes',
				],
				[
					'name'  => 'wm:features:unsubscribe',
					'value' => 'List-Unsubscribe and List-Unsubscribe-Post headers in the confirmation mail, per-address HMAC token, local table only',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=wm-newsletter-suite&tab=settings' );
	}

	public function get_telemetry_metrics(): array {
		$present  = $this->tables_present();
		$settings = new SettingsManager();
		$config   = $settings->get_all();
		// The repository's counts: waiting and failed are the rows the next run sends, so a failed row of an
		// address unsubscribed afterwards no longer holds health on DEGRADED (it did until 2026-09-15).
		$stats    = $present ? ( new DoiRepository() )->get_stats() : [];
		$count    = static fn( string $key ): ?int => $present ? (int) ( $stats[ $key ] ?? 0 ) : null;

		return [
			'tables_present'        => $present,
			'confirmed_subscribers' => $count( 'active_subscribers' ),
			'unsubscribed'          => $count( 'unsubscribers' ),
			'pending_doi_requests'  => $count( 'pending_doi' ),
			'waiting_for_sync'      => $count( 'waiting_for_sync' ),
			'failed_esp_syncs'      => $count( 'sync_failed' ),
			'synced_to_provider'    => $count( 'synced_to_provider' ),
			'kept_local'            => $count( 'kept_local' ),
			'held_unsubscribed'     => $count( 'held_unsubscribed' ),
			'active_esp_adapter'    => (string) ( $config['active_provider'] ?? 'local' ),
			'missing_settings'      => $settings->missing_provider_settings(),
			'doi_ttl_hours'         => $settings->ttl_hours(),
			'proof_of_work'         => ! empty( $config['altcha_enabled'] ),
			'rfc8058_header'        => ! empty( $config['enable_rfc8058'] ),
			'sync_event_scheduled'  => false !== wp_next_scheduled( SyncQueue::SYNC_HOOK ),
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		switch ( $action ) {
			case 'self_test':
				// Round trip of the form's proof of work with the site's key, without consuming a challenge.
				$key       = (string) ( new SettingsManager() )->get( 'altcha_hmac_key' );
				$challenge = AltchaVerifier::create_challenge( $key, 2000 );
				$number    = AltchaVerifier::solve( $challenge );
				$payload   = base64_encode( (string) wp_json_encode( [
					'algorithm' => $challenge['algorithm'],
					'challenge' => $challenge['challenge'],
					'number'    => $number,
					'salt'      => $challenge['salt'],
					'signature' => $challenge['signature'],
				] ) );
				$pow_ok    = null !== $number && AltchaVerifier::verify( $payload, $key, false );
				$tables_ok = $this->tables_present();
				$cron_ok   = false !== wp_next_scheduled( SyncQueue::SYNC_HOOK );

				return [
					'success' => $pow_ok && $tables_ok && $cron_ok,
					'message' => sprintf(
						'Selbsttest: Proof-of-Work %s, Tabellen %s, Sync-Ereignis %s.',
						$pow_ok ? 'ok' : 'fehlgeschlagen',
						$tables_ok ? 'vorhanden' : 'fehlen',
						$cron_ok ? 'geplant' : 'nicht geplant'
					),
				];

			case 'queue_health':
				$metrics = $this->get_telemetry_metrics();
				return [
					'success' => 0 === (int) $metrics['failed_esp_syncs'] && $metrics['tables_present'],
					'message' => sprintf( 'Warteschlange: %d aktive Abonnenten, %d unbestätigt, %d warten auf Übergabe, %d fehlgeschlagen, %d nicht übergeben wegen lokaler Abmeldung.', (int) $metrics['confirmed_subscribers'], (int) $metrics['pending_doi_requests'], (int) $metrics['waiting_for_sync'], (int) $metrics['failed_esp_syncs'], (int) $metrics['held_unsubscribed'] ),
				];

			default:
				return [
					'success' => false,
					'message' => sprintf( 'Unbekannte Spoke-Aktion "%s".', esc_html( $action ) ),
				];
		}
	}
}
