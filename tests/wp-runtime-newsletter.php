<?php
/**
 * Runtime check inside a real WordPress and MySQL for the defects fixed on 2026-09-15 (reader pass D1–D12):
 * core add_query_arg() and query parsing, the shortcode and list resolution, the counts against the real
 * tables, the REST shield on a mixed-case route, the CleverReach payload in the site's time zone, a refused
 * insert, the webhook with an array address, the removed dead code, and the hub adapter.
 *
 *   wp eval-file tests/wp-runtime-newsletter.php --user=1
 *
 * Read-only: no row is written. The refused insert is produced with core's `query` filter (an empty query
 * makes wpdb return false before MySQL sees it), HTTP is answered by a `pre_http_request` filter, and no
 * route that writes transients is dispatched. Exit 1 (WP_CLI::error) on failure.
 *
 * `wp eval-file` runs the file inside a method: state lives in $GLOBALS, and there is no strict_types
 * declaration because one after the docblock is fatal there.
 *
 * @package WMCyber\Newsletter
 */

defined( 'ABSPATH' ) || exit; // the local install serves the plugin folder over HTTP; this file is for wp eval-file only.

use WMCyber\Newsletter\Admin\AdminDashboard;
use WMCyber\Newsletter\Admin\SettingsManager;
use WMCyber\Newsletter\Core\Plugin;
use WMCyber\Newsletter\Database\DoiRepository;
use WMCyber\Newsletter\Database\Schema;
use WMCyber\Newsletter\Domain\DTO\SubscriberDTO;
use WMCyber\Newsletter\Frontend\FormRenderer;
use WMCyber\Newsletter\Mailer\DoiMailer;
use WMCyber\Newsletter\Providers\ProviderFactory;
use WMCyber\Newsletter\REST\SubscriptionController;
use WMCyber\Newsletter\REST\WebhookController;
use WMCyber\Newsletter\Security\RequestShield;

$GLOBALS['wm_nl_rt'] = [ 'pass' => 0, 'fail' => 0 ];

function wm_nl_rt_check( string $name, bool $ok, string $details = '' ): void {
	$GLOBALS['wm_nl_rt'][ $ok ? 'pass' : 'fail' ]++;
	WP_CLI::log( ( $ok ? '[PASS] ' : '[FAIL] ' ) . $name . ( $ok || '' === $details ? '' : ': ' . $details ) );
}

if ( ! class_exists( Plugin::class ) ) {
	WP_CLI::error( 'wm-dsgvo-newsletter-suite is not loaded.' );
}
if ( ! current_user_can( 'manage_options' ) ) {
	WP_CLI::error( 'Run with --user=<an administrator>.' );
}

global $wpdb;
$staging     = Schema::get_staging_table_name();
$subscribers = Schema::get_subscribers_table_name();
$settings    = new SettingsManager();
$config      = $settings->get_all();
$repo        = new DoiRepository();

wm_nl_rt_check( 'both tables exist', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $staging ) ) ) === $staging && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $subscribers ) ) ) === $subscribers );

// D1: the unsubscribe link of a plus address, through core add_query_arg() and the parsing PHP applies to $_GET.
$plus = 'anna+runtime@example.invalid';
wp_parse_str( (string) wp_parse_url( DoiMailer::build_unsubscribe_url( $plus ), PHP_URL_QUERY ), $link_query );
$parsed_email = sanitize_email( (string) ( $link_query['email'] ?? '' ) );
wm_nl_rt_check( 'D1: a plus address survives the link and its token matches', $plus === $parsed_email && hash_equals( DoiRepository::unsubscribe_token( $parsed_email ), (string) ( $link_query['token'] ?? '' ) ), 'email read back as ' . $parsed_email );

// D2: the form without list_id posts an empty signed list, which resolves to the list in the settings.
$form = ( new FormRenderer( $settings ) )->render_shortcode( [] );
preg_match( '/name="list_id" value="([^"]*)"/', $form, $f_list );
preg_match( '/name="legal_basis" value="([^"]*)"/', $form, $f_basis );
preg_match( '/name="form_sig" value="([^"]*)"/', $form, $f_sig );
$configured = (string) ( $config['default_list_id'] ?? 'default' );
$resolved   = SubscriptionController::resolve_list_and_basis( [ 'list_id' => $f_list[1] ?? null, 'legal_basis' => $f_basis[1] ?? '', 'form_sig' => $f_sig[1] ?? '' ], $config );
$cached     = SubscriptionController::resolve_list_and_basis( [ 'list_id' => 'default', 'legal_basis' => 'art_6_1_a', 'form_sig' => FormRenderer::form_signature( 'default', 'art_6_1_a' ) ], $config );
$unsigned   = SubscriptionController::resolve_list_and_basis( [ 'list_id' => 'vip', 'legal_basis' => 'art_6_1_a' ], $config );
wm_nl_rt_check( 'D2: the shortcode default resolves to default_list_id, also a cached "default"; an unsigned list is refused', '' === ( $f_list[1] ?? null ) && [ $configured, 'art_6_1_a' ] === $resolved && [ $configured, 'art_6_1_a' ] === $cached && null === $unsigned, wp_json_encode( [ $f_list[1] ?? null, $resolved, $cached ] ) );

