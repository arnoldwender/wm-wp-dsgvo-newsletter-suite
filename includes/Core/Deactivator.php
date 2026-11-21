<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Queue\SyncQueue;

final class Deactivator {
	public static function deactivate(): void {
		wp_clear_scheduled_hook( SyncQueue::CLEANUP_HOOK );
		wp_clear_scheduled_hook( SyncQueue::SYNC_HOOK );
	}
}
