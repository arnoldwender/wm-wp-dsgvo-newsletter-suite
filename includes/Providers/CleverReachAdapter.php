<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Contracts\NewsletterProviderInterface;
use WMCyber\Newsletter\Domain\DTO\SubscriberDTO;
use WMCyber\Newsletter\Domain\DTO\ProviderResult;
use WMCyber\Newsletter\Domain\Enum\SubscriberStatus;

final class CleverReachAdapter implements NewsletterProviderInterface {
	private string $token;

	public function __construct( string $token ) {
		$this->token = trim( $token );
	}

	/**
	 * Unix time of a staging timestamp written in the site's clock, or null when it is not a full datetime
	 * (get_gmt_from_date() alone would turn an unreadable value into 1970).
	 */
	private static function unix_from_site_time( ?string $siteTime ): ?int {
		if ( null === $siteTime || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $siteTime ) ) {
			return null;
		}
		$unix = (int) get_gmt_from_date( $siteTime, 'U' );
		return $unix > 0 ? $unix : null;
	}

	public function add_subscriber( SubscriberDTO $subscriber ): ProviderResult {
		$listId = $subscriber->listId;
		if ( empty( $listId ) || $listId === 'default' ) {
			return ProviderResult::failure( 'CleverReach requires a valid Group ID.' );
		}

		// `registered` is when the address was added and `activated` when it became active (CleverReach DOI
		// guide, developers.cleverreach.com/docs/guides/doubleoptin, read 2026-09-15): here the sign-up and the
		// click on the confirmation link. Until 2026-09-15 both were the time of the push, so a row retried days
		// later carried the wrong dates. A row without a readable confirmation time is not sent as active.
		$registered = self::unix_from_site_time( $subscriber->createdAt );
		$activated  = self::unix_from_site_time( $subscriber->confirmedAt );
		if ( null === $registered || null === $activated ) {
			return ProviderResult::failure( 'CleverReach push needs the sign-up and confirmation times of the row; nothing was sent.', 'missing_consent_time' );
		}

		$payload = [
			'email'      => $subscriber->email,
			'registered' => $registered,
			'activated'  => $activated,
			'source'     => 'WM DSGVO Suite',
		];

		$response = wp_remote_post( 'https://rest.cleverreach.com/v3/groups.json/' . rawurlencode( $listId ) . '/receivers', [
			'headers' => [
				'Authorization' => "Bearer {$this->token}",
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return ProviderResult::failure( $response->get_error_message(), 'http_request_failed', true );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];

		if ( $code === 200 || $code === 201 ) {
			return ProviderResult::success( (string) ( $body['id'] ?? $subscriber->email ), $body );
		}

		return ProviderResult::failure( (string) ( $body['error']['message'] ?? "HTTP {$code}" ), $code, $code >= 500 || 429 === $code, $body );
	}

	// Not implemented for CleverReach; nothing calls these yet. Until 2026-09-15 they returned success and
	// "subscribed" without contacting the provider.
	public function update_subscriber( string $email, array $attributes ): ProviderResult {
		return ProviderResult::failure( 'update_subscriber is not implemented for CleverReach; nothing was sent.', 'not_implemented' );
	}

	public function delete_subscriber( string $email ): ProviderResult {
		return ProviderResult::failure( 'delete_subscriber is not implemented for CleverReach; nothing was sent.', 'not_implemented' );
	}

	public function get_subscriber_status( string $email ): SubscriberStatus {
		return SubscriberStatus::UNKNOWN;
	}

	public function test_connection( array $credentials = [] ): bool|\WP_Error {
		$token = $credentials['token'] ?? $this->token;
		$res = wp_remote_get( 'https://rest.cleverreach.com/v3/groups.json', [
			'headers' => [ 'Authorization' => "Bearer {$token}" ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( $code !== 200 ) {
			return new \WP_Error( 'cleverreach_auth_error', "CleverReach auth failed with HTTP {$code}" );
		}

		return true;
	}
}
