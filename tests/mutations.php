<?php
/**
 * Mutations for tests/run-mutations.sh: each entry undoes one correction with literal [file, search, replace]
 * replacements. The suite must report at least one failure for every entry. The first 25 undo the fixes of the
 * 2026-09-15 runtime pass (until then inline Perl substitutions in the runner); the D-entries undo the defects of
 * the reader pass of the same day.
 *
 * @package WMCyber\Newsletter
 */

declare(strict_types=1);

return [
	[
		'failure() signature from before (TypeError on an int status)',
		[
			[ 'includes/Domain/DTO/ProviderResult.php', 'int|string|null $code = null, bool $retryable = false, array $raw = []', '?string $code = null, array $raw = []' ],
		],
	],
	[
		'success() signature from before (TypeError on success)',
		[
			[ 'includes/Domain/DTO/ProviderResult.php', '?string $subscriberId = null, array $raw = [], string $message = \'OK\'', 'string $message = \'OK\', ?string $subscriberId = null, array $raw = []' ],
		],
	],
	[
		'five-minute interval not registered',
		[
			[ 'includes/Queue/SyncQueue.php', 'add_filter( \'cron_schedules\', [ self::class, \'register_schedule\' ] );', '' ],
		],
	],
	[
		'address written to the failure log',
		[
			[ 'includes/Queue/SyncQueue.php', '\'[WM-NEWSLETTER-SYNC-FAIL] staging #%d via %s: %s (%s)\', (int) $row[\'id\']', '\'[WM-NEWSLETTER-SYNC-FAIL] staging #%d (\' . $row[\'email\'] . \') via %s: %s (%s)\', (int) $row[\'id\']' ],
		],
	],
	[
		'cutoff in UTC against local created_at',
		[
			[ 'includes/Database/DoiRepository.php', 'return wp_date( \'Y-m-d H:i:s\', ', 'return gmdate( \'Y-m-d H:i:s\', ' ],
		],
	],
	[
		'expired sign-ups kept instead of deleted',
		[
			[ 'includes/Database/DoiRepository.php', '\'DELETE FROM %i WHERE status IN', '\'SELECT * FROM %i WHERE status IN' ],
		],
	],
	[
		'unsubscribed addresses pushed to the provider',
		[
			[ 'includes/Database/DoiRepository.php', 's.status IN (%s, %s) AND ( u.status IS NULL OR u.status = %s )', 's.status IN (%s, %s) AND ( %s = %s )' ],
		],
	],
	[
		'solved challenge replayable',
		[
			[ 'includes/Security/AltchaVerifier.php', 'set_transient( $usedKey, 1, ', 'null && set_transient( $usedKey, 1, ' ],
		],
	],
	[
		'expired challenge accepted',
		[
			[ 'includes/Security/AltchaVerifier.php', '|| (int) $m[1] < time() ', '' ],
		],
	],
	[
		'generated secrets not stored (new webhook key per read)',
		[
			[ 'includes/Admin/SettingsManager.php', 'update_option( self::OPTION_KEY, $stored );', '' ],
		],
	],
	[
		'provider taken from the request',
		[
			[ 'includes/REST/SubscriptionController.php', '$provider    = in_array( $config[\'active_provider\'] ?? \'local\', SettingsManager::PROVIDERS, true ) ? (string) $config[\'active_provider\'] : \'local\';', '$provider    = sanitize_text_field( (string) ( $params[\'provider\'] ?? \'local\' ) );' ],
		],
	],
	[
		'consent not checked on the server',
		[
			[ 'includes/REST/SubscriptionController.php', 'if ( empty( $params[\'consent\'] )', 'if ( false' ],
		],
	],
	[
		'row kept when the mail fails',
		[
			[ 'includes/REST/SubscriptionController.php', '$this->repo->delete_pending( (int) $doiRecord[\'id\'] );', '' ],
		],
	],
	[
		'consent text taken from the request',
		[
			[ 'includes/REST/SubscriptionController.php', '$consentText = FormRenderer::consent_record(', '$consentText = (string) ( $params[\'consent_text\'] ?? \'\' ) . FormRenderer::consent_record(' ],
		],
	],
	[
		'list id accepted without the form signature',
		[
			[ 'includes/REST/SubscriptionController.php', '\'\' === $postedSig || ! hash_equals', 'false && ! hash_equals' ],
		],
	],
	[
		'shield applied to every REST route',
		[
			[ 'includes/Security/RequestShield.php', 'if ( ! str_starts_with( strtolower( (string) $request->get_route() ), self::ROUTE_PREFIX ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'legal badge back in the form',
		[
			[ 'includes/Frontend/FormRenderer.php', '<section class="wm-newsletter-box"', '<div class="wm-nl-badge">100% DSGVO-Konform · Zero-Client-Leak</div><section class="wm-newsletter-box"' ],
		],
	],
	[
		'health value the hub rejects',
		[
			[ 'includes/Adapters/Newsletter_Spoke_Adapter.php', "return 'DEGRADED';\n\t\t}\n\t\treturn 'HEALTHY';", "return 'WARNING';\n\t\t}\n\t\treturn 'HEALTHY';" ],
		],
	],
	[
		'settings link to a missing page',
		[
			[ 'includes/Adapters/Newsletter_Spoke_Adapter.php', 'page=wm-newsletter-suite&tab=settings', 'page=wm-newsletter-settings' ],
		],
	],
	[
		'CSV formula cells not neutralised',
		[
			[ 'includes/Admin/AdminDashboard.php', '? "\'" . $value : $value;', '? $value : $value;' ],
		],
	],
	[
		'widget counts the wrong status',
		[
			[ 'includes/Admin/AdminDashboard.php', "(int) \$stats['active_subscribers'] ) ); ?></div>\n\t\t\t\t\t<div class=\"wm-dash-kpi-lbl\"><?php esc_html_e( 'Abonniert'", "(int) \$stats['pending_doi'] ) ); ?></div>\n\t\t\t\t\t<div class=\"wm-dash-kpi-lbl\"><?php esc_html_e( 'Abonniert'" ],
		],
	],
	[
		'mail promises a fixed 48 hours',
		[
			[ 'includes/Mailer/DoiMailer.php', '$ttlHours         = $this->settings->ttl_hours();', '$ttlHours         = 48;' ],
		],
	],
	[
		'Brevo delete reports success on 401',
		[
			[ 'includes/Providers/BrevoAdapter.php', 'if ( $code >= 200 && $code < 300 ) {', 'if ( true ) {' ],
		],
	],
	[
		'uninstall clears invented hook names',
		[
			[ 'uninstall.php', '\'wm_newsletter_process_sync_queue\', \'wm_newsletter_hourly_cleanup\'', '\'wm_newsletter_hourly_sync\', \'wm_newsletter_cleanup_expired\'' ],
		],
	],
	[
		'form sends no proof of work',
		[
			[ 'assets/js/frontend-form.js', 'altcha: altcha', 'altcha: \'\'' ],
		],
	],
	[
		'D1: unsubscribe link with the raw address (plus addresses answer 403)',
		[
			[ 'includes/Mailer/DoiMailer.php', '\'email\' => rawurlencode( $email ),', '\'email\' => $email,' ],
		],
	],
	[
		'D2: shortcode default list back to the literal \'default\'',
		[
			[ 'includes/Frontend/FormRenderer.php', '\'list_id\'     => \'\',', '\'list_id\'     => \'default\',' ],
		],
	],
	[
		'D2: posted empty or \'default\' list stored instead of the configured one',
		[
			[ 'includes/REST/SubscriptionController.php', '$listId = ( \'\' === $postedList || \'default\' === $postedList ) ? $configuredList : $postedList;', '$listId = $postedList;' ],
		],
	],
	[
		'D3: sync_failed counts held rows of unsubscribed addresses again',
		[
			[ 'includes/Database/DoiRepository.php', '\'sync_failed\'        => $sendable( DoiState::SYNC_FAILED->value ),', '\'sync_failed\'        => (int) $wpdb->get_var( $wpdb->prepare( \'SELECT COUNT(*) FROM %i WHERE status = %s\', $this->stagingTable, DoiState::SYNC_FAILED->value ) ),' ],
		],
	],
	[
		'D3: hub failure count includes held rows',
		[
			[ 'includes/Adapters/Newsletter_Spoke_Adapter.php', '\'failed_esp_syncs\'      => $count( \'sync_failed\' ),', '\'failed_esp_syncs\'      => $present ? $count( \'sync_failed\' ) + $count( \'held_unsubscribed\' ) : null,' ],
		],
	],
	[
		'D4: local pushes counted as external in the run',
		[
			[ 'includes/Queue/SyncQueue.php', '$this->lastRun[ \'local\' === $providerId ? \'local\' : \'external\' ]++;', '$this->lastRun[\'external\']++;' ],
		],
	],
	[
		'D4: synced_to_provider counts local rows',
		[
			[ 'includes/Database/DoiRepository.php', '\'SELECT COUNT(*) FROM %i WHERE status = %s AND provider_adapter <> %s\', $this->stagingTable, DoiState::SYNCED_TO_PROVIDER->value, \'local\' )', '\'SELECT COUNT(*) FROM %i WHERE status = %s\', $this->stagingTable, DoiState::SYNCED_TO_PROVIDER->value )' ],
		],
	],
	[
		'D4: KPI shows \'An den Anbieter übergeben\' with the provider local',
		[
			[ 'includes/Admin/AdminDashboard.php', '<?php if ( \'local\' === (string) $settings[\'active_provider\'] ) : ?>', '<?php if ( false ) : ?>' ],
		],
	],
	[
		'D4: sync notice drops the local rows',
		[
			[ 'includes/Admin/AdminDashboard.php', 'if ( $local > 0 ) {', 'if ( false ) {' ],
		],
	],
	[
		'D5: every write restarts the window (sliding, as before)',
		[
			[ 'includes/Security/RateLimiter.php', 'set_transient( $key, $state, $left );', 'set_transient( $key, [ \'start\' => $now, \'count\' => $state[\'count\'] ], $windowSeconds );' ],
		],
	],
	[
		'D5: refused requests extend the window',
		[
			[ 'includes/Security/RateLimiter.php', "if ( (int) \$state['count'] >= \$limit ) {", "if ( (int) \$state['count'] >= \$limit ) {\n\t\t\tset_transient( \$key, [ 'start' => \$now, 'count' => (int) \$state['count'] ], \$windowSeconds );" ],
		],
	],
	[
		'D6: route compared case-sensitively',
		[
			[ 'includes/Security/RequestShield.php', 'strtolower( (string) $request->get_route() )', '(string) $request->get_route()' ],
		],
	],
	[
		'D7: mail sent although nothing was stored',
		[
			[ 'includes/REST/SubscriptionController.php', 'if ( empty( $doiRecord[\'id\'] ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D7: failed insert not detected, older pending row deleted',
		[
			[ 'includes/Database/DoiRepository.php', 'if ( false === $inserted || $newId < 1 ) {', 'if ( false ) {' ],
		],
	],
	[
		'D8: re-click on a SYNC_FAILED row answers 410',
		[
			[ 'includes/Database/DoiRepository.php', 'DoiState::SYNCED_TO_PROVIDER->value, DoiState::SYNC_FAILED->value ], true ) ) {', 'DoiState::SYNCED_TO_PROVIDER->value ], true ) ) {' ],
		],
	],
	[
		'D9: CleverReach registered is the push time',
		[
			[ 'includes/Providers/CleverReachAdapter.php', '\'registered\' => $registered,', '\'registered\' => time(),' ],
		],
	],
	[
		'D9: queue does not pass confirmed_at',
		[
			[ 'includes/Queue/SyncQueue.php', 'confirmedAt: isset( $row[\'confirmed_at\'] ) ? (string) $row[\'confirmed_at\'] : null,', 'confirmedAt: null,' ],
		],
	],
	[
		'D9: CleverReach pushed without consent times',
		[
			[ 'includes/Providers/CleverReachAdapter.php', 'if ( null === $registered || null === $activated ) {', 'if ( false ) {' ],
		],
	],
	[
		'D10: integration test runs without the disposable-install flag',
		[
			[ 'tests/test-newsletter-suite.php', 'if ( \'1\' !== getenv( \'WM_NL_INTEGRATION_DISPOSABLE\' ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D10: integration purge runs although other rows would be deleted',
		[
			[ 'tests/test-newsletter-suite.php', 'if ( 0 === $foreign_expired ) {', 'if ( true ) {' ],
		],
	],
	[
		'D11: provider name with a sovereignty claim back',
		[
			[ 'includes/Providers/LocalDatabaseProvider.php', "final class LocalDatabaseProvider implements NewsletterProviderInterface {", "final class LocalDatabaseProvider implements NewsletterProviderInterface {\n\tpublic function getName(): string {\n\t\treturn 'Local Sovereign Database (Zero-Cloud)';\n\t}\n" ],
		],
	],
	[
		'D12: array email reaches sanitize_email()',
		[
			[ 'includes/REST/WebhookController.php', 'if ( is_string( $params[\'email\'] ?? null ) && \'\' !== $params[\'email\'] ) {', 'if ( ! empty( $params[\'email\'] ) ) {' ],
		],
	],
	[
		'D12: Brevo spam and contact_deleted ignored',
		[
			[ 'includes/REST/WebhookController.php', '\'unsubscribe\', \'spam\', \'contact_deleted\', \'unsub\'', '\'unsubscribe\', \'unsub\'' ],
		],
	],
	[
		'v1.1: public REST route falls back to __return_true',
		[
			[ 'includes/REST/SubscriptionController.php', "'callback'            => [ \$this, 'handle_subscribe' ],\n\t\t\t'permission_callback' => [ \$this, 'check_public_subscription_access' ],", "'callback'            => [ \$this, 'handle_subscribe' ],\n\t\t\t'permission_callback' => '__return_true'," ],
		],
	],
	[
		'v1.1: REST permission callback ignores wm_newsletter_rest_rate_limit filter',
		[
			[ 'includes/REST/SubscriptionController.php', '$limit = (int) apply_filters( \'wm_newsletter_rest_rate_limit\', 60, $request );', '$limit = 60;' ],
		],
	],
	[
		'v1.1: REST permission callback stores raw IP in transient key',
		[
			[ 'includes/REST/SubscriptionController.php', '$key    = \'wm_nl_rl_rest_\' . substr( $ipHash, 0, 32 );', '$key    = \'wm_nl_rl_rest_\' . self::client_ip();' ],
		],
	],
	[
		'v1.1: uninstall drops tables and options without opt-in',
		[
			[ 'uninstall.php', 'if ( ! $delete_data ) {', 'if ( false ) {' ],
		],
	],
	[
		'v1.1: uninstall setting not registered on admin_init',
		[
			[ 'includes/Admin/AdminDashboard.php', 'add_action( \'admin_init\', [ $this->settings, \'register_settings\' ] );', '' ],
		],
	],
	[
		'v1.1: wm_newsletter_confirmed action not fired on confirmation',
		[
			[ 'includes/Database/DoiRepository.php', 'do_action( \'wm_newsletter_confirmed\', (string) $row[\'email\'], $row );', '' ],
		],
	],
	[
		'v1.1: wm_newsletter_subscriber_payload filter bypassed in queue',
		[
			[ 'includes/Queue/SyncQueue.php', '\'wm_newsletter_subscriber_payload\',', '\'wm_newsletter_unused_filter\',' ],
		],
	],
	[
		'v1.1: Block Bindings source not registered',
		[
			[ 'includes/Frontend/EcosystemIntegrations.php', "register_block_bindings_source(\n\t\t\t\t\t'wm-newsletter/stats',", "register_block_bindings_source(\n\t\t\t\t\t'wm-newsletter/broken'," ],
		],
	],
	[
		'v1.1: GenerateBlocks Dynamic Tag not registered',
		[
			[ 'includes/Frontend/EcosystemIntegrations.php', '\'tag\'      => \'wm_newsletter_stat\',', '\'tag\'      => \'wm_broken_tag\',' ],
		],
	],
	[
		'v1.1.1: wp_initialize_site hook omitted from main plugin file',
		[
			[ 'wm-dsgvo-newsletter-suite.php', "add_action( 'wp_initialize_site', function ( \$new_site ): void {", "false && add_action( 'wp_initialize_site', function ( \$new_site ): void {" ],
		],
	],
	[
		'v1.1.1: wp_initialize_site skips is_plugin_active_for_network guard',
		[
			[ 'wm-dsgvo-newsletter-suite.php', "if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'v1.1.1: wp_initialize_site omits switch_to_blog',
		[
			[ 'wm-dsgvo-newsletter-suite.php', 'switch_to_blog( (int) $new_site->blog_id );', '// switch_to_blog skipped.' ],
		],
	],
	[
		'v1.1.1: wp_initialize_site omits finally restore_current_blog',
		[
			[ 'wm-dsgvo-newsletter-suite.php', "\ttry {\n\t\tCore\\Activator::activate();\n\t} finally {\n\t\trestore_current_blog();\n\t}", "\tCore\\Activator::activate();\n\trestore_current_blog();" ],
		],
	],
	[
		'v1.1.1: Activator::activate ignores $network_wide = true',
		[
			[ 'includes/Core/Activator.php', "if ( \$network_wide && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'v1.1.1: Activator stores default settings without autoload=false',
		[
			[ 'includes/Core/Activator.php', "\t\t\t\t'doi_email_subject'   => __( 'Bitte bestätigen Sie Ihre Anmeldung', 'wm-dsgvo-newsletter-suite' ),\n\t\t\t], false );", "\t\t\t\t'doi_email_subject'   => __( 'Bitte bestätigen Sie Ihre Anmeldung', 'wm-dsgvo-newsletter-suite' ),\n\t\t\t] );" ],
		],
	],
	[
		'v1.1.1: SettingsManager::update_all stores settings without autoload=false',
		[
			[ 'includes/Admin/SettingsManager.php', 'return update_option( self::OPTION_KEY, $updated, false );', 'return update_option( self::OPTION_KEY, $updated );' ],
		],
	],
];
