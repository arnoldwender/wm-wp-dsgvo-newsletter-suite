<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses requests to the plugin's own REST routes whose parameters carry stream wrappers, NUL bytes
 * or PHP code fragments.
 *
 * Until 2026-09-15 it scanned $_GET and $_POST of every request to the site on plugins_loaded and ended
 * the request with a bare 400: a blog comment containing "eval(" or a form mentioning "php://" was
 * rejected site-wide (measured with a comment on a local WordPress install), and the client IP went into the error log.
 */
final class RequestShield {
	private const ROUTE_PREFIX = '/wm-newsletter/v1/';

	private static array $dangerousProtocols = [
		'php://', 'data://', 'expect://', 'phar://', 'zip://', 'glob://', 'gopher://',
	];

	public static function boot(): void {
		add_filter( 'rest_pre_dispatch', [ self::class, 'guard_rest_request' ], 10, 3 );
	}

	/**
	 * @param mixed            $result  Earlier short-circuit value.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request being dispatched.
	 * @return mixed
	 */
	public static function guard_rest_request( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		// Lower case: core matches routes case-insensitively, so until 2026-09-15 /WM-Newsletter/v1/subscribe
		// reached the handler past this check.
		if ( ! str_starts_with( strtolower( (string) $request->get_route() ), self::ROUTE_PREFIX ) ) {
			return $result;
		}

		$param = self::first_unsafe_param( $request->get_params() );
		if ( null === $param ) {
			return $result;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) { error_log( sprintf( '[WM-NEWSLETTER-BLOCK] %s refused: parameter "%s"', (string) $request->get_route(), $param ) ); } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator signal, no personal data.

		return new \WP_Error(
			'wm_security_shield_blocked',
			__( 'Die Anfrage wurde abgelehnt.', 'wm-dsgvo-newsletter-suite' ),
			[ 'status' => 400 ]
		);
	}

	/** Name of the first parameter (at any depth) that fails is_string_safe(), or null. */
	public static function first_unsafe_param( array $params ): ?string {
		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				$nested = self::first_unsafe_param( $value );
				if ( null !== $nested ) {
					return $nested;
				}
			} elseif ( is_string( $value ) && ! self::is_string_safe( (string) $key, $value ) ) {
				return (string) $key;
			}
		}
		return null;
	}

	public static function is_string_safe( string $key, string $value ): bool {
		$lower = strtolower( $value );

		foreach ( self::$dangerousProtocols as $proto ) {
			if ( str_contains( $lower, $proto ) ) {
				return false;
			}
		}

		if ( str_contains( $value, chr( 0 ) ) || str_contains( $value, '%00' ) || str_contains( $value, '\0' ) ) {
			return false;
		}

		if ( str_contains( $lower, 'base64_decode(' ) || str_contains( $lower, 'eval(' ) || str_contains( $lower, 'passthru(' ) ) {
			return false;
		}

		return true;
	}
}
