<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Domain\DTO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outcome of one provider call.
 *
 * Until 2026-09-15 both factories disagreed with every caller, and strict_types turned that into a
 * TypeError on each answer from a real provider:
 * - `failure()` took `( string $message, ?string $code, array $raw )`; the adapters call it as
 *   `failure( $message, $httpStatus, $retryable, $body )`, so a wrong key, a full list or a timeout
 *   crashed the sync run instead of marking the row;
 * - `success()` took `( string $message, ?string $subscriberId, array $raw )`; the adapters call it as
 *   `success( $providerId, $body )`, so an accepted contact crashed as well.
 * No confirmed subscriber could reach Brevo, Rapidmail, CleverReach, Kit or Mailchimp. The signatures
 * now match their callers.
 */
final class ProviderResult {
	public function __construct(
		public readonly bool $success,
		public readonly string $message,
		public readonly ?string $providerSubscriberId = null,
		public readonly ?string $errorCode = null,
		public readonly array $rawResponse = [],
		public readonly bool $retryable = false
	) {}

	/**
	 * @param string|null $subscriberId The provider's id for the contact, when it returns one.
	 * @param array       $raw          Decoded provider answer.
	 * @param string      $message      Short note for logs.
	 */
	public static function success( ?string $subscriberId = null, array $raw = [], string $message = 'OK' ): self {
		return new self(
			success: true,
			message: $message,
			providerSubscriberId: $subscriberId,
			rawResponse: $raw
		);
	}

	/**
	 * @param string          $message   Provider or adapter message (never shown to visitors).
	 * @param int|string|null $code      HTTP status or provider error code.
	 * @param bool            $retryable Whether a later attempt can succeed (network error, 5xx, 429).
	 * @param array           $raw       Decoded provider answer.
	 */
	public static function failure( string $message, int|string|null $code = null, bool $retryable = false, array $raw = [] ): self {
		return new self(
			success: false,
			message: $message,
			errorCode: null === $code ? null : (string) $code,
			rawResponse: $raw,
			retryable: $retryable
		);
	}
}