// D3 and D4: the counts against the real tables, compared with plain SQL.
$wpdb->last_error = '';
$stats            = $repo->get_stats();
$stats_error      = $wpdb->last_error;
$open_rows        = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s)', $staging, 'CONFIRMED', 'SYNC_FAILED' ) );
$synced_rows      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $staging, 'SYNCED_TO_PROVIDER' ) );
$queue_size       = count( $repo->get_pending_sync_queue( 100000 ) );
WP_CLI::log( 'stats ' . wp_json_encode( $stats ) . " | open rows {$open_rows} | synced rows {$synced_rows} | queue {$queue_size}" );
wm_nl_rt_check( 'D3: waiting + failed + held equals the CONFIRMED and SYNC_FAILED rows, and waiting + failed equals the queue', '' === $stats_error && $stats['waiting_for_sync'] + $stats['sync_failed'] + $stats['held_unsubscribed'] === $open_rows && $stats['waiting_for_sync'] + $stats['sync_failed'] === $queue_size, $stats_error );
wm_nl_rt_check( 'D4: handed over + kept local equals the SYNCED_TO_PROVIDER rows', $stats['synced_to_provider'] + $stats['kept_local'] === $synced_rows );
$notice = AdminDashboard::sync_notice( 0, 2, 0 );
wm_nl_rt_check( 'D4: the sync notice names local rows as local', str_contains( $notice, 'nur in der lokalen Tabelle' ) && ! str_contains( $notice, 'an den Anbieter übergeben' ) );

// D6: a mixed-case route with a stream wrapper is refused by the shield inside real REST dispatch.
$shield_req = new WP_REST_Request( 'POST', '/WM-Newsletter/v1/subscribe' );
$shield_req->set_param( 'email', 'php://filter/resource=wp-config.php' );
$direct     = RequestShield::guard_rest_request( null, rest_get_server(), $shield_req );
$dispatched = rest_do_request( $shield_req );
wm_nl_rt_check( 'D6: /WM-Newsletter/v1/subscribe with php:// answers 400 wm_security_shield_blocked', $direct instanceof WP_Error && 400 === $dispatched->get_status() && 'wm_security_shield_blocked' === ( $dispatched->as_error() ? $dispatched->as_error()->get_error_code() : '' ), (string) $dispatched->get_status() );

// D7: an insert MySQL never receives returns id 0, and the older pending rows are not deleted.
$seen_queries = [];
$block_insert = static function ( $query ) use ( $staging, &$seen_queries ) {
	$seen_queries[] = (string) $query;
	return str_starts_with( ltrim( (string) $query ), 'INSERT INTO `' . $staging . '`' ) ? '' : $query;
};
add_filter( 'query', $block_insert );
$refused = $repo->create_pending_doi( 'runtime-refused@example.invalid', '198.51.100.1', 'runtime check, never stored' );
remove_filter( 'query', $block_insert );
$deletes = array_filter( $seen_queries, static fn( $q ) => str_starts_with( ltrim( $q ), 'DELETE' ) );
wm_nl_rt_check( 'D7: a refused insert returns id 0 and deletes nothing', 0 === $refused['id'] && '' === $refused['plain_token'] && [] === $deletes && null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $staging, 'runtime-refused@example.invalid' ) ) );

