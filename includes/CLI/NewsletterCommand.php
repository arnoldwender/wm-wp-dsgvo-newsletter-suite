<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Core\Plugin;
use WP_CLI;

/**
 * `wp wm-newsletter sync|purge-expired|purge|stats`.
 */
final class NewsletterCommand {
	/**
	 * Push confirmed subscribers to the configured provider (one batch).
	 *
	 * ## OPTIONS
	 * [--batch-size=<size>]
	 * : Number of rows to process.
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 * wp wm-newsletter sync --batch-size=25
	 */
	public function sync( array $args, array $assocArgs ): void {
		$size  = max( 1, (int) ( $assocArgs['batch-size'] ?? 50 ) );
		$queue = Plugin::instance()->getSyncQueue();

		WP_CLI::log( "Processing sync queue (batch size: {$size})..." );
		$queue->process_queue( $size );
		$run   = $queue->last_run();
		$stats = Plugin::instance()->getDoiRepository()->get_stats();
		// Rows kept by the provider "local" are named as such (counted as "accepted by the provider" until 2026-09-15).
		WP_CLI::success( sprintf(
			'This run: %d accepted by an external provider, %d kept in the local table only (provider "local"), %d failed. Now: %d waiting, %d failed and retried on the next run, %d not pushed because the address is unsubscribed locally.',
			$run['external'],
			$run['local'],
			$run['failed'],
			(int) $stats['waiting_for_sync'],
			(int) $stats['sync_failed'],
			(int) $stats['held_unsubscribed']
		) );
	}

	/**
	 * Delete unconfirmed sign-ups older than the configured confirmation window.
	 *
	 * ## EXAMPLES
	 * wp wm-newsletter purge-expired
	 *
	 * @subcommand purge-expired
	 */
	public function purge_expired( array $args, array $assocArgs ): void {
		$ttl    = Plugin::instance()->getSettingsManager()->ttl_hours();
		$purged = Plugin::instance()->getDoiRepository()->purge_expired_pending( $ttl );
		WP_CLI::success( sprintf( 'Deleted %d unconfirmed sign-ups older than %d hours.', $purged, $ttl ) );
	}

	/**
	 * Alias of purge-expired.
	 *
	 * ## EXAMPLES
	 * wp wm-newsletter purge
	 */
	public function purge( array $args, array $assocArgs ): void {
		$this->purge_expired( $args, $assocArgs );
	}

	/**
	 * Show subscriber statistics.
	 *
	 * ## EXAMPLES
	 * wp wm-newsletter stats
	 */
	public function stats( array $args, array $assocArgs ): void {
		$stats = Plugin::instance()->getDoiRepository()->get_stats();
		WP_CLI\Utils\format_items( 'table', [ $stats ], array_keys( $stats ) );
	}
}
