<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Database\DoiRepository;
use WMCyber\Newsletter\Admin\SettingsManager;
use WP_REST_Request;
use WP_REST_Response;

final class WebhookController {
	private DoiRepository $repo;
	private SettingsManager $settings;

	public function __construct( DoiRepository $repo, SettingsManager $settings ) {
		$this->repo     = $repo;
		$this->settings = $settings;
	}

	public function register_routes(): void {
		register_rest_route( 'wm-newsletter/v1', '/webhook', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ $this, 'handle_remote_webhook' ],
			'permission_callback' => [ $this, 'verify_webhook_permission' ],
		] );
	}

	public function verify_webhook_permission( WP_REST_Request $request ): bool {
		$config    = $this->settings->get_all();
		$secretKey = (string) ( $config['webhook_secret'] ?? '' );

		if ( empty( $secretKey ) ) {
			return false;
		}

		// Check GET parameter secret
		$tokenParam = (string) $request->get_param( 'key' );
		if ( ! empty( $tokenParam ) && hash_equals( $secretKey, $tokenParam ) ) {
			return true;
		}

		// Check Bearer token in Authorization header
		$authHeader = (string) $request->get_header( 'authorization' );
		if ( str_starts_with( $authHeader, 'Bearer ' ) ) {
			$bearer = substr( $authHeader, 7 );
			if ( hash_equals( $secretKey, $bearer ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Events that set the local row to unsubscribed. `unsubscribe`, `spam` and `contact_deleted` are the names
	 * in Brevo's marketing webhook documentation (developers.brevo.com/docs/marketing-webhooks, read
	 * 2026-09-15); until that day `spam` and `contact_deleted` were missing, so Brevo complaints and deletions
	 * changed nothing here. The other names were accepted before and stay.
	 */
	public const UNSUBSCRIBE_EVENTS = [ 'unsubscribe', 'spam', 'contact_deleted', 'unsub', 'complaint', 'deleted' ];

	public function handle_remote_webhook( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_params();
		$email  = '';

		// Only strings: an array in `email` reached sanitize_email() and ended in a TypeError (500) until 2026-09-15.
		$data = is_array( $params['data'] ?? null ) ? $params['data'] : [];
		if ( is_string( $params['email'] ?? null ) && '' !== $params['email'] ) {
			// Brevo format
			$email = sanitize_email( $params['email'] );
		} elseif ( is_string( $data['email'] ?? null ) && '' !== $data['email'] ) {
			// Mailchimp format
			$email = sanitize_email( $data['email'] );
		}

		if ( ! empty( $email ) && is_email( $email ) ) {
			$event = $params['event'] ?? $params['type'] ?? 'unsubscribe';
			if ( is_string( $event ) && in_array( $event, self::UNSUBSCRIBE_EVENTS, true ) ) {
				$this->repo->unsubscribe( $email );
			}
		}

		return new WP_REST_Response( [ 'status' => 'received' ], 200 );
	}
}
