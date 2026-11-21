<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Domain\Enum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum DoiState: string {
	case PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
	case CONFIRMED            = 'CONFIRMED';
	case SYNCED_TO_PROVIDER   = 'SYNCED_TO_PROVIDER';
	case SYNC_FAILED          = 'SYNC_FAILED';
	case EXPIRED              = 'EXPIRED';
	case PURGED               = 'PURGED';

	// label() and badge_color() were removed on 2026-09-15: nothing called them, and the colours were raw hex
	// outside the admin token sheet. The admin screen prints the status value and a family status pill.
}
