<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Contracts\NewsletterProviderInterface;

final class ProviderFactory {
	public static function create( string $providerId, array $settings ): NewsletterProviderInterface {
		return match ( $providerId ) {
			'brevo'       => new BrevoAdapter( $settings['brevo_api_key'] ?? '' ),
			'rapidmail'   => new RapidmailAdapter( $settings['rapidmail_username'] ?? '', $settings['rapidmail_password'] ?? '' ),
			'cleverreach' => new CleverReachAdapter( $settings['cleverreach_token'] ?? '' ),
			'kit'         => new KitAdapter( $settings['kit_api_key'] ?? '' ),
			'mailchimp'   => new MailchimpAdapter( $settings['mailchimp_api_key'] ?? '' ),
			default       => new LocalDatabaseProvider(),
		};
	}
}
