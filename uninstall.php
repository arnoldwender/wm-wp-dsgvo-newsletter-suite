<?php
/**
 * WM Suite DSGVO Newsletter Suite — uninstaller.
 *
 * Always unschedules both WP-Cron events. Preserves options, subscribers, and DOI consent records by
 * default unless the site administrator explicitly enabled `wm_newsletter_delete_data_on_uninstall`.
 *
 * @package WMCyber\Newsletter
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// A closure keeps every variable local: this file runs in the global scope.
( static function (): void {
	global $wpdb;

	// 1. Cron events (SyncQueue::SYNC_HOOK and SyncQueue::CLEANUP_HOOK).
	foreach ( [ 'wm_newsletter_process_sync_queue', 'wm_newsletter_hourly_cleanup' ] as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// 2. Preserve consent records, subscribers, and settings unless explicitly opted in.
	$delete_data = ! empty( get_option( 'wm_newsletter_delete_data_on_uninstall', 0 ) );
	if ( ! $delete_data ) {
		return;
	}

	// 3. Settings and transients.
	delete_option( 'wm_newsletter_settings' );
	delete_site_option( 'wm_newsletter_settings' );
	delete_option( 'wm_newsletter_delete_data_on_uninstall' );
	delete_site_option( 'wm_newsletter_delete_data_on_uninstall' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall purges plugin transients on explicit opt-in.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_wm_nl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wm_nl_' ) . '%'
		)
	);

	// 4. Tables.
	$staging     = $wpdb->prefix . 'wm_newsletter_doi_staging';
	$subscribers = $wpdb->prefix . 'wm_newsletter_subscribers';

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $staging ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes the plugin's own tables when opted in.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $subscribers ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes the plugin's own tables when opted in.

	// 5. Legacy uploads/wm-newsletter/exports directory if it holds only old protection files.
	$uploads = wp_upload_dir();
	$exports = trailingslashit( $uploads['basedir'] ) . 'wm-newsletter/exports';
	if ( ! is_dir( $exports ) ) {
		return;
	}
	foreach ( [ '.htaccess', 'web.config', 'index.php' ] as $file ) {
		if ( is_file( $exports . '/' . $file ) ) {
			wp_delete_file( $exports . '/' . $file );
		}
	}
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( WP_Filesystem() ) {
		global $wp_filesystem;
		// rmdir without recursion fails on a non-empty directory, which is the intent.
		if ( $wp_filesystem->rmdir( $exports ) ) {
			$wp_filesystem->rmdir( dirname( $exports ) );
		}
	}
} )();
