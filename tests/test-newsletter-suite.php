<?php
/**
 * Integration test for WM DSGVO Newsletter Suite against a real WordPress and MySQL.
 *
 * Run only on a disposable install, inside the WordPress container:
 *   WM_NL_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-newsletter-suite/tests/test-newsletter-suite.php --allow-root
 *   (docker exec -e WM_NL_INTEGRATION_DISPOSABLE=1 <container> wp eval-file …)
 *
 * Without that variable it exits with code 2 before touching anything. The run writes to the install it
 * runs in: rows for addresses ending in @wm-nl-integration.test (deleted at the end, also on failure), rate
 * limit and proof-of-work transients, and for a few seconds a confirmed, subscribed test row that a WP-Cron
 * run started by another request would push to the site's configured provider. Until 2026-09-15 there was
 * no guard, and the purge step deleted every unconfirmed sign-up of the site past the window, not only test
 * rows; it now runs only when no other row would be deleted. Mail is intercepted with pre_wp_mail. No
 * strict_types declaration: wp eval-file runs the file as evaluated code, where a declare() after the
 * opening docblock is a fatal error.
 */

if ( '1' !== getenv( 'WM_NL_INTEGRATION_DISPOSABLE' ) ) {
	fwrite( STDERR, "Refused: this test writes to the WordPress install it runs in. Set WM_NL_INTEGRATION_DISPOSABLE=1 on a disposable install.\n" );
	exit( 2 );
}

if ( ! defined( 'ABSPATH' ) ) {
	require_once '/var/www/html/wp-load.php';
}

use WMCyber\Newsletter\Core\Plugin;
use WMCyber\Newsletter\Database\DoiRepository;
use WMCyber\Newsletter\Database\Schema;
use WMCyber\Newsletter\Domain\Enum\DoiState;
use WMCyber\Newsletter\Frontend\FormRenderer;
use WMCyber\Newsletter\Mailer\DoiMailer;
use WMCyber\Newsletter\Queue\SyncQueue;
use WMCyber\Newsletter\Security\AltchaVerifier;
use WMCyber\Newsletter\Security\RateLimiter;

global $wpdb;

// wp eval-file runs this file inside a method, so its variables are not globals: the counters live
// in $GLOBALS explicitly, or the summary and the exit code would always read 0 failures.
$GLOBALS['wm_nl_pass'] = 0;
$GLOBALS['wm_nl_fail'] = 0;
$wm_nl_domain          = '@wm-nl-integration.test';

function wm_nl_assert( string $name, bool $ok, string $detail = '' ): void {
	echo ( $ok ? ' [PASS] ' : ' [FAIL] ' ) . $name . ( '' !== $detail && ! $ok ? " — {$detail}" : '' ) . "\n";
	$ok ? $GLOBALS['wm_nl_pass']++ : $GLOBALS['wm_nl_fail']++;
}

$staging     = Schema::get_staging_table_name();
$subscribers = Schema::get_subscribers_table_name();
$mail_log        = [];
$mail_filter     = static function ( $return, $atts ) use ( &$mail_log ) {
	$mail_log[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );

$cleanup = static function () use ( $wpdb, $staging, $subscribers, $wm_nl_domain ): void {
	$like = '%' . $wpdb->esc_like( $wm_nl_domain );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email LIKE %s', $staging, $like ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email LIKE %s', $subscribers, $like ) );
};

echo "======================================================\n";
echo "  WM DSGVO NEWSLETTER SUITE — INTEGRATION (WordPress)\n";
echo "======================================================\n\n";

