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

final class LocalDatabaseProvider implements NewsletterProviderInterface {
	public function add_subscriber( SubscriberDTO $subscriber ): ProviderResult {
		// DoiRepository::confirm_token_atomic() already wrote the subscriber row; nothing leaves the site.
		return ProviderResult::success( 'local_' . md5( $subscriber->email ) );
	}

	// Not implemented; nothing calls these yet. Until 2026-09-15 they returned success and "subscribed"
	// without reading or changing anything. Unsubscribing locally is DoiRepository::unsubscribe().
	public function update_subscriber( string $email, array $attributes ): ProviderResult {
		return ProviderResult::failure( 'update_subscriber is not implemented for the local provider.', 'not_implemented' );
	}

	public function delete_subscriber( string $email ): ProviderResult {
		return ProviderResult::failure( 'delete_subscriber is not implemented for the local provider.', 'not_implemented' );
	}

	public function get_subscriber_status( string $email ): SubscriberStatus {
		return SubscriberStatus::UNKNOWN;
	}

	public function test_connection( array $credentials = [] ): bool|\WP_Error {
		return true;
	}
}
