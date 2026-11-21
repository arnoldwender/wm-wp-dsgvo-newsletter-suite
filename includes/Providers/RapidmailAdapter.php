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

final class RapidmailAdapter implements NewsletterProviderInterface {
	private string $username;
	private string $password;

	public function __construct( string $username, string $password ) {
		$this->username = trim( $username );
		$this->password = trim( $password );
	}

	public function add_subscriber( SubscriberDTO $subscriber ): ProviderResult {
		$listId = $subscriber->listId;
		if ( empty( $listId ) || $listId === 'default' ) {
			return ProviderResult::failure( 'Rapidmail requires a valid recipientlist_id.' );
		}

		$auth = base64_encode( "{$this->username}:{$this->password}" );
		$payload = [
			'recipientlist_id' => (int) $listId,
			'email'            => $subscriber->email,
			'status'           => 'active',
		];

		if ( ! empty( $subscriber->attributes['firstname'] ) ) {
			$payload['firstname'] = $subscriber->attributes['firstname'];
		}
		if ( ! empty( $subscriber->attributes['lastname'] ) ) {
			$payload['lastname'] = $subscriber->attributes['lastname'];
		}

		$response = wp_remote_post( 'https://apiv3.rapidmail.de/v3/recipients', [
			'headers' => [
				'Authorization' => "Basic {$auth}",
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

		if ( $code === 201 || $code === 200 ) {
			return ProviderResult::success( (string) ( $body['id'] ?? $subscriber->email ), $body );
		}

		return ProviderResult::failure( (string) ( $body['description'] ?? "HTTP {$code}" ), $code, $code >= 500 || 429 === $code, $body );
	}

	// Not implemented for Rapidmail; nothing calls these yet. Until 2026-09-15 they returned success and
	// "subscribed" without contacting the provider.
	public function update_subscriber( string $email, array $attributes ): ProviderResult {
		return ProviderResult::failure( 'update_subscriber is not implemented for Rapidmail; nothing was sent.', 'not_implemented' );
	}

	public function delete_subscriber( string $email ): ProviderResult {
		return ProviderResult::failure( 'delete_subscriber is not implemented for Rapidmail; nothing was sent.', 'not_implemented' );
	}

	public function get_subscriber_status( string $email ): SubscriberStatus {
		return SubscriberStatus::UNKNOWN;
	}

	public function test_connection( array $credentials = [] ): bool|\WP_Error {
		$u = $credentials['username'] ?? $this->username;
		$p = $credentials['password'] ?? $this->password;
		$auth = base64_encode( "{$u}:{$p}" );

		$res = wp_remote_get( 'https://apiv3.rapidmail.de/v3/recipientlists', [
			'headers' => [ 'Authorization' => "Basic {$auth}" ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( $code !== 200 ) {
			return new \WP_Error( 'rapidmail_auth_error', "Rapidmail auth failed with HTTP {$code}" );
		}

		return true;
	}
}