try {
	$cleanup();

	// 1. Tables and schedule.
	wm_nl_assert( 'staging table exists', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $staging ) ) ) === $staging );
	wm_nl_assert( 'subscribers table exists', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $subscribers ) ) ) === $subscribers );
	wm_nl_assert( 'the five-minute interval is registered', isset( wp_get_schedules()[ SyncQueue::SCHEDULE ] ) );
	wm_nl_assert( 'the sync event is scheduled', false !== wp_next_scheduled( SyncQueue::SYNC_HOOK ) );
	wm_nl_assert( 'the hourly cleanup event is scheduled', false !== wp_next_scheduled( SyncQueue::CLEANUP_HOOK ) );

	// 2. Settings secrets survive reads.
	$sm = Plugin::instance()->getSettingsManager();
	$a  = $sm->get_all();
	$b  = $sm->get_all();
	wm_nl_assert( 'webhook_secret is stored and stable', '' !== (string) $a['webhook_secret'] && $a['webhook_secret'] === $b['webhook_secret'] && $a['webhook_secret'] === ( get_option( 'wm_newsletter_settings' )['webhook_secret'] ?? null ) );

	// 3. DOI lifecycle.
	$repo  = new DoiRepository();
	$email = 'lifecycle' . $wm_nl_domain;
	$rec   = $repo->create_pending_doi( $email, '198.51.100.42', 'consent text', [], [], 'default', 'local', 'art_6_1_a', 'integration' );
	$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $staging, $rec['id'] ), ARRAY_A );
	wm_nl_assert( 'pending row stores the blinded IP, never the raw one', $row && DoiRepository::blind_ip( '198.51.100.42' ) === $row['blinded_ip'] );
	wm_nl_assert( 'pending row starts in PENDING_CONFIRMATION', $row && DoiState::PENDING_CONFIRMATION->value === $row['status'] );
	$confirm = $repo->confirm_token_atomic( $rec['plain_token'], $sm->ttl_hours() );
	wm_nl_assert( 'confirmation inside the window succeeds', true === $confirm['success'], (string) $confirm['message'] );
	wm_nl_assert( 'a second click answers "already confirmed"', ! empty( $repo->confirm_token_atomic( $rec['plain_token'] )['already'] ) );
	wm_nl_assert( 'unsubscribe marks the local row', $repo->unsubscribe( $email ) && 'unsubscribed' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE email = %s', $subscribers, $email ) ) );

	// 4. Queue: an unsubscribed address is not handed to the provider (real LEFT JOIN).
	$queue_emails = array_column( $repo->get_pending_sync_queue( 500 ), 'email' );
	wm_nl_assert( 'the queue leaves out the unsubscribed address', ! in_array( $email, $queue_emails, true ) );
	$kept  = 'queued' . $wm_nl_domain;
	$kept_rec = $repo->create_pending_doi( $kept, '198.51.100.43', 'consent text', [], [], 'default', 'local', 'art_6_1_a', 'integration' );
	$repo->confirm_token_atomic( $kept_rec['plain_token'], $sm->ttl_hours() );
	wm_nl_assert( 'the queue contains a confirmed, subscribed address', in_array( $kept, array_column( $repo->get_pending_sync_queue( 500 ), 'email' ), true ) );

	// 5. Deletion after the window, in the site clock (real DELETE). purge_expired_pending() deletes every
	// unconfirmed row of the site past the window, so it runs only when no row outside the test domain
	// qualifies; otherwise the step is reported as skipped, not passed.
	$ttl     = $sm->ttl_hours();
	$old     = 'expired' . $wm_nl_domain;
	$old_rec = $repo->create_pending_doi( $old, '198.51.100.44', 'consent text', [], [], 'default', 'local', 'art_6_1_a', 'integration' );
	$wpdb->update( $staging, [ 'created_at' => wp_date( 'Y-m-d H:i:s', time() - ( $ttl + 1 ) * HOUR_IN_SECONDS ) ], [ 'id' => $old_rec['id'] ] );
	$young = 'young' . $wm_nl_domain;
	$repo->create_pending_doi( $young, '198.51.100.45', 'consent text', [], [], 'default', 'local', 'art_6_1_a', 'integration' );
	$foreign_expired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s) AND created_at < %s AND email NOT LIKE %s', $staging, DoiState::PENDING_CONFIRMATION->value, DoiState::EXPIRED->value, DoiRepository::cutoff_for( $ttl ), '%' . $wpdb->esc_like( $wm_nl_domain ) ) );
	if ( 0 === $foreign_expired ) {
		$repo->purge_expired_pending( $ttl );
		wm_nl_assert( 'an unconfirmed row older than the window is deleted', null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $staging, $old ) ) );
		wm_nl_assert( 'a younger unconfirmed row stays', null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $staging, $young ) ) );
	} else {
		echo " [SKIP] purge: {$foreign_expired} unconfirmed rows of the site are past the window and would be deleted too\n";
	}

	// 6. Rate limiter and proof of work with real transients.
	$rl_ip = '203.0.113.' . wp_rand( 100, 250 );
	for ( $i = 0; $i < 6; $i++ ) {
		$rl = RateLimiter::check( 'integration', 5, 60, $rl_ip );
	}
	wm_nl_assert( 'rate limiter blocks the sixth request', false === $rl['allowed'] );
	RateLimiter::reset( 'integration', $rl_ip );
	$key       = (string) $sm->get( 'altcha_hmac_key' );
	$challenge = AltchaVerifier::create_challenge( $key, 2000 );
	$payload   = base64_encode( wp_json_encode( [ 'algorithm' => 'SHA-256', 'challenge' => $challenge['challenge'], 'number' => AltchaVerifier::solve( $challenge ), 'salt' => $challenge['salt'], 'signature' => $challenge['signature'] ] ) );
	wm_nl_assert( 'a solved challenge verifies once and is refused on replay', AltchaVerifier::verify( $payload, $key ) && ! AltchaVerifier::verify( $payload, $key ) );

	// 7. REST end to end, as the form sends it.
	$solve = static function () use ( $key ): string {
		$c = AltchaVerifier::create_challenge( $key, 2000 );
		return base64_encode( wp_json_encode( [ 'algorithm' => 'SHA-256', 'challenge' => $c['challenge'], 'number' => AltchaVerifier::solve( $c ), 'salt' => $c['salt'], 'signature' => $c['signature'] ] ) );
	};
	$post = static function ( array $body ): WP_REST_Response {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 100, 250 );
		$req = new WP_REST_Request( 'POST', '/wm-newsletter/v1/subscribe' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	};
	$rest_email = 'rest' . $wm_nl_domain;
	$mail_log   = [];
	$ok         = $post( [ 'email' => $rest_email, 'consent' => true, 'altcha' => $solve(), 'provider' => 'mailchimp', 'consent_text' => 'posted text' ] );
	$rest_row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', $staging, $rest_email ), ARRAY_A );
	wm_nl_assert( 'POST /subscribe with a solved challenge and consent answers 200', 200 === $ok->get_status(), wp_json_encode( $ok->get_data() ) );
	wm_nl_assert( 'the confirmation mail went to the address', 1 === count( $mail_log ) && $rest_email === ( $mail_log[0]['to'] ?? '' ) );
	wm_nl_assert( 'provider and consent text come from the site, not the request', $rest_row && (string) $sm->get( 'active_provider' ) === $rest_row['provider_adapter'] && ! str_contains( (string) $rest_row['consent_text'], 'posted text' ) && FormRenderer::consent_record( (string) ( $sm->get( 'privacy_policy_url' ) ?: home_url( '/datenschutz/' ) ) ) === $rest_row['consent_text'] );
	wm_nl_assert( 'POST /subscribe without proof of work answers 403', 403 === $post( [ 'email' => 'nopow' . $wm_nl_domain, 'consent' => true ] )->get_status() );
	wm_nl_assert( 'POST /subscribe without consent answers 400', 400 === $post( [ 'email' => 'noconsent' . $wm_nl_domain, 'altcha' => $solve() ] )->get_status() );
	$shield = $post( [ 'email' => 'shield' . $wm_nl_domain, 'consent' => true, 'altcha' => 'php://filter' ] );
	wm_nl_assert( 'the request shield answers 400 on the plugin route', 400 === $shield->get_status() && 'wm_security_shield_blocked' === ( $shield->as_error()->get_error_code() ?? '' ) );
	remove_filter( 'pre_wp_mail', $mail_filter, 10 );
	add_filter( 'pre_wp_mail', '__return_false' );
	$no_mail_email = 'nomail' . $wm_nl_domain;
	$failed        = $post( [ 'email' => $no_mail_email, 'consent' => true, 'altcha' => $solve() ] );
	remove_filter( 'pre_wp_mail', '__return_false' );
	wm_nl_assert( 'when the mail fails: 500 and no row keeps the address', 500 === $failed->get_status() && null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $staging, $no_mail_email ) ) );

	// 8. Unsubscribe route with the mailed token.
	$repo->upsert_subscriber_local( $rest_email, 'x', 'h', 't', [], [], 'default', 'local', 'art_6_1_a', 'integration' );
	$unsub = new WP_REST_Request( 'POST', '/wm-newsletter/v1/unsubscribe' );
	$unsub->set_param( 'email', $rest_email );
	$unsub->set_param( 'token', DoiRepository::unsubscribe_token( $rest_email ) );
	wm_nl_assert( 'unsubscribe with the mailed token answers 200', 200 === rest_do_request( $unsub )->get_status() );

	// 9. A plus address through core add_query_arg() and the query parsing PHP applies to $_GET (403 until 2026-09-15).
	$plus_email = 'anna+news' . $wm_nl_domain;
	$repo->upsert_subscriber_local( $plus_email, 'x', 'h', 't', [], [], 'default', 'local', 'art_6_1_a', 'integration' );
	wp_parse_str( (string) wp_parse_url( DoiMailer::build_unsubscribe_url( $plus_email ), PHP_URL_QUERY ), $plus_query );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 100, 250 );
	$plus_req               = new WP_REST_Request( 'GET', '/wm-newsletter/v1/unsubscribe' );
	$plus_req->set_query_params( $plus_query );
	wm_nl_assert( 'the mailed unsubscribe link works for a plus address', 200 === rest_do_request( $plus_req )->get_status() && 'unsubscribed' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE email = %s', $subscribers, $plus_email ) ) );
} catch ( Throwable $e ) {
	wm_nl_assert( 'no exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	$cleanup();
	remove_filter( 'pre_wp_mail', $mail_filter, 10 );
	// Settings are not restored: the run changes none of them, and restoring a copy taken before
	// SettingsManager stored its secrets would rotate the site's webhook key.
}

$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email LIKE %s', $staging, '%' . $wpdb->esc_like( $wm_nl_domain ) ) ) + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email LIKE %s', $subscribers, '%' . $wpdb->esc_like( $wm_nl_domain ) ) );
wm_nl_assert( 'cleanup left no test rows', 0 === $left );

echo "\n------------------------------------------------------\n";
echo 'SUMMARY: ' . $GLOBALS['wm_nl_pass'] . ' passed | ' . $GLOBALS['wm_nl_fail'] . " failed\n";
echo "======================================================\n";
exit( $GLOBALS['wm_nl_fail'] > 0 ? 1 : 0 );
