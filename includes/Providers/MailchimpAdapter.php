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

final class MailchimpAdapter implements NewsletterProviderInterface {
	private string $apiKey;
	private string $dataCenter;

	public function __construct( string $apiKey ) {
		$this->apiKey = trim( $apiKey );
		if ( str_contains( $this->apiKey, '-' ) ) {
			$parts = explode( '-', $this->apiKey );
			$this->dataCenter = end( $parts );
		} else {
			$this->dataCenter = 'us1';
		}
	}

	public function add_subscriber( SubscriberDTO $subscriber ): ProviderResult {
		$listId = $subscriber->listId;
		if ( empty( $listId ) || $listId === 'default' ) {
			return ProviderResult::failure( 'Mailchimp requires an Audience List ID.' );
		}

		$subscriberHash = md5( strtolower( trim( $subscriber->email ) ) );
		$endpoint       = "https://{$this->dataCenter}.api.mailchimp.com/3.0/lists/" . rawurlencode( $listId ) . "/members/{$subscriberHash}";

		$mergeFields = [];
		if ( ! empty( $subscriber->attributes['FNAME'] ) || ! empty( $subscriber->attributes['firstname'] ) ) {
			$mergeFields['FNAME'] = $subscriber->attributes['FNAME'] ?? $subscriber->attributes['firstname'];
		}
		if ( ! empty( $subscriber->attributes['LNAME'] ) || ! empty( $subscriber->attributes['lastname'] ) ) {
			$mergeFields['LNAME'] = $subscriber->attributes['LNAME'] ?? $subscriber->attributes['lastname'];
		}

		$payload = [
			'email_address' => $subscriber->email,
			'status_if_new' => 'subscribed',
			'status'        => 'subscribed',
			'merge_fields'  => (object) $mergeFields,
		];

		$response = wp_remote_request( $endpoint, [
			'method'  => 'PUT',
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $this->apiKey ),
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
			return ProviderResult::success( (string) ( $body['id'] ?? $subscriberHash ), $body );
		}

		return ProviderResult::failure( (string) ( $body['title'] ?? $body['detail'] ?? "HTTP {$code}" ), $code, $code >= 500 || 429 === $code, $body );
	}

	// Not implemented for Mailchimp; nothing calls these yet. Until 2026-09-15 they returned success and
	// "subscribed" without contacting the provider.
	public function update_subscriber( string $email, array $attributes ): ProviderResult {
		return ProviderResult::failure( 'update_subscriber is not implemented for Mailchimp; nothing was sent.', 'not_implemented' );
	}

	public function delete_subscriber( string $email ): ProviderResult {
		return ProviderResult::failure( 'delete_subscriber is not implemented for Mailchimp; nothing was sent.', 'not_implemented' );
	}

	public function get_subscriber_status( string $email ): SubscriberStatus {
		return SubscriberStatus::UNKNOWN;
	}

	public function test_connection( array $credentials = [] ): bool|\WP_Error {
		$key = $credentials['api_key'] ?? $this->apiKey;
		$dc  = 'us1';
		if ( str_contains( $key, '-' ) ) {
			$parts = explode( '-', $key );
			$dc    = end( $parts );
		}

		$res = wp_remote_get( "https://{$dc}.api.mailchimp.com/3.0/ping", [
			'headers' => [ 'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $key ) ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( $code !== 200 ) {
			return new \WP_Error( 'mailchimp_auth_error', "Mailchimp auth failed with HTTP {$code}" );
		}

		return true;
	}
}
