<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Domain\DTO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SubscriberDTO {
	public function __construct(
		public readonly string $email,
		public readonly string $blindedIp,
		public readonly string $consentHash,
		public readonly string $consentText,
		public readonly array $attributes = [],
		public readonly array $tags = [],
		public readonly string $listId = 'default',
		public readonly string $provider = 'local',
		public readonly string $legalBasis = 'art_6_1_a',
		public readonly string $formId = 'default',
		// Staging `confirmed_at` and `created_at`, both in the site's clock (current_time( 'mysql' )).
		public readonly ?string $confirmedAt = null,
		public readonly ?string $createdAt = null
	) {}
}
