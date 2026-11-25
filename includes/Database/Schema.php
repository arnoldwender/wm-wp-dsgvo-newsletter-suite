<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	public static function get_staging_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_newsletter_doi_staging';
	}

	public static function get_subscribers_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_newsletter_subscribers';
	}

	public static function create_tables(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();
		$staging_table   = self::get_staging_table_name();
		$subs_table      = self::get_subscribers_table_name();

		// 1. DOI Staging Ledger Table
		$sql_staging = "CREATE TABLE `{$staging_table}` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`email` VARCHAR(255) NOT NULL,
			`token_hash` CHAR(64) NOT NULL,
			`blinded_ip` CHAR(64) NOT NULL,
			`consent_snapshot_hash` CHAR(64) NOT NULL,
			`consent_text` TEXT NOT NULL,
			`attributes_json` LONGTEXT NULL,
			`tags_json` TEXT NULL,
			`list_id` VARCHAR(100) NOT NULL DEFAULT 'default',
			`provider_adapter` VARCHAR(50) NOT NULL DEFAULT 'local',
			`legal_basis` VARCHAR(50) NOT NULL DEFAULT 'art_6_1_a',
			`form_id` VARCHAR(50) NOT NULL DEFAULT 'default',
			`status` ENUM('PENDING_CONFIRMATION', 'CONFIRMED', 'SYNCED_TO_PROVIDER', 'SYNC_FAILED', 'EXPIRED', 'PURGED') NOT NULL DEFAULT 'PENDING_CONFIRMATION',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`confirmed_at` DATETIME NULL DEFAULT NULL,
			`synced_at` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_token_hash` (`token_hash`),
			INDEX `idx_email_status` (`email`(191), `status`),
			INDEX `idx_created_at` (`created_at`)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql_staging );

		// 2. Confirmed subscribers (one row per address)
		$sql_subs = "CREATE TABLE `{$subs_table}` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`email` VARCHAR(255) NOT NULL,
			`blinded_ip` CHAR(64) NOT NULL,
			`status` ENUM('subscribed', 'unsubscribed', 'cleaned') NOT NULL DEFAULT 'subscribed',
			`attributes_json` LONGTEXT NULL,
			`tags_json` TEXT NULL,
			`list_id` VARCHAR(100) NOT NULL DEFAULT 'default',
			`provider_id` VARCHAR(255) NULL,
			`provider_adapter` VARCHAR(50) NOT NULL DEFAULT 'local',
			`consent_hash` CHAR(64) NOT NULL,
			`consent_text` TEXT NOT NULL,
			`legal_basis` VARCHAR(50) NOT NULL DEFAULT 'art_6_1_a',
			`form_id` VARCHAR(50) NOT NULL DEFAULT 'default',
			`confirmed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`unsubscribed_at` DATETIME NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_sub_email` (`email`(191)),
			INDEX `idx_sub_status` (`status`),
			INDEX `idx_sub_list` (`list_id`)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql_subs );
		update_option( 'wm_newsletter_db_version', 2, false );
	}

	/**
	 * Bring database tables forward on already-active installs where activation does not re-run.
	 */
	public static function maybe_upgrade(): void {
		if ( 2 <= (int) get_option( 'wm_newsletter_db_version', 0 ) ) {
			return;
		}
		self::create_tables();
	}
}
