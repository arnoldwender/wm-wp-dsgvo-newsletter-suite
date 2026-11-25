<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ErrorSanitizer {
	public static function handle( \Throwable $e, string $context = 'GENERAL' ): array {
		$incidentRef = 'WM-NEWSLETTER-' . strtoupper( substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 ) );

		$logMessage = sprintf(
			'[%s] %s | Msg: %s | File: %s:%d | Trace: %s',
			$incidentRef,
			$context,
			$e->getMessage(),
			$e->getFile(),
			$e->getLine(),
			$e->getTraceAsString()
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) { error_log( $logMessage ); } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only incident log.

		return [
			'safe_message' => sprintf(
				/* translators: %s: incident reference the visitor can quote to support */
				__( 'Ein Systemfehler ist aufgetreten. Referenznummer: %s', 'wm-dsgvo-newsletter-suite' ),
				$incidentRef
			),
			'incident_ref' => $incidentRef,
		];
	}
}
