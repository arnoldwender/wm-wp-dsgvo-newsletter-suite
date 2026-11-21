<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Domain\Enum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum SubscriberStatus: string {
	case SUBSCRIBED   = 'subscribed';
	case UNSUBSCRIBED = 'unsubscribed';
	case CLEANED      = 'cleaned';
	case UNKNOWN      = 'unknown';

	public function label(): string {
		return match ( $this ) {
			self::SUBSCRIBED   => __( 'Aktiv abonniert', 'wm-dsgvo-newsletter-suite' ),
			self::UNSUBSCRIBED => __( 'Abgemeldet', 'wm-dsgvo-newsletter-suite' ),
			self::CLEANED      => __( 'Bereinigt / Gelöscht', 'wm-dsgvo-newsletter-suite' ),
			self::UNKNOWN      => __( 'Unbekannter Status', 'wm-dsgvo-newsletter-suite' ),
		};
	}
}
