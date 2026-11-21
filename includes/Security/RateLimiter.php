<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed window per action and client IP: at most `$limit` requests from the first request of a window
 * until `$windowSeconds` later.
 *
 * Until 2026-09-15 every request, refused ones included, wrote the counter back with the full window, so
 * the transient never expired while requests kept coming: a client sending one request every 50 seconds
 * against "5 per 60 s" was refused from the sixth request on, for good. Behind a proxy that hides the
 * visitor's address that client is the whole site.
 */
final class RateLimiter {
	/**
	 * @param int|null $now Unix time; null means time(). Tests pass it to walk through a window.
	 */
	public static function check( string $action, int $limit = 5, int $windowSeconds = 60, ?string $clientIp = null, ?int $now = null ): array {
		$ip            = $clientIp ?: self::remote_addr();
		$key           = 'wm_nl_rl_' . md5( $action . '_' . $ip );
		$now           = $now ?? time();
		$windowSeconds = max( 1, $windowSeconds );

		$state = get_transient( $key );
		// A counter written by the old code is a bare integer without a start; it starts a new window.
		if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] ) || $now - (int) $state['start'] >= $windowSeconds ) {
			$state = [ 'start' => $now, 'count' => 0 ];
		}

		$left = max( 1, (int) $state['start'] + $windowSeconds - $now );

		// Refused requests do not write: the window keeps its end.
		if ( (int) $state['count'] >= $limit ) {
			return [
				'allowed'     => false,
				'remaining'   => 0,
				'retry_after' => $left,
			];
		}

		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, $left );

		return [
			'allowed'     => true,
			'remaining'   => max( 0, $limit - $state['count'] ),
			'retry_after' => 0,
		];
	}

	/** Client address as PHP received it, for callers that pass none. */
	private static function remote_addr(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
	}

	public static function reset( string $action, ?string $clientIp = null ): void {
		$ip = $clientIp ?: self::remote_addr();
		delete_transient( 'wm_nl_rl_' . md5( $action . '_' . $ip ) );
	}
}