// D9: the CleverReach payload carries the row times converted from the site's time zone; HTTP never leaves.
$captured = [];
$fake_http = static function ( $pre, $args, $url ) use ( &$captured ) {
	$captured[] = [ $url, $args ];
	return [ 'headers' => [], 'body' => '{"id":1}', 'response' => [ 'code' => 201, 'message' => 'Created' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $fake_http, 10, 3 );
ProviderFactory::create( 'cleverreach', [ 'cleverreach_token' => 'runtime-check' ] )->add_subscriber( new SubscriberDTO( email: 'runtime@example.invalid', blindedIp: 'x', consentHash: 'h', consentText: 't', listId: '9', confirmedAt: '2026-09-10 10:05:00', createdAt: '2026-09-10 10:00:00' ) );
remove_filter( 'pre_http_request', $fake_http, 10 );
$payload  = json_decode( (string) ( $captured[0][1]['body'] ?? '' ), true ) ?: [];
$expected = [ ( new DateTimeImmutable( '2026-09-10 10:00:00', wp_timezone() ) )->getTimestamp(), ( new DateTimeImmutable( '2026-09-10 10:05:00', wp_timezone() ) )->getTimestamp() ];
wm_nl_rt_check( 'D9: registered and activated are the row times in the site time zone (' . wp_timezone_string() . ')', 1 === count( $captured ) && $expected === [ $payload['registered'] ?? null, $payload['activated'] ?? null ], wp_json_encode( $payload ) );

// D11: the dead code with claims is gone.
wm_nl_rt_check( 'D11: no ProviderType enum and no getName/isEuNative on the adapters', ! enum_exists( 'WMCyber\\Newsletter\\Domain\\Enum\\ProviderType' ) && ! method_exists( 'WMCyber\\Newsletter\\Providers\\BrevoAdapter', 'getName' ) && ! method_exists( 'WMCyber\\Newsletter\\Providers\\LocalDatabaseProvider', 'isEuNative' ) );

// D12: an array in "email" is ignored (an invalid address never reaches the database).
$hook_req = new WP_REST_Request( 'POST', '/wm-newsletter/v1/webhook' );
$hook_req->set_header( 'Content-Type', 'application/json' );
$hook_req->set_body( wp_json_encode( [ 'event' => 'spam', 'email' => [ 'runtime@example.invalid' ] ] ) );
try {
	$hook_status = ( new WebhookController( $repo, $settings ) )->handle_remote_webhook( $hook_req )->get_status();
} catch ( Throwable $e ) {
	$hook_status = get_class( $e ) . ': ' . $e->getMessage();
}
wm_nl_rt_check( 'D12: the webhook answers 200 to an array address instead of a TypeError', 200 === $hook_status, (string) $hook_status );

// REST routes and the admin overview.
$routes = array_keys( rest_get_server()->get_routes() );
wm_nl_rt_check( 'REST routes registered', [] === array_diff( [ '/wm-newsletter/v1/subscribe', '/wm-newsletter/v1/altcha-challenge', '/wm-newsletter/v1/unsubscribe', '/wm-newsletter/v1/webhook' ], $routes ) );
$_GET = [ 'page' => 'wm-newsletter-suite', 'tab' => 'overview' ];
ob_start();
( new AdminDashboard( $repo, $settings, Plugin::instance()->getSyncQueue() ) )->render_dashboard();
$overview = (string) ob_get_clean();
$_GET     = [];
$local    = 'local' === (string) ( $config['active_provider'] ?? '' );
wm_nl_rt_check( 'admin overview shows held rows and names the third tile by provider', str_contains( $overview, 'Nicht übergeben, weil die Adresse lokal abgemeldet ist' ) && ( $local ? str_contains( $overview, 'Nur lokal geführt' ) : str_contains( $overview, '>An den Anbieter übergeben<' ) ) );

// Hub adapter.
if ( ! class_exists( 'WMCyber\\Newsletter\\Adapters\\Newsletter_Spoke_Adapter' ) ) {
	WP_CLI::log( '[SKIP] hub adapter: WM Suite Hub interface not loaded' );
} else {
	$adapter = new WMCyber\Newsletter\Adapters\Newsletter_Spoke_Adapter();
	$metrics = $adapter->get_telemetry_metrics();
	$health  = $adapter->get_health_status();
	$expect  = [] !== $settings->missing_provider_settings() ? 'NEEDS_CONFIG' : ( ( (int) $metrics['failed_esp_syncs'] > 0 || ! $metrics['sync_event_scheduled'] ) ? 'DEGRADED' : 'HEALTHY' );
	WP_CLI::log( 'hub health ' . $health . ' | telemetry ' . wp_json_encode( $metrics ) );
	wm_nl_rt_check( 'hub health follows failed pushes the queue retries, not held rows', $expect === $health && (int) $metrics['failed_esp_syncs'] === $stats['sync_failed'] && (int) $metrics['held_unsubscribed'] === $stats['held_unsubscribed'], $health . ' expected ' . $expect );
}

WP_CLI::log( sprintf( '=== RUNTIME: %d passed, %d failed ===', $GLOBALS['wm_nl_rt']['pass'], $GLOBALS['wm_nl_rt']['fail'] ) );
if ( $GLOBALS['wm_nl_rt']['fail'] > 0 ) {
	WP_CLI::error( 'runtime check failed' );
}
