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

final class KitAdapter implements NewsletterProviderInterface {
	private string $apiKey;

	public function __construct( string $apiKey ) {
		$this->apiKey = trim( $apiKey );
	}

	public function add_subscriber( SubscriberDTO $subscriber ): ProviderResult {
		if ( empty( $this->apiKey ) ) {
			return ProviderResult::failure( 'Kit API key is not configured.' );
		}

		// Kit v4 API: Strip null values to prevent erasing existing attributes
		$fields = array_filter( $subscriber->attributes, fn( $v ) => null !== $v );

		$payload = [
			'email_address' => $subscriber->email,
		];

		if ( ! empty( $fields['first_name'] ) || ! empty( $fields['firstname'] ) ) {
			$payload['first_name'] = $fields['first_name'] ?? $fields['firstname'];
			unset( $fields['first_name'], $fields['firstname'] );
		}

		if ( ! empty( $fields ) ) {
			$payload['fields'] = $fields;
		}

		$response = wp_remote_post( 'https://api.kit.com/v4/subscribers', [
			'headers' => [
				'X-Kit-Api-Key' => $this->apiKey,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
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
			$subId = (string) ( $body['subscriber']['id'] ?? $body['id'] ?? $subscriber->email );
			return ProviderResult::success( $subId, $body );
		}

		return ProviderResult::failure( (string) ( $body['message'] ?? "HTTP {$code}" ), $code, $code >= 500 || 429 === $code, $body );
	}

	// Not implemented for Kit; nothing calls these yet. Until 2026-09-15 they returned success and
	// "subscribed" without contacting the provider.
	public function update_subscriber( string $email, array $attributes ): ProviderResult {
		return ProviderResult::failure( 'update_subscriber is not implemented for Kit; nothing was sent.', 'not_implemented' );
	}

	public function delete_subscriber( string $email ): ProviderResult {
		return ProviderResult::failure( 'delete_subscriber is not implemented for Kit; nothing was sent.', 'not_implemented' );
	}

	public function get_subscriber_status( string $email ): SubscriberStatus {
		return SubscriberStatus::UNKNOWN;
	}

	public function test_connection( array $credentials = [] ): bool|\WP_Error {
		$key = $credentials['api_key'] ?? $this->apiKey;
		$res = wp_remote_get( 'https://api.kit.com/v4/account', [
			'headers' => [ 'X-Kit-Api-Key' => $key ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( $code !== 200 ) {
			return new \WP_Error( 'kit_auth_error', "Kit API auth failed with HTTP {$code}" );
		}

		return true;
	}
}
