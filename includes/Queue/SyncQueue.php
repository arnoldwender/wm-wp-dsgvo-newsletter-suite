<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Database\DoiRepository;
use WMCyber\Newsletter\Admin\SettingsManager;
use WMCyber\Newsletter\Providers\ProviderFactory;
use WMCyber\Newsletter\Domain\DTO\SubscriberDTO;

/**
 * Pushes confirmed subscribers to the configured provider (WP-Cron every five minutes) and removes
 * expired unconfirmed sign-ups (hourly).
 *
 * Until 2026-09-15 the five-minute interval `every_five_minutes` was never registered, so
 * wp_schedule_event() refused the event and the queue only ran from the admin button or WP-CLI.
 */
final class SyncQueue {
	public const SYNC_HOOK     = 'wm_newsletter_process_sync_queue';
	public const CLEANUP_HOOK  = 'wm_newsletter_hourly_cleanup';
	public const SCHEDULE      = 'every_five_minutes';
	private const INTERVAL_SEC = 300;

	private DoiRepository $repo;
	private SettingsManager $settings;

	/** Outcome of the last process_queue() call: rows sent to an external provider, kept local, failed. */
	private array $lastRun = [ 'external' => 0, 'local' => 0, 'failed' => 0 ];

	public function __construct( DoiRepository $repo, SettingsManager $settings ) {
		$this->repo     = $repo;
		$this->settings = $settings;
	}

	public function boot(): void {
		// The interval has to exist before the event is scheduled against it.
		add_filter( 'cron_schedules', [ self::class, 'register_schedule' ] );
		add_action( self::SYNC_HOOK, [ $this, 'run_scheduled_sync' ] );
		add_action( self::CLEANUP_HOOK, [ $this, 'hourly_cleanup' ] );

		// Scheduled on init: wp_get_schedules() runs the filter above, whose label is translated, and
		// translations must not load before init (WordPress 6.7 notice).
		if ( did_action( 'init' ) ) {
			self::ensure_scheduled();
		} else {
			add_action( 'init', [ self::class, 'ensure_scheduled' ] );
		}
	}

	/** Schedules both events if they are missing. */
	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::SYNC_HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::SYNC_HOOK );
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * @param array $schedules WordPress cron schedules.
	 * @return array
	 */
	public static function register_schedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : [];
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = [
				'interval' => self::INTERVAL_SEC,
				// Another plugin may read the schedules before init; the label then stays untranslated.
				'display'  => did_action( 'init' ) ? __( 'Alle fünf Minuten', 'wm-dsgvo-newsletter-suite' ) : 'Alle fünf Minuten',
			];
		}
		return $schedules;
	}

	/** WP-Cron passes no argument; the batch size stays the class default. */
	public function run_scheduled_sync(): void {
		$this->process_queue();
	}

	/**
	 * One batch: rows in CONFIRMED or SYNC_FAILED whose address is not unsubscribed.
	 *
	 * @return int Rows the provider accepted, the provider "local" included; last_run() separates them.
	 */
	public function process_queue( int $batchSize = 25 ): int {
		$this->lastRun = [ 'external' => 0, 'local' => 0, 'failed' => 0 ];
		$items         = $this->repo->get_pending_sync_queue( $batchSize );
		if ( empty( $items ) ) {
			return 0;
		}

		$config    = $this->settings->get_all();
		$processed = 0;

		foreach ( $items as $row ) {
			$providerId = $row['provider_adapter'] ?: ( $config['active_provider'] ?? 'local' );
			$provider   = ProviderFactory::create( $providerId, $config );

			$attributes = json_decode( $row['attributes_json'] ?? '[]', true ) ?: [];
			$tags       = json_decode( $row['tags_json'] ?? '[]', true ) ?: [];
			$payload    = apply_filters(
				'wm_newsletter_subscriber_payload',
				[
					'attributes' => is_array( $attributes ) ? $attributes : [],
					'tags'       => is_array( $tags ) ? $tags : [],
					'list_id'    => (string) $row['list_id'],
				],
				(string) $row['email'],
				(string) $providerId
			);
			$filteredAttributes = is_array( $payload['attributes'] ?? null ) ? $payload['attributes'] : [];
			$filteredTags       = is_array( $payload['tags'] ?? null ) ? $payload['tags'] : [];
			$filteredListId     = is_string( $payload['list_id'] ?? null ) && '' !== $payload['list_id'] ? $payload['list_id'] : (string) $row['list_id'];

			$dto = new SubscriberDTO(
				email: $row['email'],
				blindedIp: $row['blinded_ip'],
				consentHash: $row['consent_snapshot_hash'],
				consentText: $row['consent_text'],
				attributes: $filteredAttributes,
				tags: $filteredTags,
				listId: $filteredListId,
				provider: $providerId,
				legalBasis: $row['legal_basis'],
				formId: $row['form_id'],
				confirmedAt: isset( $row['confirmed_at'] ) ? (string) $row['confirmed_at'] : null,
				createdAt: isset( $row['created_at'] ) ? (string) $row['created_at'] : null
			);

			$result = $provider->add_subscriber( $dto );

			if ( $result->success ) {
				$this->repo->mark_synced( (int) $row['id'], $result->providerSubscriberId );
				$processed++;
				$this->lastRun[ 'local' === $providerId ? 'local' : 'external' ]++;
			} else {
				$this->repo->mark_sync_failed( (int) $row['id'] );
				$this->lastRun['failed']++;
				// Row id, provider and answer only: the address stays out of the server log. Until
				// 2026-09-15 this line wrote the address and read a property that does not exist.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) { error_log( sprintf( '[WM-NEWSLETTER-SYNC-FAIL] staging #%d via %s: %s (%s)', (int) $row['id'], $providerId, $result->message, (string) $result->errorCode ) ); } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator needs the provider answer; no personal data.
			}
		}

		return $processed;
	}

	/**
	 * What the last process_queue() call did. Until 2026-09-15 the admin notice turned every accepted row
	 * into "an den Anbieter übergeben", also with the provider "local", where nothing leaves the site.
	 *
	 * @return array{external: int, local: int, failed: int}
	 */
	public function last_run(): array {
		return $this->lastRun;
	}

	/** Deletes unconfirmed sign-ups older than the configured confirmation window. */
	public function hourly_cleanup(): void {
		$this->repo->purge_expired_pending( $this->settings->ttl_hours() );
	}
}
