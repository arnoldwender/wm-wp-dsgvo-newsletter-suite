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

final class BrevoAdapter implements NewsletterProviderInterface {
	private string $apiKey;

	public function __construct( string $apiKey ) {
		$this->apiKey = trim( $apiKey );
	}

	public function add_subscriber( SubscriberDTO $subscriber ): ProviderResult {
		if ( empty( $this->apiKey ) ) {
			return ProviderResult::failure( 'Brevo API key is not configured.' );
		}

		$payload = [
			'email'         => $subscriber->email,
			'attributes'    => $subscriber->attributes,
			'updateEnabled' => true,
		];

		if ( is_numeric( $subscriber->listId ) ) {
			$payload['listIds'] = [ (int) $subscriber->listId ];
		}

		$response = wp_remote_post( 'https://api.brevo.com/v3/contacts', [
			'headers' => [
				'api-key'      => $this->apiKey,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return ProviderResult::failure( $response->get_error_message(), 'http_request_failed', true );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];

		if ( $code === 201 || $code === 204 ) {
			return ProviderResult::success( (string) ( $body['id'] ?? $subscriber->email ), $body );
		}

		// Brevo returns 400 when contact already exists but updateEnabled handles it, or list is full
		if ( $code === 400 && str_contains( $body['message'] ?? '', 'Contact already exist' ) ) {
			return ProviderResult::success( (string) ( $body['id'] ?? $subscriber->email ), $body );
		}

		return ProviderResult::failure( (string) ( $body['message'] ?? "HTTP Error {$code}" ), $code, $code >= 500 || $code === 429, $body );
	}

	/** 2xx is success; anything else is reported with its status (until 2026-09-15 a 401 counted as done). */
	private static function result_from_response( $response ): ProviderResult {
		if ( is_wp_error( $response ) ) {
			return ProviderResult::failure( $response->get_error_message(), 'http_request_failed', true );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return ProviderResult::success();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
		return ProviderResult::failure( (string) ( $body['message'] ?? "HTTP Error {$code}" ), $code, $code >= 500 || 429 === $code, $body );
	}

	public function update_subscriber( string $email, array $attributes ): ProviderResult {
		$response = wp_remote_request( 'https://api.brevo.com/v3/contacts/' . rawurlencode( $email ), [
			'method'  => 'PUT',
			'headers' => [
				'api-key'      => $this->apiKey,
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( [ 'attributes' => $attributes ] ),
			'timeout' => 15,
		] );

		return self::result_from_response( $response );
	}

	public function delete_subscriber( string $email ): ProviderResult {
		$response = wp_remote_request( 'https://api.brevo.com/v3/contacts/' . rawurlencode( $email ), [
			'method'  => 'DELETE',
			'headers' => [ 'api-key' => $this->apiKey ],
			'timeout' => 15,
		] );

		return self::result_from_response( $response );
	}

	public function get_subscriber_status( string $email ): SubscriberStatus {
		$response = wp_remote_get( 'https://api.brevo.com/v3/contacts/' . rawurlencode( $email ), [
			'headers' => [ 'api-key' => $this->apiKey ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return SubscriberStatus::UNKNOWN;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['emailBlacklisted'] ) ) {
			return SubscriberStatus::UNSUBSCRIBED;
		}

		return SubscriberStatus::SUBSCRIBED;
	}

	public function test_connection( array $credentials = [] ): bool|\WP_Error {
		$key = $credentials['api_key'] ?? $this->apiKey;
		$res = wp_remote_get( 'https://api.brevo.com/v3/account', [
			'headers' => [ 'api-key' => $key ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( $code !== 200 ) {
			return new \WP_Error( 'brevo_auth_failed', "Brevo API authentication failed with HTTP {$code}" );
		}

		return true;
	}
}
