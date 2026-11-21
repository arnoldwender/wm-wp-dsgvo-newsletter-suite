<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Database\DoiRepository;
use WMCyber\Newsletter\Mailer\DoiMailer;
use WMCyber\Newsletter\Queue\SyncQueue;
use WMCyber\Newsletter\Admin\SettingsManager;
use WMCyber\Newsletter\Security\RateLimiter;
use WMCyber\Newsletter\Security\AltchaVerifier;
use WMCyber\Newsletter\Security\ErrorSanitizer;
use WMCyber\Newsletter\Frontend\FormRenderer;
use WP_REST_Request;
use WP_REST_Response;

final class SubscriptionController {
	private DoiRepository $repo;
	private DoiMailer $mailer;
	private SyncQueue $queue;
	private SettingsManager $settings;

	public function __construct( DoiRepository $repo, DoiMailer $mailer, SyncQueue $queue, SettingsManager $settings ) {
		$this->repo     = $repo;
		$this->mailer   = $mailer;
		$this->queue    = $queue;
		$this->settings = $settings;
	}

	public function register_routes(): void {
		// 1. Subscribe endpoint (Public Zero-Client-Leak)
		register_rest_route( 'wm-newsletter/v1', '/subscribe', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_subscribe' ],
			'permission_callback' => [ $this, 'check_public_subscription_access' ],
		] );

