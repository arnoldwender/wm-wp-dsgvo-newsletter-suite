<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Domain\DTO\SubscriberDTO;
use WMCyber\Newsletter\Domain\DTO\ProviderResult;
use WMCyber\Newsletter\Domain\Enum\SubscriberStatus;

/**
 * Until 2026-09-15 the contract also required getName(), getId() and isEuNative(); nothing called them, and
 * their return values carried data-centre cities and a sovereignty label for the local table, none of
 * them measured.
 */
interface NewsletterProviderInterface {
	public function add_subscriber( SubscriberDTO $subscriber ): ProviderResult;
	public function update_subscriber( string $email, array $attributes ): ProviderResult;
	public function delete_subscriber( string $email ): ProviderResult;
	public function get_subscriber_status( string $email ): SubscriberStatus;
	public function test_connection( array $credentials = [] ): bool|\WP_Error;
}