		// 2. Altcha Challenge endpoint
		register_rest_route( 'wm-newsletter/v1', '/altcha-challenge', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_altcha_challenge' ],
			'permission_callback' => [ $this, 'check_public_subscription_access' ],
		] );

		// 3. RFC 8058 One-Click Unsubscribe endpoint
		register_rest_route( 'wm-newsletter/v1', '/unsubscribe', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ $this, 'handle_unsubscribe' ],
			'permission_callback' => [ $this, 'check_public_subscription_access' ],
		] );
	}

	/**
	 * Public REST permission callback with transient-based IP HMAC-SHA256 rate limiting.
	 *
	 * Never stores raw IP addresses. Administrators with `manage_options` bypass the rate limit.
	 */
	public function check_public_subscription_access( WP_REST_Request $request ): bool|\WP_Error {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$limit = (int) apply_filters( 'wm_newsletter_rest_rate_limit', 60, $request );
		if ( $limit < 1 ) {
			return new \WP_Error(
				'wm_newsletter_rate_limited',
				__( 'Zu viele Anfragen. Bitte warten Sie einen Moment.', 'wm-dsgvo-newsletter-suite' ),
				[ 'status' => 429 ]
			);
		}

		$salt   = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : wp_salt( 'auth' );
		$ipHash = hash_hmac( 'sha256', 'rest_access|' . self::client_ip(), $salt );
		$key    = 'wm_nl_rl_rest_' . substr( $ipHash, 0, 32 );
		$now    = time();
		$window = 60;

		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] ) || ( $now - (int) $state['start'] ) >= $window ) {
			$state = [
				'start' => $now,
				'count' => 0,
			];
		}

		$left = max( 1, (int) $state['start'] + $window - $now );
		if ( (int) $state['count'] >= $limit ) {
			return new \WP_Error(
				'wm_newsletter_rate_limited',
				__( 'Zu viele Anfragen. Bitte warten Sie einen Moment.', 'wm-dsgvo-newsletter-suite' ),
				[
					'status'      => 429,
					'retry_after' => $left,
				]
			);
		}

		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, $left );

		return true;
	}

	/** Client address as PHP received it; only its keyed hash is stored. */
	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
	}

	/**
	 * List and legal basis as configured in the shortcode, bound by its signature.
	 *
	 * When either is posted the signature has to match and the basis has to be known; null means refuse.
	 * An empty list, or the literal 'default' that pages cached before 2026-09-15 still post, stands for
	 * the list in the settings: until that day the stored list was 'default', which Rapidmail, CleverReach
	 * and Mailchimp refuse before any request and Brevo ignores.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function resolve_list_and_basis( array $params, array $config ): ?array {
		$configuredList = (string) ( $config['default_list_id'] ?? 'default' );
		$postedList     = sanitize_text_field( is_scalar( $params['list_id'] ?? null ) ? (string) $params['list_id'] : '' );
		$postedBasis    = sanitize_key( is_scalar( $params['legal_basis'] ?? null ) ? (string) $params['legal_basis'] : '' );
		$postedSig      = sanitize_text_field( is_scalar( $params['form_sig'] ?? null ) ? (string) $params['form_sig'] : '' );

		if ( '' === $postedList && '' === $postedBasis ) {
			return [ $configuredList, 'art_6_1_a' ];
		}
		if ( '' === $postedSig || ! hash_equals( FormRenderer::form_signature( $postedList, $postedBasis ), $postedSig ) || ! in_array( $postedBasis, FormRenderer::LEGAL_BASES, true ) ) {
			return null;
		}
		$listId = ( '' === $postedList || 'default' === $postedList ) ? $configuredList : $postedList;
		return [ $listId, $postedBasis ];
	}

	/**
	 * Until 2026-09-15 the provider, the consent text, the legal basis, the list and the form id were all
	 * taken from the request: any caller could store a consent text of its choosing as the evidence,
	 * route an address to another provider, or subscribe it to any list; the consent checkbox was not
	 * checked on the server at all. Now the provider comes from the settings, the consent text is the
	 * sentence the form prints, and list and legal basis are accepted only with the form's signature.
	 */
	public function handle_subscribe( WP_REST_Request $request ): WP_REST_Response {
		$ip = self::client_ip();

		// 1. Rate Limiting (5 attempts / 60 seconds)
		$rl = RateLimiter::check( 'subscribe', 5, 60, $ip );
		if ( ! $rl['allowed'] ) {
			return new WP_REST_Response( [
				'code'        => 'rate_limit_exceeded',
				'message'     => __( 'Zu viele Anfragen. Bitte warten Sie einen Moment.', 'wm-dsgvo-newsletter-suite' ),
				'retry_after' => $rl['retry_after'],
			], 429 );
		}

		$params = $request->get_json_params() ?: $request->get_params();

		// 2. Semantic Honeypot Check
		if ( ! empty( $params['wm_hp_name'] ) || ! empty( $params['website_field'] ) ) {
			// Silently accept without processing to fool bots
			return new WP_REST_Response( [
				'success' => true,
				'message' => __( 'Vielen Dank für Ihre Anmeldung! Bitte prüfen Sie Ihr Postfach.', 'wm-dsgvo-newsletter-suite' ),
			], 200 );
		}

		// 3. Altcha PoW Verification
		$config = $this->settings->get_all();
		if ( ! empty( $config['altcha_enabled'] ) ) {
			$altchaPayload = (string) ( $params['altcha'] ?? '' );
			$hmacKey       = (string) ( $config['altcha_hmac_key'] ?? '' );

			if ( empty( $altchaPayload ) || ! AltchaVerifier::verify( $altchaPayload, $hmacKey ) ) {
				return new WP_REST_Response( [
					'code'    => 'invalid_captcha',
					'message' => __( 'Sicherheitsüberprüfung fehlgeschlagen (Proof-of-Work ungültig).', 'wm-dsgvo-newsletter-suite' ),
				], 403 );
			}
		}

		// 4. Validate Inputs
		$email = sanitize_email( (string) ( $params['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return new WP_REST_Response( [
				'code'    => 'invalid_email',
				'message' => __( 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'wm-dsgvo-newsletter-suite' ),
			], 400 );
		}

		if ( empty( $params['consent'] ) || in_array( $params['consent'], [ 'false', '0', 0, false ], true ) ) {
			return new WP_REST_Response( [
				'code'    => 'consent_required',
				'message' => __( 'Bitte bestätigen Sie die Einwilligung.', 'wm-dsgvo-newsletter-suite' ),
			], 400 );
		}

		$listAndBasis = self::resolve_list_and_basis( $params, $config );
		if ( null === $listAndBasis ) {
			return new WP_REST_Response( [
				'code'    => 'invalid_form',
				'message' => __( 'Das Formular ist ungültig. Bitte laden Sie die Seite neu.', 'wm-dsgvo-newsletter-suite' ),
			], 400 );
		}
		[ $listId, $legalBasis ] = $listAndBasis;

		$consentText = FormRenderer::consent_record( (string) ( $config['privacy_policy_url'] ?: home_url( '/datenschutz/' ) ) );
		$provider    = in_array( $config['active_provider'] ?? 'local', SettingsManager::PROVIDERS, true ) ? (string) $config['active_provider'] : 'local';
		$formId      = substr( sanitize_key( (string) ( $params['form_id'] ?? 'default' ) ), 0, 50 ) ?: 'default';
		// The form sends neither; kept for API callers, bounded to what the columns and providers accept.
		$attributes  = is_array( $params['attributes'] ?? null ) ? array_slice( array_map( static fn( $v ) => substr( sanitize_text_field( (string) $v ), 0, 100 ), $params['attributes'] ), 0, 10, true ) : [];
		$tags        = is_array( $params['tags'] ?? null ) ? array_slice( array_map( static fn( $v ) => substr( sanitize_text_field( (string) $v ), 0, 100 ), $params['tags'] ), 0, 10 ) : [];

		try {
			// 5. Store pending DOI in staging ledger
			$doiRecord = $this->repo->create_pending_doi(
				$email,
				$ip,
				$consentText,
				$attributes,
				$tags,
				$listId,
				$provider,
				$legalBasis,
				$formId
			);

			// No row, no mail: until 2026-09-15 a refused insert still mailed a link that answered 404, and the
			// visitor was told to check the mailbox.
			if ( empty( $doiRecord['id'] ) ) {
				return new WP_REST_Response( [
					'code'    => 'storage_failed',
					'message' => __( 'Die Anmeldung konnte nicht gespeichert werden. Bitte versuchen Sie es später.', 'wm-dsgvo-newsletter-suite' ),
				], 500 );
			}

			// 6. Send transactional DOI mail
			$sent = $this->mailer->send_doi_confirmation( $email, $doiRecord['plain_token'] );

			if ( ! $sent ) {
				// Nobody can confirm without the mail; keeping the row would only store the address.
				$this->repo->delete_pending( (int) $doiRecord['id'] );
				return new WP_REST_Response( [
					'code'    => 'mail_delivery_failed',
					'message' => __( 'Die Bestätigungs-E-Mail konnte nicht versendet werden. Bitte versuchen Sie es später.', 'wm-dsgvo-newsletter-suite' ),
				], 500 );
			}

			return new WP_REST_Response( [
				'success' => true,
				'message' => __( 'Fast geschafft! Wir haben Ihnen einen Bestätigungslink per E-Mail gesendet.', 'wm-dsgvo-newsletter-suite' ),
			], 200 );

		} catch ( \Throwable $e ) {
			$sanitized = ErrorSanitizer::handle( $e, 'REST_SUBSCRIBE' );
			return new WP_REST_Response( [
				'code'    => 'system_error',
				'message' => $sanitized['safe_message'],
			], 500 );
		}
	}

	public function get_altcha_challenge(): WP_REST_Response {
		$config = $this->settings->get_all();
		$chal   = AltchaVerifier::create_challenge( (string) $config['altcha_hmac_key'] );

		$response = new WP_REST_Response( $chal, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public function handle_unsubscribe( WP_REST_Request $request ): WP_REST_Response {
		// Public by design (RFC 8058 one-click from mail clients), so the gate is the
		// per-subscriber token from the mail plus a per-client rate limit.
		$ip = self::client_ip();
		$rl = RateLimiter::check( 'unsubscribe', 10, 60, $ip );
		if ( ! $rl['allowed'] ) {
			return new WP_REST_Response( [
				'code'        => 'rate_limit_exceeded',
				'message'     => __( 'Zu viele Anfragen. Bitte warten Sie einen Moment.', 'wm-dsgvo-newsletter-suite' ),
				'retry_after' => $rl['retry_after'],
			], 429 );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_REST_Response( [ 'code' => 'missing_email', 'message' => __( 'Bitte geben Sie eine gültige E-Mail-Adresse an.', 'wm-dsgvo-newsletter-suite' ) ], 400 );
		}

		// Before this check anyone who knew an address could unsubscribe it (fleet audit 2026-09-13 §6.2 #9).
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( '' === $token || ! hash_equals( DoiRepository::unsubscribe_token( $email ), $token ) ) {
			return new WP_REST_Response( [
				'code'    => 'invalid_token',
				'message' => __( 'Der Abmeldelink ist ungültig oder abgelaufen. Bitte nutzen Sie den Link aus Ihrer E-Mail.', 'wm-dsgvo-newsletter-suite' ),
			], 403 );
		}

		$this->repo->unsubscribe( $email );

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Sie wurden erfolgreich vom Newsletter abgemeldet.', 'wm-dsgvo-newsletter-suite' ),
		], 200 );
	}
}
