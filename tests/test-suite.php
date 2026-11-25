<?php
/**
 * Standalone test suite for wm-dsgvo-newsletter-suite — no WordPress, no MySQL.
 *
 * A deterministic mock layer stands in for $wpdb, options, transients, cron, mail, HTTP, hooks and the
 * hub contract. Groups 1–5 predate 2026-09-15; groups 4 and 6–15 pin the defects fixed that day (provider
 * TypeErrors, unregistered cron interval, form without proof of work, client-controlled evidence,
 * regenerated webhook secret, deletion promise, time zone, site-wide request shield, admin verdicts,
 * uninstall hooks); group 16 pins D1–D12 of the reader pass of the same day (plus addresses, form list,
 * held and local rows, rate-limit window, route case, failed insert, re-click after a failed push,
 * CleverReach consent times, integration-test guard, dead claims, webhook input).
 * Run: php tests/test-suite.php (exit 0 = green).
 *
 * @package WMCyber\Newsletter
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// Warnings and deprecations fail the run: an undefined property is exactly what this suite hunts.
set_error_handler( static function ( int $no, string $str, string $file, int $line ): bool {
	if ( ! ( error_reporting() & $no ) ) {
		return false; // Suppressed with @ (file probes in the mocks and in the plugin's filemtime fallbacks).
	}
	throw new ErrorException( $str, 0, $no, $file, $line );
} );

// ---------------------------------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------------------------------
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_DEBUG', true );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );

$wm_test_log = sys_get_temp_dir() . '/wm-newsletter-test-error-' . getmypid() . '.log';
@unlink( $wm_test_log );
ini_set( 'error_log', $wm_test_log );

// ---------------------------------------------------------------------------------------------------
// Mock state
// ---------------------------------------------------------------------------------------------------
global $mock_options, $mock_option_autoload, $mock_filters, $mock_db_tables, $mock_transients, $mock_cron, $mock_mail, $mock_mail_result,
	$mock_http_response, $mock_http_requests, $mock_offset_hours, $mock_queries, $wpdb, $wp_filesystem, $mock_uploads_base, $mock_cleared,
	$mock_insert_fail, $mock_transient_ttl, $mock_can_manage_options, $mock_registered_settings, $mock_block_bindings, $mock_gb_tags,
	$mock_rest_routes, $mock_actions_fired, $mock_is_multisite, $mock_network_active_plugins, $mock_sites, $mock_current_blog_id,
	$mock_blog_stack, $mock_blog_state, $mock_blog_switches, $mock_blog_restores, $mock_dbdelta_queries,
	$mock_activation_hooks, $mock_deactivation_hooks;

$mock_options                = [];
$mock_option_autoload        = [];
$mock_filters                = [];
$mock_transients             = [];
$mock_transient_ttl          = [];
$mock_insert_fail            = false;
$mock_can_manage_options     = false;
$mock_registered_settings    = [];
$mock_block_bindings         = [];
$mock_gb_tags                = [];
$mock_rest_routes            = [];
$mock_actions_fired          = [];
$mock_is_multisite           = false;
$mock_network_active_plugins = [];
$mock_sites                  = [ 1 ];
$mock_current_blog_id        = 1;
$mock_blog_stack             = [];
$mock_blog_state             = [];
$mock_blog_switches          = [];
$mock_blog_restores          = [];
$mock_dbdelta_queries        = [];
$mock_activation_hooks       = [];
$mock_deactivation_hooks     = [];
$mock_cron          = [];
$mock_cleared       = [];
$mock_mail          = [];
$mock_mail_result   = true;
$mock_http_response = null;
$mock_http_requests = [];
$mock_offset_hours  = 0;
$mock_queries       = [];
$mock_uploads_base  = sys_get_temp_dir();
$mock_db_tables     = [
	'wp_wm_newsletter_doi_staging' => [],
	'wp_wm_newsletter_subscribers' => [],
];

final class Mock_WPDB_Newsletter {
	public string $prefix   = 'wp_';
	public string $options  = 'wp_options';
	public int $insert_id   = 0;
	private array $auto_inc = [];

	/** @return int|false Like wpdb: false when MySQL refuses the row, and insert_id reset to 0. */
	public function insert( string $table, array $data, array $format = [] ) {
		global $mock_db_tables, $mock_insert_fail;
		if ( $mock_insert_fail ) {
			$this->insert_id = 0;
			return false;
		}
		$this->auto_inc[ $table ] = ( $this->auto_inc[ $table ] ?? 0 ) + 1;
		$id                       = $this->auto_inc[ $table ];
		$this->insert_id          = $id;
		$mock_db_tables[ $table ][ $id ] = (object) array_merge( [ 'id' => $id ], $data );
		return 1;
	}

	private static function row_matches( object $row, array $where ): bool {
		foreach ( $where as $k => $v ) {
			if ( ! property_exists( $row, $k ) || (string) $row->$k !== (string) $v ) {
				return false;
			}
		}
		return true;
	}

	public function update( string $table, array $data, array $where, $format = [], $where_format = [] ) {
		global $mock_db_tables;
		$n = 0;
		foreach ( $mock_db_tables[ $table ] ?? [] as $row ) {
			if ( self::row_matches( $row, $where ) ) {
				foreach ( $data as $k => $v ) {
					$row->$k = $v;
				}
				$n++;
			}
		}
		return $n;
	}

	public function delete( string $table, array $where, $format = [] ) {
		global $mock_db_tables;
		$n = 0;
		foreach ( $mock_db_tables[ $table ] ?? [] as $id => $row ) {
			if ( self::row_matches( $row, $where ) ) {
				unset( $mock_db_tables[ $table ][ $id ] );
				$n++;
			}
		}
		return $n;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function prepare( string $query, ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$idx = 0;
		return (string) preg_replace_callback( '/%[sdfFi]/', static function ( array $m ) use ( &$idx, $args ) {
			$arg = $args[ $idx++ ] ?? '';
			if ( '%i' === $m[0] ) {
				return '`' . str_replace( '`', '``', (string) $arg ) . '`';
			}
			if ( '%d' === $m[0] ) {
				return (string) (int) $arg;
			}
			return "'" . addslashes( (string) $arg ) . "'";
		}, $query );
	}

	public function query( string $query ) {
		global $mock_db_tables, $mock_queries;
		$mock_queries[] = $query;
		if ( preg_match( "/DELETE FROM\s+`([^`]+)`\s+WHERE email = '((?:[^'\\\\]|\\\\.)*)' AND status = '([^']+)' AND id <> (\d+)/", $query, $m ) ) {
			$n = 0;
			foreach ( $mock_db_tables[ $m[1] ] ?? [] as $id => $row ) {
				if ( ( $row->email ?? null ) === stripslashes( $m[2] ) && ( $row->status ?? null ) === $m[3] && (int) $id !== (int) $m[4] ) {
					unset( $mock_db_tables[ $m[1] ][ $id ] );
					$n++;
				}
			}
			return $n;
		}
		if ( preg_match( "/DELETE FROM\s+`([^`]+)`\s+WHERE status IN \('([^']+)', '([^']+)'\) AND created_at < '([^']+)'/", $query, $m ) ) {
			$n = 0;
			foreach ( $mock_db_tables[ $m[1] ] ?? [] as $id => $row ) {
				if ( in_array( $row->status, [ $m[2], $m[3] ], true ) && strcmp( (string) $row->created_at, $m[4] ) < 0 ) {
					unset( $mock_db_tables[ $m[1] ][ $id ] );
					$n++;
				}
			}
			return $n;
		}
		return 1;
	}

	public function get_row( string $query, $output = 'OBJECT' ) {
		global $mock_db_tables;
		$patterns = [
			'token_hash' => "/FROM\s+`?([^\s`]+)`?\s+WHERE\s+`?token_hash`?\s*=\s*'([^']+)'/i",
			'email'      => "/FROM\s+`?([^\s`]+)`?\s+WHERE\s+`?email`?\s*=\s*'([^']+)'/i",
			'id'         => "/FROM\s+`?([^\s`]+)`?\s+WHERE\s+`?id`?\s*=\s*'?(\d+)'?/i",
		];
		foreach ( $patterns as $col => $re ) {
			if ( preg_match( $re, $query, $m ) ) {
				foreach ( $mock_db_tables[ $m[1] ] ?? [] as $row ) {
					if ( isset( $row->$col ) && (string) $row->$col === stripslashes( $m[2] ) ) {
						return ARRAY_A === $output ? (array) $row : clone $row;
					}
				}
				return null;
			}
		}
		return null;
	}

	public function get_var( string $query ) {
		global $mock_db_tables;
		if ( preg_match( "/SHOW\s+TABLES\s+LIKE\s+'([^']+)'/i", $query, $m ) ) {
			// prepare() added slashes and esc_like() escaped _ and %: undo both, as MySQL would.
			$name = (string) preg_replace( '/\\\\([_%\\\\])/', '$1', stripslashes( $m[1] ) );
			return array_key_exists( $name, $mock_db_tables ) ? $name : null;
		}
		// Staging counts joined to the subscriber row; the subscriber condition is read from the SQL itself.
		if ( preg_match( '/COUNT\(\*\) FROM `([^`]+)` AS s (LEFT|INNER) JOIN `([^`]+)` AS u ON u\.email = s\.email WHERE (.+)$/s', $query, $m ) ) {
			$where    = $m[4];
			$statuses = preg_match( "/s\.status IN \('([^']+)', '([^']+)'\)/", $where, $in ) ? [ $in[1], $in[2] ] : ( preg_match( "/s\.status = '([^']+)'/", $where, $eq ) ? [ $eq[1] ] : [] );
			$sendable = preg_match( "/\( u\.status IS NULL OR u\.status = '([^']+)' \)/", $where, $send ) ? $send[1] : null;
			$not      = preg_match( "/u\.status <> '([^']+)'/", $where, $ne ) ? $ne[1] : null;
			$n        = 0;
			foreach ( $mock_db_tables[ $m[1] ] ?? [] as $row ) {
				if ( ! in_array( $row->status ?? null, $statuses, true ) ) {
					continue;
				}
				$sub = null;
				foreach ( $mock_db_tables[ $m[3] ] ?? [] as $candidate ) {
					if ( $candidate->email === $row->email ) {
						$sub = $candidate;
						break;
					}
				}
				if ( 'INNER' === $m[2] && null === $sub ) {
					continue;
				}
				if ( null !== $sendable && null !== $sub && $sendable !== $sub->status ) {
					continue;
				}
				if ( null !== $not && null !== $sub && $not === $sub->status ) {
					continue;
				}
				$n++;
			}
			return $n;
		}
		if ( preg_match( "/COUNT\(\*\)\s+FROM\s+`([^`]+)`\s+WHERE status = '([^']+)' AND provider_adapter (<>|=) '([^']+)'/", $query, $m ) ) {
			return count( array_filter( $mock_db_tables[ $m[1] ] ?? [], static fn( $r ) => ( $r->status ?? null ) === $m[2] && ( '=' === $m[3] ) === ( ( $r->provider_adapter ?? null ) === $m[4] ) ) );
		}
		if ( preg_match( "/COUNT\(\*\)\s+FROM\s+`?([^\s`]+)`?\s+WHERE\s+`?status`?\s*=\s*'([^']+)'/i", $query, $m ) ) {
			return count( array_filter( $mock_db_tables[ $m[1] ] ?? [], static fn( $r ) => ( $r->status ?? null ) === $m[2] ) );
		}
		if ( preg_match( '/COUNT\(\*\)\s+FROM\s+`?([^\s`]+)`?/i', $query, $m ) ) {
			return count( $mock_db_tables[ $m[1] ] ?? [] );
		}
		return null;
	}

	/** Emulates the plugin's two list queries; the queue's unsubscribe filter is read from the SQL itself. */
	public function get_results( string $query, $output = 'OBJECT' ) {
		global $mock_db_tables;
		if ( preg_match( '/FROM\s+`([^`]+)`\s+AS s\s+LEFT JOIN\s+`([^`]+)`\s+AS u/i', $query, $m ) ) {
			preg_match( "/status IN \('([^']+)', '([^']+)'\)/", $query, $st );
			preg_match( '/LIMIT (\d+)/', $query, $lim );
			$filter_unsub = (bool) preg_match( "/u\.status IS NULL OR u\.status = 'subscribed'/", $query );
			$rows         = [];
			foreach ( $mock_db_tables[ $m[1] ] ?? [] as $row ) {
				if ( ! in_array( $row->status, [ $st[1], $st[2] ], true ) ) {
					continue;
				}
				if ( $filter_unsub ) {
					foreach ( $mock_db_tables[ $m[2] ] ?? [] as $sub ) {
						if ( $sub->email === $row->email && 'subscribed' !== $sub->status ) {
							continue 2;
						}
					}
				}
				$rows[ $row->id ] = (array) $row;
			}
			ksort( $rows );
			return array_slice( array_values( $rows ), 0, (int) ( $lim[1] ?? 50 ) );
		}
		if ( preg_match( '/SELECT \* FROM\s+`([^`]+)`.*ORDER BY id DESC/is', $query, $m ) ) {
			$rows = array_map( static fn( $r ) => (array) $r, $mock_db_tables[ $m[1] ] ?? [] );
			krsort( $rows );
			return array_values( $rows );
		}
		return [];
	}
}
$wpdb = new Mock_WPDB_Newsletter();

// ---------------------------------------------------------------------------------------------------
// WordPress function mocks
// ---------------------------------------------------------------------------------------------------
function add_filter( string $tag, $cb, int $prio = 10, int $args = 1 ): bool {
	global $mock_filters;
	$mock_filters[ $tag ][] = [ $cb, $args ];
	return true;
}
function add_action( string $tag, $cb, int $prio = 10, int $args = 1 ): bool {
	return add_filter( $tag, $cb, $prio, $args );
}
function apply_filters( string $tag, $value, ...$rest ) {
	global $mock_filters;
	foreach ( $mock_filters[ $tag ] ?? [] as [ $cb, $n ] ) {
		$value = $cb( ...array_slice( array_merge( [ $value ], $rest ), 0, $n ) );
	}
	return $value;
}
function do_action( string $tag, ...$args ): void {
	global $mock_filters, $mock_actions_fired;
	$mock_actions_fired[ $tag ][] = $args;
	foreach ( $mock_filters[ $tag ] ?? [] as [ $cb, $n ] ) {
		$cb( ...array_slice( $args, 0, $n ) );
	}
}
function has_filter_callback( string $tag, $needle ): bool {
	global $mock_filters;
	foreach ( $mock_filters[ $tag ] ?? [] as [ $cb ] ) {
		if ( $cb === $needle ) {
			return true;
		}
	}
	return false;
}
function did_action( string $tag ): int {
	global $mock_did_init;
	return 'init' === $tag && ! empty( $mock_did_init ) ? 1 : 0;
}
function do_mock_action( string $tag ): void {
	global $mock_filters;
	foreach ( $mock_filters[ $tag ] ?? [] as [ $cb ] ) {
		$cb();
	}
}
function get_option( string $name, $default = false ) {
	global $mock_options;
	return $mock_options[ $name ] ?? $default;
}
function update_option( string $name, $value, $autoload = null ): bool {
	global $mock_options, $mock_option_autoload;
	$mock_options[ $name ]         = $value;
	$mock_option_autoload[ $name ] = $autoload;
	return true;
}
function delete_option( string $name ): bool {
	global $mock_options;
	unset( $mock_options[ $name ] );
	return true;
}
function delete_site_option( string $name ): bool {
	return delete_option( $name );
}
function wp_salt( string $scheme = 'auth' ): string {
	return 'mock-salt-' . $scheme;
}
function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
	return substr( bin2hex( random_bytes( $length ) ), 0, $length );
}
function current_time( string $type, $gmt = 0 ) {
	global $mock_offset_hours;
	$ts = time() + ( $gmt ? 0 : $mock_offset_hours * 3600 );
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s', $ts ) : $ts;
}
function wp_date( string $format, ?int $ts = null ) {
	global $mock_offset_hours;
	return gmdate( $format, ( $ts ?? time() ) + $mock_offset_hours * 3600 );
}
function admin_url( string $path = '' ): string {
	return 'https://demo.example/wp-admin/' . ltrim( $path, '/' );
}
function home_url( string $path = '' ): string {
	return 'https://demo.example' . $path;
}
function rest_url( string $path = '' ): string {
	return 'https://demo.example/wp-json/' . ltrim( $path, '/' );
}
function add_query_arg( ...$a ): string {
	if ( is_array( $a[0] ) ) {
		$params = $a[0];
		$url    = (string) ( $a[1] ?? '' );
	} else {
		$params = [ $a[0] => $a[1] ];
		$url    = (string) ( $a[2] ?? '' );
	}
	// Like core (functions.php, _http_build_query( …, false )): values are appended as given, not encoded.
	// Until 2026-09-15 this mock used http_build_query(), which encodes, and hid the plus-address defect.
	$pairs = [];
	foreach ( $params as $k => $v ) {
		if ( false !== $v ) {
			$pairs[] = $k . '=' . $v;
		}
	}
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', $pairs );
}
function get_gmt_from_date( string $date, string $format = 'Y-m-d H:i:s' ): string {
	global $mock_offset_hours;
	return gmdate( $format, strtotime( $date . ' UTC' ) - $mock_offset_hours * 3600 );
}
function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function esc_html( $t ): string {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $t ): string {
	return esc_html( $t );
}
function esc_url( $u ): string {
	return esc_html( $u );
}
function esc_url_raw( $u ): string {
	return (string) $u;
}
function __( string $t, string $d = 'default' ): string {
	return $t;
}
function esc_html__( string $t, string $d = 'default' ): string {
	return esc_html( $t );
}
function esc_attr__( string $t, string $d = 'default' ): string {
	return esc_html( $t );
}
function esc_html_e( string $t, string $d = 'default' ): void {
	echo esc_html( $t );
}
function esc_attr_e( string $t, string $d = 'default' ): void {
	echo esc_html( $t );
}
function wp_rand( int $min = 0, int $max = 0 ): int {
	return $max > $min ? random_int( $min, $max ) : random_int( 0, PHP_INT_MAX );
}
function get_transient( string $k ) {
	global $mock_transients;
	return $mock_transients[ $k ] ?? false;
}
function set_transient( string $k, $v, int $ttl = 0 ): bool {
	global $mock_transients, $mock_transient_ttl;
	$mock_transients[ $k ]    = $v;
	$mock_transient_ttl[ $k ] = $ttl;
	return true;
}
function delete_transient( string $k ): bool {
	global $mock_transients;
	unset( $mock_transients[ $k ] );
	return true;
}
function sanitize_email( $e ): string {
	if ( ! is_scalar( $e ) && null !== $e ) {
		// Core starts with strlen( $email ), which throws for an array under PHP 8.
		throw new TypeError( 'strlen(): Argument #1 ($string) must be of type string, ' . gettype( $e ) . ' given' );
	}
	return filter_var( (string) $e, FILTER_SANITIZE_EMAIL ) ?: '';
}
function is_email( $e ) {
	return false !== filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? $e : false;
}
function sanitize_text_field( $s ): string {
	return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : '';
}
function sanitize_textarea_field( $s ): string {
	return sanitize_text_field( $s );
}
function sanitize_key( $k ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
function absint( $n ): int {
	return abs( (int) $n );
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function wp_strip_all_tags( $s ): string {
	return trim( strip_tags( (string) $s ) );
}
function wp_kses( $s, $allowed ): string {
	return (string) $s;
}
function get_bloginfo( string $show = '' ): string {
	return 'Demo Site';
}
function wp_next_scheduled( string $hook ) {
	global $mock_cron;
	return $mock_cron[ $hook ]['ts'] ?? false;
}
function wp_schedule_event( int $ts, string $recurrence, string $hook ) {
	global $mock_cron;
	// WordPress refuses an event whose recurrence is not a registered schedule.
	$schedules = apply_filters( 'cron_schedules', [ 'hourly' => [], 'twicedaily' => [], 'daily' => [], 'weekly' => [] ] );
	if ( ! isset( $schedules[ $recurrence ] ) ) {
		return false;
	}
	$mock_cron[ $hook ] = [ 'ts' => $ts, 'recurrence' => $recurrence ];
	return true;
}
function wp_clear_scheduled_hook( string $hook ): int {
	global $mock_cron, $mock_cleared;
	$mock_cleared[] = $hook;
	unset( $mock_cron[ $hook ] );
	return 1;
}
function wp_mail( $to, $subject, $body, $headers = [] ): bool {
	global $mock_mail, $mock_mail_result;
	$mock_mail[] = compact( 'to', 'subject', 'body', 'headers' );
	return $mock_mail_result;
}
function mock_http( string $url, array $args ) {
	global $mock_http_response, $mock_http_requests;
	$mock_http_requests[] = [ $url, $args ];
	return $mock_http_response;
}
function wp_remote_post( string $url, array $args = [] ) {
	return mock_http( $url, $args );
}
function wp_remote_request( string $url, array $args = [] ) {
	return mock_http( $url, $args );
}
function wp_remote_get( string $url, array $args = [] ) {
	return mock_http( $url, $args );
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}
function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) ? ( $r['response']['code'] ?? '' ) : '';
}
function wp_remote_retrieve_body( $r ): string {
	return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '';
}
function shortcode_atts( array $pairs, $atts, string $shortcode = '' ): array {
	$out = [];
	foreach ( $pairs as $k => $v ) {
		$out[ $k ] = array_key_exists( $k, (array) $atts ) ? $atts[ $k ] : $v;
	}
	return $out;
}
function add_shortcode( string $tag, $cb ): void {}
function wp_register_style( ...$a ): bool {
	return true;
}
function wp_register_script( ...$a ): bool {
	return true;
}
function wp_enqueue_style( ...$a ): void {}
function wp_enqueue_script( ...$a ): void {}
function wp_localize_script( ...$a ): bool {
	return true;
}
function is_singular(): bool {
	return false;
}
function is_admin(): bool {
	return false;
}
function load_plugin_textdomain( ...$a ): bool {
	return true;
}
function current_user_can( string $cap ): bool {
	global $mock_can_manage_options;
	return 'manage_options' === $cap && ! empty( $mock_can_manage_options );
}
function register_setting( string $group, string $name, array $args = [] ): void {
	global $mock_registered_settings;
	$mock_registered_settings[ $name ] = compact( 'group', 'args' );
}
function register_rest_route( string $ns, string $route, array $args ): bool {
	global $mock_rest_routes;
	$mock_rest_routes[ '/' . trim( $ns, '/' ) . '/' . ltrim( $route, '/' ) ] = $args;
	return true;
}
function register_block_bindings_source( string $name, array $props ): bool {
	global $mock_block_bindings;
	$mock_block_bindings[ $name ] = $props;
	return true;
}
function get_all_registered_block_bindings_sources(): array {
	global $mock_block_bindings;
	return $mock_block_bindings;
}
class GenerateBlocks_Register_Dynamic_Tag {
	public function __construct( array $args ) {
		global $mock_gb_tags;
		$mock_gb_tags[ (string) ( $args['tag'] ?? '' ) ] = $args;
	}
}
function number_format_i18n( $n ): string {
	return number_format( (float) $n );
}
function wp_nonce_field( ...$a ): void {}
function selected( $a, $b ): void {
	echo (string) $a === (string) $b ? ' selected' : '';
}
function checked( $a, $b ): void {
	echo (string) $a === (string) $b ? ' checked' : '';
}
function wp_upload_dir(): array {
	global $mock_uploads_base;
	return [ 'basedir' => $mock_uploads_base ];
}
function trailingslashit( string $s ): string {
	return rtrim( $s, '/\\' ) . '/';
}
function wp_delete_file( string $f ): void {
	@unlink( $f );
}
function WP_Filesystem(): bool {
	global $wp_filesystem;
	$wp_filesystem = new class() {
		public function rmdir( string $path, bool $recursive = false ): bool {
			return @rmdir( $path );
		}
	};
	return true;
}
function plugin_dir_path( string $file ): string {
	return trailingslashit( dirname( $file ) );
}
function plugin_dir_url( string $file ): string {
	return 'https://demo.example/wp-content/plugins/wm-dsgvo-newsletter-suite/';
}
function plugin_basename( string $file ): string {
	return 'wm-dsgvo-newsletter-suite/' . basename( $file );
}
function register_activation_hook( string $file, $cb ): void {
	global $mock_activation_hooks;
	$mock_activation_hooks[ plugin_basename( $file ) ] = $cb;
}
function register_deactivation_hook( string $file, $cb ): void {
	global $mock_deactivation_hooks;
	$mock_deactivation_hooks[ plugin_basename( $file ) ] = $cb;
}
function is_multisite(): bool {
	global $mock_is_multisite;
	return ! empty( $mock_is_multisite );
}
function is_plugin_active_for_network( string $plugin ): bool {
	global $mock_network_active_plugins;
	return in_array( $plugin, $mock_network_active_plugins, true );
}
function get_sites( array $args = [] ): array {
	global $mock_sites;
	return $mock_sites;
}
function switch_to_blog( int $new_blog_id ): bool {
	global $mock_current_blog_id, $mock_blog_stack, $mock_blog_switches, $wpdb;
	global $mock_options, $mock_option_autoload, $mock_cron, $mock_blog_state;
	$mock_blog_state[ $mock_current_blog_id ] = [
		'options'  => $mock_options,
		'autoload' => $mock_option_autoload,
		'cron'     => $mock_cron,
	];
	$mock_blog_stack[]    = $mock_current_blog_id;
	$mock_blog_switches[] = $new_blog_id;
	$mock_current_blog_id = $new_blog_id;
	$wpdb->prefix         = 1 === $new_blog_id ? 'wp_' : 'wp_' . $new_blog_id . '_';
	$state                = $mock_blog_state[ $new_blog_id ] ?? [ 'options' => [], 'autoload' => [], 'cron' => [] ];
	$mock_options         = $state['options'];
	$mock_option_autoload = $state['autoload'];
	$mock_cron            = $state['cron'];
	return true;
}
function restore_current_blog(): bool {
	global $mock_current_blog_id, $mock_blog_stack, $mock_blog_restores, $wpdb;
	global $mock_options, $mock_option_autoload, $mock_cron, $mock_blog_state;
	if ( empty( $mock_blog_stack ) ) {
		return false;
	}
	$mock_blog_state[ $mock_current_blog_id ] = [
		'options'  => $mock_options,
		'autoload' => $mock_option_autoload,
		'cron'     => $mock_cron,
	];
	$prev                 = (int) array_pop( $mock_blog_stack );
	$mock_blog_restores[] = $mock_current_blog_id;
	$mock_current_blog_id = $prev;
	$wpdb->prefix         = 1 === $prev ? 'wp_' : 'wp_' . $prev . '_';
	$state                = $mock_blog_state[ $prev ] ?? [ 'options' => [], 'autoload' => [], 'cron' => [] ];
	$mock_options         = $state['options'];
	$mock_option_autoload = $state['autoload'];
	$mock_cron            = $state['cron'];
	return true;
}
function dbDelta( $queries = '', bool $execute = true ): array {
	global $mock_dbdelta_queries, $mock_db_tables;
	$list    = is_array( $queries ) ? $queries : [ (string) $queries ];
	$created = [];
	foreach ( $list as $sql ) {
		$mock_dbdelta_queries[] = $sql;
		if ( preg_match( '/CREATE TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m ) ) {
			$table = $m[1];
			if ( ! isset( $mock_db_tables[ $table ] ) ) {
				$mock_db_tables[ $table ] = [];
			}
			$created[ $table ] = 'Created table ' . $table;
		}
	}
	return $created;
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
	public function get_error_message(): string {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}
class WP_REST_Request {
	private array $params  = [];
	private array $headers = [];
	private array $json    = [];
	public function __construct( private string $method = 'GET', private string $route = '' ) {}
	public function set_param( string $k, $v ): void {
		$this->params[ $k ] = $v;
	}
	public function get_param( string $k ) {
		return $this->params[ $k ] ?? null;
	}
	public function get_params(): array {
		return $this->params;
	}
	public function set_json_params( array $json ): void {
		$this->json = $json;
	}
	public function get_json_params(): array {
		return $this->json;
	}
	public function set_header( string $k, string $v ): void {
		$this->headers[ strtolower( $k ) ] = $v;
	}
	public function get_header( string $k ): ?string {
		return $this->headers[ strtolower( $k ) ] ?? null;
	}
	public function get_route(): string {
		return $this->route;
	}
}
class WP_REST_Response {
	public array $headers = [];
	public function __construct( public $data = null, public int $status = 200 ) {}
	public function get_data() {
		return $this->data;
	}
	public function get_status(): int {
		return $this->status;
	}
	public function header( string $k, string $v ): void {
		$this->headers[ $k ] = $v;
	}
}

// Hub contract before the adapter file is loaded (the adapter returns early without it).
require_once __DIR__ . '/stubs/hub-contract.php';

// Load main plugin bootstrap so constants, autoloader, lifecycle hooks and wp_initialize_site run as in WordPress.
require_once ABSPATH . 'wm-dsgvo-newsletter-suite.php';
$wm_boot_filters = $mock_filters;

// ---------------------------------------------------------------------------------------------------
// Plugin classes
// ---------------------------------------------------------------------------------------------------
foreach ( [
	'Domain/Enum/DoiState', 'Domain/Enum/SubscriberStatus',
	'Domain/DTO/SubscriberDTO', 'Domain/DTO/ProviderResult',
	'Database/Schema', 'Database/DoiRepository',
	'Security/ErrorSanitizer', 'Security/RateLimiter', 'Security/AltchaVerifier', 'Security/RequestShield',
	'Admin/SettingsManager', 'Mailer/DoiMailer',
	'Contracts/NewsletterProviderInterface', 'Providers/LocalDatabaseProvider', 'Providers/BrevoAdapter',
	'Providers/RapidmailAdapter', 'Providers/CleverReachAdapter', 'Providers/KitAdapter', 'Providers/MailchimpAdapter',
	'Providers/ProviderFactory', 'Queue/SyncQueue', 'Frontend/FormRenderer', 'Frontend/EcosystemIntegrations',
	'REST/SubscriptionController', 'REST/WebhookController', 'Admin/AdminDashboard', 'Adapters/Newsletter_Spoke_Adapter',
	'CLI/NewsletterCommand', 'Core/Activator', 'Core/Deactivator', 'Core/Plugin',
] as $wm_class_file ) {
	require_once ABSPATH . 'includes/' . $wm_class_file . '.php';
}

use WMCyber\Newsletter\Admin\AdminDashboard;
use WMCyber\Newsletter\Admin\SettingsManager;
use WMCyber\Newsletter\Adapters\Newsletter_Spoke_Adapter;
use WMCyber\Newsletter\Core\Activator;
use WMCyber\Newsletter\Core\Plugin;
use WMCyber\Newsletter\Database\DoiRepository;
use WMCyber\Newsletter\Domain\DTO\ProviderResult;
use WMCyber\Newsletter\Domain\DTO\SubscriberDTO;
use WMCyber\Newsletter\Domain\Enum\SubscriberStatus;
use WMCyber\Newsletter\Frontend\EcosystemIntegrations;
use WMCyber\Newsletter\Frontend\FormRenderer;
use WMCyber\Newsletter\Mailer\DoiMailer;
use WMCyber\Newsletter\Providers\ProviderFactory;
use WMCyber\Newsletter\Queue\SyncQueue;
use WMCyber\Newsletter\REST\SubscriptionController;
use WMCyber\Newsletter\REST\WebhookController;
use WMCyber\Newsletter\Security\AltchaVerifier;
use WMCyber\Newsletter\Security\ErrorSanitizer;
use WMCyber\Newsletter\Security\RateLimiter;
use WMCyber\Newsletter\Security\RequestShield;

// ---------------------------------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------------------------------
$passed_count = 0;
$failed_count = 0;

function print_results(): void {
	global $passed_count, $failed_count;
	echo "\n==============================================================================\n";
	echo "  TEST SUITE RESULTS: {$passed_count} PASSED | {$failed_count} FAILED\n";
	echo "==============================================================================\n\n";
}

// An error nobody catches still ends with the results line, so tests/run-mutations.sh can tell a run
// whose assertions failed from a file that never ran (a closing PHP tag inside a line comment makes a
// test file print itself and exit 0; measured in wm-activity-sentinel on 2026-09-15).
set_exception_handler( static function ( Throwable $e ): void {
	global $failed_count, $wm_test_log;
	$failed_count++;
	echo '  [FAIL] uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
	print_results();
	@unlink( $wm_test_log );
	exit( 1 );
} );

function assert_test( string $name, bool $condition, string $details = '' ): void {
	global $passed_count, $failed_count;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		$passed_count++;
	} else {
		echo "  [FAIL] {$name}" . ( '' !== $details ? ": {$details}" : '' ) . "\n";
		$failed_count++;
	}
}

function reset_tables(): void {
	global $mock_db_tables;
	$mock_db_tables['wp_wm_newsletter_doi_staging'] = [];
	$mock_db_tables['wp_wm_newsletter_subscribers'] = [];
}

function staging_rows(): array {
	global $mock_db_tables;
	return array_values( $mock_db_tables['wp_wm_newsletter_doi_staging'] );
}

function set_settings( array $values ): void {
	global $mock_options;
	$mock_options['wm_newsletter_settings'] = array_merge( $mock_options['wm_newsletter_settings'] ?? [], $values );
}

/** A fresh, solved proof-of-work payload signed with the site's key. */
function solved_altcha(): string {
	$key       = (string) ( new SettingsManager() )->get( 'altcha_hmac_key' );
	$challenge = AltchaVerifier::create_challenge( $key, 1500 );
	return base64_encode( json_encode( [
		'algorithm' => 'SHA-256',
		'challenge' => $challenge['challenge'],
		'number'    => AltchaVerifier::solve( $challenge ),
		'salt'      => $challenge['salt'],
		'signature' => $challenge['signature'],
	] ) );
}

function http_answer( int $code, array $body ): array {
	return [ 'response' => [ 'code' => $code ], 'body' => json_encode( $body ) ];
}

function make_controller(): SubscriptionController {
	$repo     = new DoiRepository();
	$settings = new SettingsManager();
	return new SubscriptionController( $repo, new DoiMailer( $settings ), new SyncQueue( $repo, $settings ), $settings );
}

$emoji_re = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}]/u';

echo "==============================================================================\n";
echo "  WM DSGVO NEWSLETTER SUITE — STANDALONE TEST SUITE\n";
echo "==============================================================================\n\n";

// ---------------------------------------------------------------------------------------------------
echo "[TEST GROUP 1] IP blinding, token and consent hashes\n";
$blinded_ip = DoiRepository::blind_ip( '198.51.100.42' );
assert_test( 'blind_ip is a 64-hex HMAC, never the raw IP', 64 === strlen( $blinded_ip ) && ctype_xdigit( $blinded_ip ) && '198.51.100.42' !== $blinded_ip );
assert_test( 'hash_token is SHA-256 hex', 64 === strlen( DoiRepository::hash_token( bin2hex( random_bytes( 32 ) ) ) ) );
assert_test( 'hash_consent is SHA-256 of the trimmed text', hash( 'sha256', 'abc' ) === DoiRepository::hash_consent( ' abc ' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 2] Pending sign-up\n";
reset_tables();
$repo    = new DoiRepository();
$pending = $repo->create_pending_doi( 'subscriber@example.de', '198.51.100.42', 'consent', [ 'first_name' => 'Klaus' ], [ 'kunde' ], 'list-101', 'local', 'art_6_1_a', 'footer_form' );
assert_test( 'create_pending_doi writes one staging row and returns a single-use plain token', isset( $pending['plain_token'], $pending['token_hash'] ) && 1 === count( staging_rows() ) );
assert_test( 'the stored token is its hash, not the plain token', staging_rows()[0]->token_hash === DoiRepository::hash_token( $pending['plain_token'] ) && staging_rows()[0]->token_hash !== $pending['plain_token'] );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 3] Error sanitizer\n";
$sanitized = ErrorSanitizer::handle( new RuntimeException( 'Database constraint violation with secret credentials' ), 'TEST_SUITE' );
assert_test( 'visitor message carries only an incident reference', str_starts_with( $sanitized['incident_ref'], 'WM-NEWSLETTER-' ) && str_contains( $sanitized['safe_message'], $sanitized['incident_ref'] ) && ! str_contains( $sanitized['safe_message'], 'secret' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 4] Provider results match their callers (TypeError until 2026-09-15)\n";
try {
	$failure = ProviderResult::failure( 'Key not found', 401, false, [ 'code' => 'unauthorized' ] );
	assert_test( 'failure( message, int status, bool, array ) builds a result', false === $failure->success && '401' === $failure->errorCode && false === $failure->retryable && [ 'code' => 'unauthorized' ] === $failure->rawResponse );
} catch ( Throwable $e ) {
	assert_test( 'failure( message, int status, bool, array ) builds a result', false, get_class( $e ) . ': ' . $e->getMessage() );
}
try {
	$success = ProviderResult::success( '42', [ 'id' => 42 ] );
	assert_test( 'success( provider id, body ) keeps the provider id', true === $success->success && '42' === $success->providerSubscriberId );
} catch ( Throwable $e ) {
	assert_test( 'success( provider id, body ) keeps the provider id', false, get_class( $e ) . ': ' . $e->getMessage() );
}

$dto            = new SubscriberDTO( email: 'p@example.de', blindedIp: 'x', consentHash: 'h', consentText: 't', listId: '7', confirmedAt: '2026-09-10 10:05:00', createdAt: '2026-09-10 10:00:00' );
$provider_cases = [
	'brevo'       => [ [ 'brevo_api_key' => 'k' ], 401, [ 'message' => 'Key not found' ] ],
	'rapidmail'   => [ [ 'rapidmail_username' => 'u', 'rapidmail_password' => 'p' ], 422, [ 'description' => 'invalid' ] ],
	'cleverreach' => [ [ 'cleverreach_token' => 't' ], 403, [ 'error' => [ 'message' => 'forbidden' ] ] ],
	'kit'         => [ [ 'kit_api_key' => 'k' ], 401, [ 'message' => 'bad key' ] ],
	'mailchimp'   => [ [ 'mailchimp_api_key' => 'abc-us6' ], 400, [ 'title' => 'Invalid Resource' ] ],
];
foreach ( $provider_cases as $id => [ $creds, $status, $body ] ) {
	$mock_http_response = http_answer( $status, $body );
	try {
		$r = ProviderFactory::create( $id, $creds )->add_subscriber( $dto );
		assert_test( "{$id}: a refused request ({$status}) returns a failure instead of throwing", false === $r->success && (string) $status === $r->errorCode );
	} catch ( Throwable $e ) {
		assert_test( "{$id}: a refused request ({$status}) returns a failure instead of throwing", false, get_class( $e ) . ': ' . $e->getMessage() );
	}
	$mock_http_response = http_answer( 201, [ 'id' => 99, 'subscriber' => [ 'id' => 99 ] ] );
	try {
		$r = ProviderFactory::create( $id, $creds )->add_subscriber( $dto );
		assert_test( "{$id}: an accepted contact returns success with the provider id", true === $r->success && '99' === $r->providerSubscriberId );
	} catch ( Throwable $e ) {
		assert_test( "{$id}: an accepted contact returns success with the provider id", false, get_class( $e ) . ': ' . $e->getMessage() );
	}
}
$mock_http_response = new WP_Error( 'http_request_failed', 'timeout' );
$network            = ProviderFactory::create( 'kit', [ 'kit_api_key' => 'k' ] )->add_subscriber( $dto );
assert_test( 'a network error is a retryable failure', false === $network->success && $network->retryable );
$stub = ProviderFactory::create( 'rapidmail', [] );
assert_test( 'unimplemented provider methods say so instead of reporting success', false === $stub->delete_subscriber( 'p@example.de' )->success && 'not_implemented' === $stub->update_subscriber( 'p@example.de', [] )->errorCode && SubscriberStatus::UNKNOWN === $stub->get_subscriber_status( 'p@example.de' ) );
$mock_http_response = http_answer( 401, [ 'message' => 'Key not found' ] );
assert_test( 'Brevo delete with a refused request is a failure (counted as done until 2026-09-15)', false === ProviderFactory::create( 'brevo', [ 'brevo_api_key' => 'k' ] )->delete_subscriber( 'p@example.de' )->success );
$mock_http_requests = [];
$mock_http_response = http_answer( 200, [ 'id' => 'x' ] );
ProviderFactory::create( 'mailchimp', [ 'mailchimp_api_key' => 'k-us6' ] )->add_subscriber( new SubscriberDTO( email: 'p@example.de', blindedIp: 'x', consentHash: 'h', consentText: 't', listId: 'a/b?c' ) );
assert_test( 'Mailchimp encodes the list id in the URL', str_contains( (string) ( $mock_http_requests[0][0] ?? '' ), '/lists/a%2Fb%3Fc/members/' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 5] Unsubscribe route requires the subscriber's token\n";
reset_tables();
$controller = make_controller();
$victim     = 'victim@example.de';
$repo->upsert_subscriber_local( $victim, DoiRepository::blind_ip( '198.51.100.9' ), 'h', 'consent', [], [], 'default', 'local', 'art_6_1_a', 'default' );
$subscriber_status = static function () use ( $victim ): string {
	global $mock_db_tables;
	foreach ( $mock_db_tables['wp_wm_newsletter_subscribers'] as $row ) {
		if ( $row->email === $victim ) {
			return (string) $row->status;
		}
	}
	return 'missing';
};
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$req = new WP_REST_Request( 'GET', '/wm-newsletter/v1/unsubscribe' );
$req->set_param( 'email', $victim );
assert_test( 'address alone is refused (403), subscriber stays subscribed', 403 === $controller->handle_unsubscribe( $req )->get_status() && 'subscribed' === $subscriber_status() );
$req->set_param( 'token', str_repeat( '0', 64 ) );
assert_test( 'wrong token is refused (403)', 403 === $controller->handle_unsubscribe( $req )->get_status() && 'subscribed' === $subscriber_status() );
$req->set_param( 'token', DoiRepository::unsubscribe_token( $victim ) );
assert_test( 'own token unsubscribes (200)', 200 === $controller->handle_unsubscribe( $req )->get_status() && 'unsubscribed' === $subscriber_status() );
$unsub_url = DoiMailer::build_unsubscribe_url( $victim );
assert_test( 'List-Unsubscribe URL carries the token', str_contains( $unsub_url, 'wm-newsletter/v1/unsubscribe' ) && str_contains( $unsub_url, 'token=' . DoiRepository::unsubscribe_token( $victim ) ) );
$limited = false;
for ( $i = 0; $i < 12; $i++ ) {
	if ( 429 === $controller->handle_unsubscribe( $req )->get_status() ) {
		$limited = true;
		break;
	}
}
assert_test( 'unsubscribe is rate-limited per client (429 within 12 requests)', $limited );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 6] Settings keep their secrets\n";
$mock_options = [];
$first        = ( new SettingsManager() )->get_all();
$second       = ( new SettingsManager() )->get_all();
assert_test( 'webhook_secret is generated once and stays the same (a new one on every read until 2026-09-15)', '' !== $first['webhook_secret'] && $first['webhook_secret'] === $second['webhook_secret'] && $first['webhook_secret'] === ( $mock_options['wm_newsletter_settings']['webhook_secret'] ?? null ) );
assert_test( 'the proof-of-work key is stored and is not the auth salt', $first['altcha_hmac_key'] === $second['altcha_hmac_key'] && wp_salt( 'auth' ) !== $first['altcha_hmac_key'] );
$webhook  = new WebhookController( new DoiRepository(), new SettingsManager() );
$hook_ok  = new WP_REST_Request( 'POST', '/wm-newsletter/v1/webhook' );
$hook_ok->set_param( 'key', $first['webhook_secret'] );
$hook_bad = new WP_REST_Request( 'POST', '/wm-newsletter/v1/webhook' );
$hook_bad->set_param( 'key', 'nope' );
assert_test( 'the webhook authenticates with the stored key and refuses another', true === $webhook->verify_webhook_permission( $hook_ok ) && false === $webhook->verify_webhook_permission( $hook_bad ) );
set_settings( [ 'active_provider' => 'brevo', 'brevo_api_key' => '' ] );
assert_test( 'missing_provider_settings names the empty Brevo key', [ 'brevo_api_key' ] === ( new SettingsManager() )->missing_provider_settings() );
set_settings( [ 'active_provider' => 'mailchimp', 'mailchimp_api_key' => 'k-us6', 'default_list_id' => 'default' ] );
assert_test( 'missing_provider_settings refuses the list id "default" for Mailchimp', [ 'default_list_id' ] === ( new SettingsManager() )->missing_provider_settings() );
set_settings( [ 'active_provider' => 'local', 'doi_ttl_hours' => 500 ] );
assert_test( 'ttl_hours clamps to 168', 168 === ( new SettingsManager() )->ttl_hours() );
set_settings( [ 'doi_ttl_hours' => 48 ] );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 7] Proof of work: expiry and single use\n";
$key     = (string) ( new SettingsManager() )->get( 'altcha_hmac_key' );
$payload = solved_altcha();
assert_test( 'a solved challenge verifies once', AltchaVerifier::verify( $payload, $key ) );
assert_test( 'the same payload is refused the second time (replayable forever until 2026-09-15)', false === AltchaVerifier::verify( $payload, $key ) );
$past_salt = bin2hex( random_bytes( 4 ) ) . '?expires=' . ( time() - 5 );
$past_chal = hash( 'sha256', $past_salt . '7' );
$expired   = base64_encode( json_encode( [ 'algorithm' => 'SHA-256', 'challenge' => $past_chal, 'number' => 7, 'salt' => $past_salt, 'signature' => hash_hmac( 'sha256', $past_chal, $key ) ] ) );
assert_test( 'an expired challenge is refused', false === AltchaVerifier::verify( $expired, $key ) );
$no_exp_salt = bin2hex( random_bytes( 4 ) );
$no_exp_chal = hash( 'sha256', $no_exp_salt . '7' );
$no_expiry   = base64_encode( json_encode( [ 'algorithm' => 'SHA-256', 'challenge' => $no_exp_chal, 'number' => 7, 'salt' => $no_exp_salt, 'signature' => hash_hmac( 'sha256', $no_exp_chal, $key ) ] ) );
assert_test( 'a challenge without expiry is refused', false === AltchaVerifier::verify( $no_expiry, $key ) );
$wrong = json_decode( base64_decode( solved_altcha() ), true );
$wrong['number']++;
assert_test( 'a wrong number is refused', false === AltchaVerifier::verify( base64_encode( json_encode( $wrong ) ), $key ) );
assert_test( 'a challenge signed with another key is refused', false === AltchaVerifier::verify( solved_altcha(), 'other-key' ) );
$js_source = (string) file_get_contents( ABSPATH . 'assets/js/frontend-form.js' );
assert_test( 'the form script requests and solves the challenge and sends it as "altcha"', str_contains( $js_source, "'altcha-challenge'" ) && str_contains( $js_source, 'altcha: altcha' ) && str_contains( $js_source, "crypto.subtle.digest('SHA-256'" ) );
assert_test( 'the form script writes server text with textContent, never innerHTML', ! preg_match( '/\.innerHTML\s*=/', $js_source ) && str_contains( $js_source, 'el.textContent = msg' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 8] Subscribe: evidence decided on the server\n";
reset_tables();
$mock_transients = [];
set_settings( [ 'active_provider' => 'local', 'altcha_enabled' => 1, 'privacy_policy_url' => 'https://demo.example/datenschutz/' ] );
$controller = make_controller();
$ip_counter = 10;
$subscribe  = static function ( array $params ) use ( $controller, &$ip_counter ): WP_REST_Response {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( $ip_counter++ );
	$r = new WP_REST_Request( 'POST', '/wm-newsletter/v1/subscribe' );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	return $controller->handle_subscribe( $r );
};
assert_test( 'without proof of work: 403', 403 === $subscribe( [ 'email' => 'a@example.de', 'consent' => true ] )->get_status() );
assert_test( 'without consent: 400 consent_required (never checked on the server until 2026-09-15)', 'consent_required' === ( $subscribe( [ 'email' => 'a@example.de', 'altcha' => solved_altcha() ] )->get_data()['code'] ?? '' ) );
$res = $subscribe( [
	'email'        => 'a@example.de',
	'consent'      => true,
	'altcha'       => solved_altcha(),
	'provider'     => 'mailchimp',
	'consent_text' => 'I agree to receive anything from anyone',
	'legal_basis'  => '',
	'list_id'      => '',
] );
$row = staging_rows()[0] ?? null;
assert_test( 'a valid sign-up is accepted (200) and mailed', 200 === $res->get_status() && 1 === count( staging_rows() ) );
assert_test( 'the provider comes from the settings, not from the request', $row && 'local' === $row->provider_adapter );
assert_test( 'the stored consent text is the sentence the form prints, not the posted one', $row && FormRenderer::consent_record( 'https://demo.example/datenschutz/' ) === $row->consent_text && ! str_contains( $row->consent_text, 'anything from anyone' ) );
assert_test( 'the consent hash is the hash of that stored text', $row && DoiRepository::hash_consent( $row->consent_text ) === $row->consent_snapshot_hash );
assert_test( 'a list id without the form signature is refused (400 invalid_form)', 'invalid_form' === ( $subscribe( [ 'email' => 'b@example.de', 'consent' => true, 'altcha' => solved_altcha(), 'list_id' => 'vip', 'legal_basis' => 'art_6_1_a' ] )->get_data()['code'] ?? '' ) );
$signed = $subscribe( [ 'email' => 'c@example.de', 'consent' => '1', 'altcha' => solved_altcha(), 'list_id' => 'vip', 'legal_basis' => 'art_6_1_b', 'form_sig' => FormRenderer::form_signature( 'vip', 'art_6_1_b' ) ] );
$c_row  = array_values( array_filter( staging_rows(), static fn( $r ) => 'c@example.de' === $r->email ) )[0] ?? null;
assert_test( 'list and legal basis from a signed form are stored', 200 === $signed->get_status() && $c_row && 'vip' === $c_row->list_id && 'art_6_1_b' === $c_row->legal_basis );
assert_test( 'an unknown legal basis is refused even when signed', 'invalid_form' === ( $subscribe( [ 'email' => 'd@example.de', 'consent' => true, 'altcha' => solved_altcha(), 'list_id' => 'vip', 'legal_basis' => 'art_9', 'form_sig' => FormRenderer::form_signature( 'vip', 'art_9' ) ] )->get_data()['code'] ?? '' ) );
$mock_mail_result = false;
$failed_mail      = $subscribe( [ 'email' => 'e@example.de', 'consent' => true, 'altcha' => solved_altcha() ] );
$mock_mail_result = true;
assert_test( 'when the mail cannot be sent: 500 and no row keeps the address', 500 === $failed_mail->get_status() && [] === array_filter( staging_rows(), static fn( $r ) => 'e@example.de' === $r->email ) );
$bot = $subscribe( [ 'email' => 'bot@example.de', 'consent' => true, 'wm_hp_name' => 'x' ] );
assert_test( 'a filled honeypot gets the success answer and stores nothing', 200 === $bot->get_status() && [] === array_filter( staging_rows(), static fn( $r ) => 'bot@example.de' === $r->email ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 9] Confirmation window, deletion and time zone\n";
reset_tables();
$mock_offset_hours = 2; // A site two hours east of UTC (Europe/Berlin in summer).
$local_ago         = static fn( int $hours ): string => gmdate( 'Y-m-d H:i:s', time() + 2 * 3600 - $hours * 3600 );
foreach ( [ [ 'keep-47', 'PENDING_CONFIRMATION', 47 ], [ 'drop-49', 'PENDING_CONFIRMATION', 49 ], [ 'drop-expired', 'EXPIRED', 49 ], [ 'keep-confirmed', 'CONFIRMED', 100 ] ] as [ $label, $status, $hours ] ) {
	$wpdb->insert( 'wp_wm_newsletter_doi_staging', [ 'email' => $label . '@example.de', 'status' => $status, 'created_at' => $local_ago( $hours ), 'token_hash' => hash( 'sha256', $label ) ] );
}
$deleted = $repo->purge_expired_pending( 48 );
$left    = array_map( static fn( $r ) => $r->email, staging_rows() );
sort( $left );
assert_test( 'purge deletes unconfirmed and expired rows past the window, in the site clock (relabelled, and 2 h late here, until 2026-09-15)', 2 === $deleted && [ 'keep-47@example.de', 'keep-confirmed@example.de' ] === $left, 'deleted=' . $deleted . ' left=' . implode( ',', $left ) );

$doi_row = [ 'blinded_ip' => 'x', 'consent_snapshot_hash' => 'h', 'consent_text' => 't', 'attributes_json' => '[]', 'tags_json' => '[]', 'list_id' => 'default', 'provider_adapter' => 'local', 'legal_basis' => 'art_6_1_a', 'form_id' => 'default', 'status' => 'PENDING_CONFIRMATION' ];
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $doi_row, [ 'email' => 'late@example.de', 'created_at' => $local_ago( 3 ), 'token_hash' => DoiRepository::hash_token( 'tok-late' ) ] ) );
$late = $repo->confirm_token_atomic( 'tok-late', 2 );
assert_test( 'a link older than a 2-hour window is expired and the message names 2 hours (fixed ">48h" until 2026-09-15)', false === $late['success'] && 410 === $late['code'] && str_contains( $late['message'], '2 Stunden' ) );
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $doi_row, [ 'email' => 'fresh@example.de', 'created_at' => $local_ago( 1 ), 'token_hash' => DoiRepository::hash_token( 'tok-fresh' ) ] ) );
$fresh = $repo->confirm_token_atomic( 'tok-fresh', 2 );
assert_test( 'a link inside the window confirms and creates the subscriber row', true === $fresh['success'] && 1 === count( array_filter( $mock_db_tables['wp_wm_newsletter_subscribers'], static fn( $r ) => 'fresh@example.de' === $r->email && 'subscribed' === $r->status ) ) );
$mock_offset_hours = 0;
$renderer_src      = (string) file_get_contents( ABSPATH . 'includes/Frontend/FormRenderer.php' );
assert_test( 'the confirmation page passes the configured window (48 regardless until 2026-09-15)', str_contains( $renderer_src, 'confirm_token_atomic( $token, $this->settings->ttl_hours() )' ) );

set_settings( [ 'doi_ttl_hours' => 24, 'doi_email_subject' => 'Bitte <b>bestätigen</b>' ] );
$mock_mail = [];
( new DoiMailer( new SettingsManager() ) )->send_doi_confirmation( 'm@example.de', 'tok' );
$mail_body = (string) ( $mock_mail[0]['body'] ?? '' );
assert_test( 'the mail states the configured window (always "48 Stunden" until 2026-09-15)', str_contains( $mail_body, 'nach 24 Stunden gelöscht' ) && ! str_contains( $mail_body, '48 Stunden' ) );
assert_test( 'the subject is escaped inside <title>', str_contains( $mail_body, '<title>Bitte &lt;b&gt;' ) );
set_settings( [ 'doi_ttl_hours' => 48, 'doi_email_subject' => '' ] );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 10] Sync queue: schedule, failures, unsubscribed addresses\n";
$mock_filters  = [];
$mock_cron     = [];
$mock_did_init = false;
$queue         = new SyncQueue( new DoiRepository(), new SettingsManager() );
$queue->boot();
assert_test( 'booted before init: nothing is scheduled yet (the label would load translations too early)', [] === $mock_cron );
assert_test( 'before init the interval label is not translated through gettext', 'Alle fünf Minuten' === ( SyncQueue::register_schedule( [] )['every_five_minutes']['display'] ?? '' ) );
$mock_did_init = true;
do_mock_action( 'init' );
assert_test( 'the five-minute interval is registered', 300 === ( apply_filters( 'cron_schedules', [] )['every_five_minutes']['interval'] ?? 0 ) );
assert_test( 'the sync event is scheduled against it (refused by WordPress until 2026-09-15)', 'every_five_minutes' === ( $mock_cron[ SyncQueue::SYNC_HOOK ]['recurrence'] ?? '' ) );
assert_test( 'the hourly cleanup event is scheduled', 'hourly' === ( $mock_cron[ SyncQueue::CLEANUP_HOOK ]['recurrence'] ?? '' ) );

reset_tables();
set_settings( [ 'active_provider' => 'brevo', 'brevo_api_key' => 'k' ] );
$base = [ 'blinded_ip' => 'x', 'consent_snapshot_hash' => 'h', 'consent_text' => 't', 'attributes_json' => '[]', 'tags_json' => '[]', 'list_id' => '3', 'provider_adapter' => 'brevo', 'legal_basis' => 'art_6_1_a', 'form_id' => 'default', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ];
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $base, [ 'email' => 'fail@example.de', 'status' => 'CONFIRMED', 'token_hash' => 't1' ] ) );
$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => 'fail@example.de', 'status' => 'subscribed' ] );
$fail_id = (int) staging_rows()[0]->id;
@unlink( $wm_test_log );
$mock_http_response = http_answer( 401, [ 'message' => 'Key not found', 'code' => 'unauthorized' ] );
try {
	$accepted = $queue->process_queue();
	assert_test( 'a refused provider call marks the row SYNC_FAILED instead of throwing', 0 === $accepted && 'SYNC_FAILED' === staging_rows()[0]->status );
} catch ( Throwable $e ) {
	assert_test( 'a refused provider call marks the row SYNC_FAILED instead of throwing', false, get_class( $e ) . ': ' . $e->getMessage() );
}
$log = (string) @file_get_contents( $wm_test_log );
assert_test( 'the failure log names row, provider and answer but not the address', str_contains( $log, "staging #{$fail_id} via brevo: Key not found (401)" ) && ! str_contains( $log, 'fail@example.de' ), $log );

reset_tables();
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $base, [ 'email' => 'ok@example.de', 'status' => 'CONFIRMED', 'token_hash' => 't2' ] ) );
$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => 'ok@example.de', 'status' => 'subscribed', 'provider_id' => null ] );
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $base, [ 'email' => 'gone@example.de', 'status' => 'CONFIRMED', 'token_hash' => 't3' ] ) );
$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => 'gone@example.de', 'status' => 'unsubscribed' ] );
$mock_http_response = http_answer( 201, [ 'id' => 4711 ] );
$mock_http_requests = [];
$accepted           = $queue->process_queue();
$by_email           = [];
foreach ( staging_rows() as $r ) {
	$by_email[ $r->email ] = $r->status;
}
$ok_sub = array_values( array_filter( $mock_db_tables['wp_wm_newsletter_subscribers'], static fn( $r ) => 'ok@example.de' === $r->email ) )[0];
assert_test( 'an accepted contact is marked SYNCED_TO_PROVIDER with the provider id', 1 === $accepted && 'SYNCED_TO_PROVIDER' === $by_email['ok@example.de'] && '4711' === (string) $ok_sub->provider_id );
$sent_emails = array_map( static fn( $sent ) => json_decode( (string) $sent[1]['body'], true )['email'] ?? '', $mock_http_requests );
assert_test( 'an address unsubscribed before the run is not pushed to the provider (pushed until 2026-09-15)', 'CONFIRMED' === $by_email['gone@example.de'] && ! in_array( 'gone@example.de', $sent_emails, true ) );
set_settings( [ 'active_provider' => 'local', 'brevo_api_key' => '' ] );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 11] Request shield only guards the plugin's routes\n";
$mock_filters = [];
$_POST        = [ 'comment' => 'see php://input in the manual' ];
RequestShield::boot();
assert_test( 'boot registers a REST filter and leaves site requests alone', has_filter_callback( 'rest_pre_dispatch', [ RequestShield::class, 'guard_rest_request' ] ) );
$_POST = [];
$own   = new WP_REST_Request( 'POST', '/wm-newsletter/v1/subscribe' );
$own->set_param( 'email', 'php://filter/resource=x' );
$other = new WP_REST_Request( 'POST', '/wp/v2/comments' );
$other->set_param( 'content', 'php://filter/resource=x' );
$clean = new WP_REST_Request( 'POST', '/wm-newsletter/v1/subscribe' );
$clean->set_param( 'email', 'a@example.de' );
$blocked = RequestShield::guard_rest_request( null, null, $own );
assert_test( 'a dangerous parameter on the plugin route is answered with a 400 WP_Error', $blocked instanceof WP_Error && 400 === ( $blocked->get_error_data()['status'] ?? 0 ) );
assert_test( 'the same parameter on another route passes untouched', null === RequestShield::guard_rest_request( null, null, $other ) );
assert_test( 'a clean request on the plugin route passes', null === RequestShield::guard_rest_request( null, null, $clean ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 12] Form output\n";
$form = ( new FormRenderer( new SettingsManager() ) )->render_shortcode( [ 'list_id' => 'vip' ] );
assert_test( 'no "100% DSGVO-Konform" badge and no emoji', ! str_contains( $form, '100%' ) && ! str_contains( $form, 'DSGVO-Konform' ) && ! preg_match( $emoji_re, $form ) );
assert_test( 'neutral defaults as documented on the admin screen', str_contains( $form, '>Newsletter abonnieren</h2>' ) && str_contains( $form, '>Anmelden</span>' ) && ! str_contains( $form, 'NIS-2' ) && ! str_contains( $form, 'wm-nl-desc' ) );
assert_test( 'the form carries the signature of its list and legal basis', str_contains( $form, 'name="form_sig" value="' . FormRenderer::form_signature( 'vip', 'art_6_1_a' ) . '"' ) );
assert_test( 'the email field has a visible label and the feedback is a live region', str_contains( $form, 'class="wm-nl-label"' ) && str_contains( $form, 'role="status"' ) );
$record = FormRenderer::consent_record( 'https://demo.example/datenschutz/' );
assert_test( 'consent_record is the printed sentence as text plus the policy URL', str_starts_with( $record, 'Ich willige in die Verarbeitung' ) && str_ends_with( $record, '[https://demo.example/datenschutz/]' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 13] Hub adapter\n";
reset_tables();
$mock_cron = [ SyncQueue::SYNC_HOOK => [ 'ts' => time() + 60, 'recurrence' => 'every_five_minutes' ] ];
$adapter   = new Newsletter_Spoke_Adapter();
assert_test( 'slug', 'wm-dsgvo-newsletter-suite' === $adapter->get_module_slug() );
assert_test( 'settings link points at the real page (wm-newsletter-settings until 2026-09-15)', str_contains( (string) $adapter->get_settings_url(), 'page=wm-newsletter-suite' ) );
assert_test( 'HEALTHY with tables, local provider, scheduled sync and no failures', 'HEALTHY' === $adapter->get_health_status(), $adapter->get_health_status() );
set_settings( [ 'active_provider' => 'brevo', 'brevo_api_key' => '' ] );
assert_test( 'NEEDS_CONFIG when the active provider has no key', 'NEEDS_CONFIG' === $adapter->get_health_status() );
set_settings( [ 'active_provider' => 'local' ] );
$wpdb->insert( 'wp_wm_newsletter_doi_staging', [ 'email' => 'x@example.de', 'status' => 'SYNC_FAILED' ] );
assert_test( 'DEGRADED with a failed sync (WARNING, a value the hub rejects, until 2026-09-15)', 'DEGRADED' === $adapter->get_health_status() );
$mock_cron = [];
reset_tables();
assert_test( 'DEGRADED when the sync event is not scheduled', 'DEGRADED' === $adapter->get_health_status() );
$tables_backup  = $mock_db_tables;
$mock_db_tables = [];
assert_test( 'ERROR without tables, telemetry counts null', 'ERROR' === $adapter->get_health_status() && null === $adapter->get_telemetry_metrics()['confirmed_subscribers'] );
$mock_db_tables = $tables_backup;
$telemetry      = (string) json_encode( $adapter->get_telemetry_metrics() );
$sbom           = (string) json_encode( $adapter->get_sbom_data() );
assert_test( 'telemetry carries no fixed claims', ! str_contains( $telemetry, '100%' ) && ! str_contains( $telemetry, 'IMMUTABLE' ) );
// The plugin's own constant (the main file is loaded above): a literal had to be edited on every release.
assert_test( 'SBOM names features, not statutes or judgments', ! preg_match( '/UWG|BGH|TDDDG/', $sbom ) && str_contains( $sbom, 'wm:features:doi' ) && str_contains( $sbom, 'pkg:wordpress\/wm-dsgvo-newsletter-suite@' . WM_NEWSLETTER_VERSION ) );
$mock_cron = [ SyncQueue::SYNC_HOOK => [ 'ts' => time() + 60, 'recurrence' => 'every_five_minutes' ] ];
$self_test = $adapter->execute_action( 'self_test' );
assert_test( 'self_test round-trips the proof of work and checks tables and schedule', true === $self_test['success'] && str_contains( $self_test['message'], 'Proof-of-Work ok' ) );
assert_test( 'unknown action is refused', false === $adapter->execute_action( 'nope' )['success'] );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 14] Admin screen and widget\n";
reset_tables();
foreach ( [ 'subscribed', 'subscribed', 'unsubscribed' ] as $i => $status ) {
	$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => "s{$i}@example.de", 'status' => $status ] );
}
$dashboard = new AdminDashboard( new DoiRepository(), new SettingsManager(), new SyncQueue( new DoiRepository(), new SettingsManager() ) );
ob_start();
$dashboard->render_dashboard_widget();
$widget = (string) ob_get_clean();
assert_test( 'the widget counts subscribed rows (status "confirmed", always 0, until 2026-09-15)', (bool) preg_match( '/wm-dash-kpi-val">2<\/div>\s*<div class="wm-dash-kpi-lbl">Abonniert/', $widget ) );
assert_test( 'the widget carries no "100% EU Hosting" tile and no emoji', ! str_contains( $widget, '100%' ) && ! preg_match( $emoji_re, $widget ) );
$screens = '';
foreach ( [ 'overview', 'staging', 'settings', 'generator' ] as $tab ) {
	$_GET = [ 'page' => 'wm-newsletter-suite', 'tab' => $tab ];
	ob_start();
	$dashboard->render_dashboard();
	$screens .= (string) ob_get_clean();
}
$_GET = [];
assert_test( 'no legal verdicts on the admin screen', ! preg_match( '/RECHTSKONFORM|BARRIEREFREI|Behördenaudit|Forensisch/', $screens ) );
assert_test( 'no emoji and no data-centre claims on the admin screen', ! preg_match( $emoji_re, $screens ) && ! preg_match( '/Frankfurt|Freiburg|Rastede|Zero-Cloud/', $screens ) );
assert_test( 'the settings tab shows the webhook URL with the stored key', str_contains( $screens, 'wm-newsletter/v1/webhook?key=' . ( new SettingsManager() )->get( 'webhook_secret' ) ) );
assert_test( 'every settings field has a label for its id', 0 === substr_count( $screens, '<label>' ) && str_contains( $screens, 'for="wm-nl-ttl"' ) );
assert_test( 'CSV cells that start a formula are neutralised', "'=1+1" === AdminDashboard::csv_cell( '=1+1' ) && "'@SUM(A1)" === AdminDashboard::csv_cell( '@SUM(A1)' ) && 'plain@example.de' === AdminDashboard::csv_cell( 'plain@example.de' ) );
assert_test( 'the dead admin script is gone', ! file_exists( ABSPATH . 'assets/js/admin-dashboard.js' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 15] Uninstall (opt-in data deletion)\n";
$uninstall_src = (string) file_get_contents( ABSPATH . 'uninstall.php' );
assert_test( 'uninstall unschedules the two real events and none of the invented names', str_contains( $uninstall_src, "'wm_newsletter_process_sync_queue'" ) && str_contains( $uninstall_src, "'wm_newsletter_hourly_cleanup'" ) && ! str_contains( $uninstall_src, "'wm_newsletter_hourly_sync'" ) && ! str_contains( $uninstall_src, "'wm_newsletter_cleanup_expired'" ) );
$mock_uploads_base = sys_get_temp_dir() . '/wm-nl-uninstall-' . getmypid();
$exports           = $mock_uploads_base . '/wm-newsletter/exports';
@mkdir( $exports, 0777, true );
foreach ( [ '.htaccess', 'web.config', 'index.php' ] as $f ) {
	file_put_contents( $exports . '/' . $f, 'x' );
}
unset( $mock_options['wm_newsletter_delete_data_on_uninstall'] );
$mock_options['wm_newsletter_settings'] = [ 'x' => 1 ];
$mock_queries                           = [];
$mock_cron                              = [ SyncQueue::SYNC_HOOK => [ 'ts' => 1 ], SyncQueue::CLEANUP_HOOK => [ 'ts' => 1 ] ];
define( 'WP_UNINSTALL_PLUGIN', 'wm-dsgvo-newsletter-suite/wm-dsgvo-newsletter-suite.php' );
include ABSPATH . 'uninstall.php';
assert_test( 'by default uninstall preserves settings option', isset( $mock_options['wm_newsletter_settings'] ) );
assert_test( 'by default uninstall clears both cron events', [] === $mock_cron );
assert_test( 'by default uninstall drops neither table and leaves files intact', 0 === count( array_filter( $mock_queries, static fn( $q ) => str_starts_with( $q, 'DROP TABLE IF EXISTS' ) ) ) && is_dir( $exports ) );

$mock_options['wm_newsletter_delete_data_on_uninstall'] = 1;
$mock_queries                                           = [];
include ABSPATH . 'uninstall.php';
assert_test( 'with opt-in, settings and uninstall flag options deleted', ! isset( $mock_options['wm_newsletter_settings'] ) && ! isset( $mock_options['wm_newsletter_delete_data_on_uninstall'] ) );
assert_test( 'with opt-in, both tables dropped', 2 === count( array_filter( $mock_queries, static fn( $q ) => str_starts_with( $q, 'DROP TABLE IF EXISTS' ) ) ) );
assert_test( 'the old exports directory and its three files are removed on opt-in', ! is_dir( $mock_uploads_base . '/wm-newsletter' ) );
@mkdir( $exports, 0777, true );
file_put_contents( $exports . '/someone-elses.csv', 'x' );
$mock_options['wm_newsletter_delete_data_on_uninstall'] = 1;
include ABSPATH . 'uninstall.php';
assert_test( 'a directory holding other files stays', is_file( $exports . '/someone-elses.csv' ) );
@unlink( $exports . '/someone-elses.csv' );
@rmdir( $exports );
@rmdir( $mock_uploads_base . '/wm-newsletter' );
@rmdir( $mock_uploads_base );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 16] Reader pass 2026-09-15: D1–D12\n";
$mock_transients = [];
$mock_mail       = [];
$sub_row         = static function ( string $email ): ?object {
	global $mock_db_tables;
	foreach ( $mock_db_tables['wp_wm_newsletter_subscribers'] as $row ) {
		if ( $row->email === $email ) {
			return $row;
		}
	}
	return null;
};
$rest_ip = 30;
$fresh_ip = static function () use ( &$rest_ip ): void {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( $rest_ip++ );
};

// D1. The unsubscribe link of a plus address, parsed the way PHP fills $_GET.
reset_tables();
set_settings( [ 'active_provider' => 'local', 'altcha_enabled' => 0 ] );
$plus = 'anna+news@example.de';
$repo->upsert_subscriber_local( $plus, 'x', 'h', 't', [], [], 'default', 'local', 'art_6_1_a', 'default' );
parse_str( (string) parse_url( DoiMailer::build_unsubscribe_url( $plus ), PHP_URL_QUERY ), $link_query );
$req = new WP_REST_Request( 'GET', '/wm-newsletter/v1/unsubscribe' );
$req->set_param( 'email', $link_query['email'] ?? '' );
$req->set_param( 'token', $link_query['token'] ?? '' );
$fresh_ip();
$plus_res = make_controller()->handle_unsubscribe( $req );
assert_test( 'D1: the mailed link unsubscribes a plus address (403, "+" read as a space, until 2026-09-15)', 200 === $plus_res->get_status() && 'unsubscribed' === ( $sub_row( $plus )->status ?? '' ), (string) $plus_res->get_status() . ' email=' . ( $link_query['email'] ?? '' ) );

// D2. The form without list_id uses the list from the settings, through the whole push.
reset_tables();
$mock_transients = [];
set_settings( [ 'active_provider' => 'rapidmail', 'rapidmail_username' => 'u', 'rapidmail_password' => 'p', 'default_list_id' => '12345', 'altcha_enabled' => 0 ] );
$plain_form = ( new FormRenderer( new SettingsManager() ) )->render_shortcode( [] );
preg_match( '/name="list_id" value="([^"]*)"/', $plain_form, $f_list );
preg_match( '/name="legal_basis" value="([^"]*)"/', $plain_form, $f_basis );
preg_match( '/name="form_sig" value="([^"]*)"/', $plain_form, $f_sig );
assert_test( 'D2: a form without list_id posts an empty list (the literal "default" until 2026-09-15)', '' === ( $f_list[1] ?? null ) && FormRenderer::form_signature( '', 'art_6_1_a' ) === ( $f_sig[1] ?? '' ) );
$form_post = static function ( string $email, string $list, string $basis, string $sig ): WP_REST_Response {
	$r = new WP_REST_Request( 'POST', '/wm-newsletter/v1/subscribe' );
	foreach ( [ 'email' => $email, 'consent' => '1', 'list_id' => $list, 'legal_basis' => $basis, 'form_sig' => $sig ] as $k => $v ) {
		$r->set_param( $k, $v );
	}
	return make_controller()->handle_subscribe( $r );
};
$fresh_ip();
$form_post( 'form@example.de', $f_list[1] ?? '', $f_basis[1] ?? '', $f_sig[1] ?? '' );
$fresh_ip();
$form_post( 'cached@example.de', 'default', 'art_6_1_a', FormRenderer::form_signature( 'default', 'art_6_1_a' ) );
$lists = [];
foreach ( staging_rows() as $r ) {
	$lists[ $r->email ] = $r->list_id;
}
assert_test( 'D2: the form sign-up stores the configured list, also from a cached page posting "default"', '12345' === ( $lists['form@example.de'] ?? '' ) && '12345' === ( $lists['cached@example.de'] ?? '' ), json_encode( $lists ) );
foreach ( $mock_db_tables['wp_wm_newsletter_doi_staging'] as $r ) {
	$r->status       = 'CONFIRMED';
	$r->confirmed_at = $r->created_at;
}
$mock_http_requests = [];
$mock_http_response = http_answer( 201, [ 'id' => 1 ] );
( new SyncQueue( new DoiRepository(), new SettingsManager() ) )->process_queue();
$rapid_lists = array_map( static fn( $sent ) => json_decode( (string) $sent[1]['body'], true )['recipientlist_id'] ?? null, $mock_http_requests );
assert_test( 'D2: Rapidmail receives recipientlist_id 12345 (refused before any request until 2026-09-15)', [ 12345, 12345 ] === $rapid_lists, json_encode( $rapid_lists ) );

// D3 and D4. Held rows of unsubscribed addresses and rows kept by the provider "local".
reset_tables();
set_settings( [ 'active_provider' => 'local' ] );
$q_base = [ 'blinded_ip' => 'x', 'consent_snapshot_hash' => 'h', 'consent_text' => 't', 'attributes_json' => '[]', 'tags_json' => '[]', 'list_id' => 'default', 'legal_basis' => 'art_6_1_a', 'form_id' => 'default', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'confirmed_at' => gmdate( 'Y-m-d H:i:s' ) ];
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $q_base, [ 'email' => 'held-failed@example.de', 'status' => 'SYNC_FAILED', 'provider_adapter' => 'brevo', 'token_hash' => 'h1' ] ) );
$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => 'held-failed@example.de', 'status' => 'unsubscribed' ] );
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $q_base, [ 'email' => 'held-confirmed@example.de', 'status' => 'CONFIRMED', 'provider_adapter' => 'brevo', 'token_hash' => 'h2' ] ) );
$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => 'held-confirmed@example.de', 'status' => 'unsubscribed' ] );
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $q_base, [ 'email' => 'local@example.de', 'status' => 'CONFIRMED', 'provider_adapter' => 'local', 'token_hash' => 'h3' ] ) );
$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => 'local@example.de', 'status' => 'subscribed' ] );
$held_stats = ( new DoiRepository() )->get_stats();
assert_test( 'D3: held rows are neither failed nor waiting (counted as failed for good until 2026-09-15)', 0 === $held_stats['sync_failed'] && 1 === $held_stats['waiting_for_sync'] && 2 === $held_stats['held_unsubscribed'], json_encode( $held_stats ) );
$mock_cron = [ SyncQueue::SYNC_HOOK => [ 'ts' => time() + 60, 'recurrence' => 'every_five_minutes' ] ];
$adapter   = new Newsletter_Spoke_Adapter();
assert_test( 'D3: hub health is HEALTHY and queue_health succeeds with only held rows (DEGRADED until 2026-09-15)', 'HEALTHY' === $adapter->get_health_status() && true === $adapter->execute_action( 'queue_health' )['success'] && 2 === $adapter->get_telemetry_metrics()['held_unsubscribed'], $adapter->get_health_status() );
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $q_base, [ 'email' => 'retry@example.de', 'status' => 'SYNC_FAILED', 'provider_adapter' => 'brevo', 'token_hash' => 'h4' ] ) );
assert_test( 'D3: a failed row of a subscribed address still counts and degrades health', 1 === ( new DoiRepository() )->get_stats()['sync_failed'] && 'DEGRADED' === $adapter->get_health_status() );
unset( $mock_db_tables['wp_wm_newsletter_doi_staging'][ (int) array_key_last( $mock_db_tables['wp_wm_newsletter_doi_staging'] ) ] );
$local_queue = new SyncQueue( new DoiRepository(), new SettingsManager() );
$mock_http_requests = [];
$local_queue->process_queue();
$local_stats = ( new DoiRepository() )->get_stats();
assert_test( 'D4: a push with the provider "local" is counted as kept local, not as handed over', [ 'external' => 0, 'local' => 1, 'failed' => 0 ] === $local_queue->last_run() && 1 === $local_stats['kept_local'] && 0 === $local_stats['synced_to_provider'] && [] === $mock_http_requests, json_encode( [ $local_queue->last_run(), $local_stats ] ) );
$notice = AdminDashboard::sync_notice( 0, 1, 0 );
assert_test( 'D4: the sync notice says "nur in der lokalen Tabelle" and not "an den Anbieter übergeben"', str_contains( $notice, 'nur in der lokalen Tabelle' ) && ! str_contains( $notice, 'an den Anbieter übergeben' ) && str_contains( AdminDashboard::sync_notice( 2, 0, 1 ), '2 Kontakte an den Anbieter übergeben' ) );
$_GET = [ 'page' => 'wm-newsletter-suite', 'tab' => 'overview', 'sync_ran' => '1', 'sync_extern' => '0', 'sync_local' => '1', 'sync_failed' => '0' ];
ob_start();
( new AdminDashboard( new DoiRepository(), new SettingsManager(), $local_queue ) )->render_dashboard();
$local_overview = (string) ob_get_clean();
$_GET           = [];
assert_test( 'D4: with the provider "local" the KPI reads "Nur lokal geführt" (always "An den Anbieter übergeben" until 2026-09-15)', (bool) preg_match( '/wm-kpi-val">1<\/div>\s*<div class="wm-kpi-lbl">Nur lokal geführt/', $local_overview ) && ! str_contains( $local_overview, '>An den Anbieter übergeben<' ) && str_contains( $local_overview, '1 bestätigte Adressen nur in der lokalen Tabelle' ) );

// D5. Fixed rate-limit window.
$mock_transients = [];
$spaced_ok       = true;
for ( $i = 0; $i < 12; $i++ ) {
	$spaced_ok = $spaced_ok && RateLimiter::check( 'rl-test', 5, 60, '192.0.2.200', 1000 + $i * 50 )['allowed'];
}
assert_test( 'D5: one request every 50 s is never refused against 5 per 60 s (refused from the sixth until 2026-09-15)', $spaced_ok );
$mock_transients = [];
$burst           = [];
for ( $i = 0; $i < 6; $i++ ) {
	$burst[] = RateLimiter::check( 'rl-test', 5, 60, '192.0.2.201', 5000 + $i );
}
$refused_late = RateLimiter::check( 'rl-test', 5, 60, '192.0.2.201', 5030 );
assert_test( 'D5: the sixth request inside a window is refused with the seconds left', true === $burst[4]['allowed'] && false === $burst[5]['allowed'] && 55 === $burst[5]['retry_after'] && false === $refused_late['allowed'] && 30 === $refused_late['retry_after'], json_encode( [ $burst[5], $refused_late ] ) );
assert_test( 'D5: refused requests do not extend the window', true === RateLimiter::check( 'rl-test', 5, 60, '192.0.2.201', 5060 )['allowed'] );
$legacy_key                     = 'wm_nl_rl_' . md5( 'rl-test_192.0.2.202' );
$mock_transients[ $legacy_key ] = 9;
assert_test( 'D5: an integer counter left by the old code starts a new window', true === RateLimiter::check( 'rl-test', 5, 60, '192.0.2.202', 7000 )['allowed'] && 60 === ( $mock_transient_ttl[ $legacy_key ] ?? 0 ) );

// D6. Route letter case.
$upper = new WP_REST_Request( 'POST', '/WM-Newsletter/v1/subscribe' );
$upper->set_param( 'email', 'php://filter/resource=wp-config.php' );
assert_test( 'D6: the shield refuses the same parameter on /WM-Newsletter/v1/ (passed until 2026-09-15)', RequestShield::guard_rest_request( null, null, $upper ) instanceof WP_Error );

// D7. A refused insert: no mail, 500, the earlier pending row survives.
reset_tables();
$mock_mail = [];
set_settings( [ 'active_provider' => 'local', 'altcha_enabled' => 0, 'default_list_id' => 'default' ] );
$fresh_ip();
$first_signup = $form_post( 'twice@example.de', '', '', '' );
$first_token  = staging_rows()[0]->token_hash ?? '';
$mock_insert_fail = true;
$fresh_ip();
$refused_insert   = $form_post( 'twice@example.de', '', '', '' );
$mock_insert_fail = false;
assert_test( 'D7: a refused insert answers 500 storage_failed and sends no mail (200 and a dead link until 2026-09-15)', 500 === $refused_insert->get_status() && 'storage_failed' === ( $refused_insert->get_data()['code'] ?? '' ) && 1 === count( $mock_mail ), (string) $refused_insert->get_status() . ' mails=' . count( $mock_mail ) );
assert_test( 'D7: the earlier pending row of the address is still there', 1 === count( staging_rows() ) && $first_token === staging_rows()[0]->token_hash );
$fresh_ip();
$form_post( 'twice@example.de', '', '', '' );
assert_test( 'D7: a stored new sign-up still replaces the older pending row', 1 === count( staging_rows() ) && $first_token !== staging_rows()[0]->token_hash );

// D8. Re-click after a failed push.
reset_tables();
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $q_base, [ 'email' => 'reclick@example.de', 'status' => 'SYNC_FAILED', 'provider_adapter' => 'brevo', 'token_hash' => DoiRepository::hash_token( 'tok-reclick' ) ] ) );
$reclick = $repo->confirm_token_atomic( 'tok-reclick', 48 );
assert_test( 'D8: a second click on a row whose push failed answers "already confirmed" (410 until 2026-09-15)', true === $reclick['success'] && ! empty( $reclick['already'] ) && 200 === $reclick['code'] );

// D9. CleverReach receives sign-up and confirmation time, converted from the site clock.
$mock_offset_hours  = 2;
$mock_http_requests = [];
$mock_http_response = http_answer( 201, [ 'id' => 5 ] );
$cr                 = ProviderFactory::create( 'cleverreach', [ 'cleverreach_token' => 't' ] );
$cr->add_subscriber( new SubscriberDTO( email: 'cr@example.de', blindedIp: 'x', consentHash: 'h', consentText: 't', listId: '9', confirmedAt: '2026-09-10 10:05:00', createdAt: '2026-09-10 10:00:00' ) );
$cr_payload = json_decode( (string) ( $mock_http_requests[0][1]['body'] ?? '' ), true ) ?: [];
assert_test( 'D9: registered and activated are the row times in UTC (both the push time until 2026-09-15)', strtotime( '2026-09-10 08:00:00 UTC' ) === ( $cr_payload['registered'] ?? null ) && strtotime( '2026-09-10 08:05:00 UTC' ) === ( $cr_payload['activated'] ?? null ), json_encode( $cr_payload ) );
$mock_http_requests = [];
$no_times           = $cr->add_subscriber( new SubscriberDTO( email: 'cr@example.de', blindedIp: 'x', consentHash: 'h', consentText: 't', listId: '9' ) );
assert_test( 'D9: without the row times nothing is sent', false === $no_times->success && 'missing_consent_time' === $no_times->errorCode && [] === $mock_http_requests );
reset_tables();
set_settings( [ 'active_provider' => 'cleverreach', 'cleverreach_token' => 't', 'default_list_id' => '9' ] );
$wpdb->insert( 'wp_wm_newsletter_doi_staging', array_merge( $q_base, [ 'email' => 'crq@example.de', 'status' => 'CONFIRMED', 'provider_adapter' => 'cleverreach', 'list_id' => '9', 'token_hash' => 'h5', 'created_at' => '2026-09-01 09:00:00', 'confirmed_at' => '2026-09-01 09:30:00' ] ) );
$mock_http_requests = [];
( new SyncQueue( new DoiRepository(), new SettingsManager() ) )->process_queue();
$crq_payload = json_decode( (string) ( $mock_http_requests[0][1]['body'] ?? '' ), true ) ?: [];
assert_test( 'D9: the queue passes the staging times to the adapter', strtotime( '2026-09-01 07:30:00 UTC' ) === ( $crq_payload['activated'] ?? null ), json_encode( $crq_payload ) );
$mock_offset_hours = 0;
set_settings( [ 'active_provider' => 'local', 'cleverreach_token' => '' ] );

// D10. The WordPress integration test refuses to run without the disposable-install flag.
$integration_src = (string) file_get_contents( ABSPATH . 'tests/test-newsletter-suite.php' );
$guard_at        = strpos( $integration_src, "getenv( 'WM_NL_INTEGRATION_DISPOSABLE' )" );
$first_write_at  = strpos( $integration_src, '$cleanup();' );
assert_test( 'D10: the integration test checks WM_NL_INTEGRATION_DISPOSABLE before its first write (no guard until 2026-09-15)', false !== $guard_at && false !== $first_write_at && $guard_at < $first_write_at && str_contains( $integration_src, 'exit( 2 )' ) );
assert_test( 'D10: its purge runs only when no row outside the test domain would be deleted', str_contains( $integration_src, '$foreign_expired' ) && (bool) preg_match( '/if \( 0 === \$foreign_expired \) \{\s*\$repo->purge_expired_pending\(/', $integration_src ) );

// D11. Dead code with claims.
$claims = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH . 'includes', FilesystemIterator::SKIP_DOTS ) ) as $src_file ) {
	$claims .= (string) file_get_contents( (string) $src_file );
}
$dead = [];
foreach ( [ 'LocalDatabaseProvider', 'BrevoAdapter', 'RapidmailAdapter', 'CleverReachAdapter', 'KitAdapter', 'MailchimpAdapter' ] as $provider_class ) {
	foreach ( [ 'getName', 'getId', 'isEuNative' ] as $method ) {
		if ( method_exists( 'WMCyber\\Newsletter\\Providers\\' . $provider_class, $method ) ) {
			$dead[] = $provider_class . '::' . $method;
		}
	}
}
assert_test( 'D11: no getName/getId/isEuNative, ProviderType, DoiState labels or DTO array helpers remain', [] === $dead && ! file_exists( ABSPATH . 'includes/Domain/Enum/ProviderType.php' ) && ! method_exists( 'WMCyber\\Newsletter\\Domain\\Enum\\DoiState', 'badge_color' ) && ! method_exists( SubscriberDTO::class, 'from_array' ), implode( ',', $dead ) );
assert_test( 'D11: no data-centre or sovereignty claims in the shipped classes', ! preg_match( '/Frankfurt|Freiburg|Rastede|Sovereign|Souverän|Zero-Cloud|Deutsches Rechenzentrum/', $claims ) );

// D12. Webhook input.
reset_tables();
$hook_key = ( new SettingsManager() )->get( 'webhook_secret' );
$hook     = new WebhookController( new DoiRepository(), new SettingsManager() );
$repo->upsert_subscriber_local( 'hook@example.de', 'x', 'h', 't', [], [], 'default', 'local', 'art_6_1_a', 'default' );
$hook_call = static function ( array $json ) use ( $hook ): int {
	$r = new WP_REST_Request( 'POST', '/wm-newsletter/v1/webhook' );
	$r->set_json_params( $json );
	try {
		return $hook->handle_remote_webhook( $r )->get_status();
	} catch ( Throwable $e ) {
		return -1;
	}
};
assert_test( 'D12: an array in "email" answers 200 and changes nothing (TypeError, 500, until 2026-09-15)', 200 === $hook_call( [ 'email' => [ 'hook@example.de' ] ] ) && 200 === $hook_call( [ 'data' => [ 'email' => [ 'hook@example.de' ] ] ] ) && 'subscribed' === ( $sub_row( 'hook@example.de' )->status ?? '' ) );
assert_test( 'D12: Brevo "opened" changes nothing', 200 === $hook_call( [ 'event' => 'opened', 'email' => 'hook@example.de' ] ) && 'subscribed' === ( $sub_row( 'hook@example.de' )->status ?? '' ) );
assert_test( 'D12: Brevo "spam" unsubscribes locally (ignored until 2026-09-15)', 200 === $hook_call( [ 'event' => 'spam', 'email' => 'hook@example.de' ] ) && 'unsubscribed' === ( $sub_row( 'hook@example.de' )->status ?? '' ) );
$repo->upsert_subscriber_local( 'hook@example.de', 'x', 'h', 't', [], [], 'default', 'local', 'art_6_1_a', 'default' );
assert_test( 'D12: Brevo "contact_deleted" unsubscribes locally (ignored until 2026-09-15)', 200 === $hook_call( [ 'event' => 'contact_deleted', 'email' => 'hook@example.de' ] ) && 'unsubscribed' === ( $sub_row( 'hook@example.de' )->status ?? '' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 17] v1.1.0 REST rate-limit callback, opt-in uninstall setting, hooks & ecosystem\n";
$mock_rest_routes = [];
$sub_ctrl         = make_controller();
$sub_ctrl->register_routes();
$all_guarded = true;
foreach ( [ '/wm-newsletter/v1/subscribe', '/wm-newsletter/v1/altcha-challenge', '/wm-newsletter/v1/unsubscribe' ] as $pub_route ) {
	$perm = $mock_rest_routes[ $pub_route ]['permission_callback'] ?? null;
	if ( [ $sub_ctrl, 'check_public_subscription_access' ] !== $perm ) {
		$all_guarded = false;
	}
}
assert_test( 'all three public REST routes use check_public_subscription_access instead of __return_true', $all_guarded );

$mock_transients         = [];
$mock_filters            = [];
$mock_can_manage_options = false;
$_SERVER['REMOTE_ADDR']  = '198.51.100.222';
add_filter( 'wm_newsletter_rest_rate_limit', static fn(): int => 3, 10, 2 );
$perm_req  = new WP_REST_Request( 'GET', '/wm-newsletter/v1/altcha-challenge' );
$r1        = $sub_ctrl->check_public_subscription_access( $perm_req );
$r2        = $sub_ctrl->check_public_subscription_access( $perm_req );
$r3        = $sub_ctrl->check_public_subscription_access( $perm_req );
$r4        = $sub_ctrl->check_public_subscription_access( $perm_req );
$rl_keys   = array_keys( $mock_transients );
$no_raw_ip = [] !== $rl_keys && ! str_contains( implode( '|', $rl_keys ) . json_encode( $mock_transients ), '198.51.100.222' ) && str_starts_with( $rl_keys[0], 'wm_nl_rl_' );
assert_test( 'check_public_subscription_access honours wm_newsletter_rest_rate_limit and returns 429 WP_Error on excess', true === $r1 && true === $r2 && true === $r3 && $r4 instanceof WP_Error && 'wm_newsletter_rate_limited' === $r4->code && 429 === ( $r4->get_error_data()['status'] ?? 0 ) );
assert_test( 'REST rate-limit transient uses HMAC-SHA256 key wm_nl_rl_* and never stores raw IP', $no_raw_ip, json_encode( $rl_keys ) );
$mock_can_manage_options = true;
assert_test( 'manage_options administrators bypass REST rate limit', true === $sub_ctrl->check_public_subscription_access( $perm_req ) );
$mock_can_manage_options = false;
$mock_filters            = [];

$mock_registered_settings = [];
$adm_dash                 = new AdminDashboard( new DoiRepository(), new SettingsManager(), new SyncQueue( new DoiRepository(), new SettingsManager() ) );
$adm_dash->boot();
do_action( 'admin_init' );
assert_test( 'AdminDashboard registers wm_newsletter_delete_data_on_uninstall setting on admin_init', isset( $mock_registered_settings['wm_newsletter_delete_data_on_uninstall'] ) );

reset_tables();
$mock_actions_fired = [];
$wpdb->insert( 'wp_wm_newsletter_doi_staging', [ 'email' => 'hooked@example.de', 'blinded_ip' => 'x', 'consent_snapshot_hash' => 'h', 'consent_text' => 't', 'attributes_json' => '[]', 'tags_json' => '[]', 'list_id' => 'default', 'provider_adapter' => 'local', 'legal_basis' => 'art_6_1_a', 'form_id' => 'default', 'status' => 'PENDING_CONFIRMATION', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'token_hash' => DoiRepository::hash_token( 'tok-hooked' ) ] );
$repo->confirm_token_atomic( 'tok-hooked', 48 );
$repo->confirm_token_atomic( 'tok-hooked', 48 ); // second click ("already") must not fire again
assert_test( 'wm_newsletter_confirmed action fires once on confirmation with email and record', 1 === count( $mock_actions_fired['wm_newsletter_confirmed'] ?? [] ) && 'hooked@example.de' === ( $mock_actions_fired['wm_newsletter_confirmed'][0][0] ?? '' ) );

$mock_filters = [];
add_filter( 'wm_newsletter_subscriber_payload', static function ( array $payload, string $email, string $provider ): array {
	$payload['tags'][] = 'via-' . $provider;
	return $payload;
}, 10, 3 );
reset_tables();
set_settings( [ 'active_provider' => 'brevo', 'brevo_api_key' => 'k' ] );
$wpdb->insert( 'wp_wm_newsletter_doi_staging', [ 'email' => 'filtered@example.de', 'blinded_ip' => 'x', 'consent_snapshot_hash' => 'h', 'consent_text' => 't', 'attributes_json' => '[]', 'tags_json' => '[]', 'list_id' => '7', 'provider_adapter' => 'brevo', 'legal_basis' => 'art_6_1_a', 'form_id' => 'default', 'status' => 'CONFIRMED', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'confirmed_at' => gmdate( 'Y-m-d H:i:s' ), 'token_hash' => 'tf1' ] );
$mock_http_requests = [];
$mock_http_response = http_answer( 201, [ 'id' => 77 ] );
$seen_dto           = null;
( new SyncQueue( new DoiRepository(), new SettingsManager() ) )->process_queue();
set_settings( [ 'active_provider' => 'local', 'brevo_api_key' => '' ] );
$mock_filters = [];
reset_tables();
add_filter( 'wm_newsletter_subscriber_payload', static function ( array $payload ): array {
	$payload['list_id'] = '888';
	return $payload;
}, 10, 3 );
set_settings( [ 'active_provider' => 'rapidmail', 'rapidmail_username' => 'u', 'rapidmail_password' => 'p' ] );
$wpdb->insert( 'wp_wm_newsletter_doi_staging', [ 'email' => 'filtered2@example.de', 'blinded_ip' => 'x', 'consent_snapshot_hash' => 'h', 'consent_text' => 't', 'attributes_json' => '[]', 'tags_json' => '[]', 'list_id' => '111', 'provider_adapter' => 'rapidmail', 'legal_basis' => 'art_6_1_a', 'form_id' => 'default', 'status' => 'CONFIRMED', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'confirmed_at' => gmdate( 'Y-m-d H:i:s' ), 'token_hash' => 'tf2' ] );
$mock_http_requests = [];
$mock_http_response = http_answer( 201, [ 'id' => 88 ] );
( new SyncQueue( new DoiRepository(), new SettingsManager() ) )->process_queue();
$rm_body = json_decode( (string) ( $mock_http_requests[0][1]['body'] ?? '' ), true ) ?: [];
assert_test( 'wm_newsletter_subscriber_payload filter customises outgoing sync payload without touching stored consent', 888 === ( $rm_body['recipientlist_id'] ?? null ), json_encode( $rm_body ) );
set_settings( [ 'active_provider' => 'local', 'rapidmail_username' => '', 'rapidmail_password' => '' ] );
$mock_filters = [];

reset_tables();
$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => 's1@example.de', 'status' => 'subscribed' ] );
$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => 's2@example.de', 'status' => 'subscribed' ] );
$wpdb->insert( 'wp_wm_newsletter_subscribers', [ 'email' => 's3@example.de', 'status' => 'subscribed' ] );
$mock_block_bindings = [];
$mock_gb_tags        = [];
$mock_actions_fired  = [];
$mock_did_init       = true;
Plugin::instance()->boot();
assert_test( 'Plugin::boot fires wm_newsletter_ready', 1 === count( $mock_actions_fired['wm_newsletter_ready'] ?? [] ) );
assert_test( 'WP 6.5+ Block Bindings source wm-newsletter/stats registered and returns subscriber count', isset( $mock_block_bindings['wm-newsletter/stats'] ) && '3' === ( $mock_block_bindings['wm-newsletter/stats']['get_value_callback'] )( [ 'key' => 'active_subscribers' ] ) );
assert_test( 'GenerateBlocks 2.x Dynamic Tag wm_newsletter_stat registered and renders escaped count', isset( $mock_gb_tags['wm_newsletter_stat'] ) && '3' === ( $mock_gb_tags['wm_newsletter_stat']['return'] )( [ 'key' => 'active_subscribers' ] ) && '3' === apply_filters( 'generateblocks_dynamic_tag_replacement', '', [ 'tag' => 'wm_newsletter_stat', 'options' => [ 'key' => 'unknown_metric' ] ] ) && 'Post Title' === apply_filters( 'generateblocks_dynamic_tag_output', 'Post Title', [ 'source' => 'post' ], 'Post Title' ) && 'Post Title' === ( new \WMCyber\Newsletter\Frontend\EcosystemIntegrations( Plugin::instance()->getDoiRepository() ) )->filter_generateblocks_tag( 'Post Title', [ 'source' => 'post' ], 'Post Title' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 18] v1.1.2 Multisite activation, wp_initialize_site & option autoload\n";
$mock_filters['wp_initialize_site'] = $wm_boot_filters['wp_initialize_site'] ?? [];
assert_test( 'wp_initialize_site action is registered in main plugin bootstrap', ! empty( $mock_filters['wp_initialize_site'] ) );

// 18.1 Single-site: wp_initialize_site does nothing.
$mock_is_multisite           = false;
$mock_network_active_plugins = [ WM_NEWSLETTER_PLUGIN_BASENAME ];
$mock_current_blog_id        = 1;
$mock_blog_stack             = [];
$mock_blog_state             = [];
$mock_blog_switches          = [];
$mock_blog_restores          = [];
$mock_dbdelta_queries        = [];
unset( $mock_db_tables['wp_2_wm_newsletter_doi_staging'], $mock_db_tables['wp_2_wm_newsletter_subscribers'] );
do_action( 'wp_initialize_site', (object) [ 'blog_id' => 2 ] );
assert_test( 'wp_initialize_site is a no-op when not multisite', [] === $mock_blog_switches && ! isset( $mock_db_tables['wp_2_wm_newsletter_doi_staging'] ) );

// 18.2 Multisite, but plugin NOT network-activated: wp_initialize_site does nothing.
$mock_is_multisite           = true;
$mock_network_active_plugins = [];
do_action( 'wp_initialize_site', (object) [ 'blog_id' => 2 ] );
assert_test( 'wp_initialize_site is a no-op when plugin is not network-active', [] === $mock_blog_switches && ! isset( $mock_db_tables['wp_2_wm_newsletter_doi_staging'] ) );

// 18.3 Multisite + network-active: switches to blog 2, creates tables + non-autoload settings + cleanup cron, restores blog 1.
$mock_network_active_plugins = [ WM_NEWSLETTER_PLUGIN_BASENAME ];
$mock_options                = [ 'admin_email' => 'admin@example.de' ];
$mock_option_autoload        = [];
$mock_cron                   = [];
do_action( 'wp_initialize_site', (object) [ 'blog_id' => 2 ] );
assert_test(
	'wp_initialize_site activates new blog with switch_to_blog/restore_current_blog and dbDelta tables',
	[ 2 ] === $mock_blog_switches
	&& [ 2 ] === $mock_blog_restores
	&& 1 === $mock_current_blog_id
	&& 'wp_' === $wpdb->prefix
	&& isset( $mock_db_tables['wp_2_wm_newsletter_doi_staging'], $mock_db_tables['wp_2_wm_newsletter_subscribers'] )
);
assert_test(
	'wp_initialize_site stores new blog settings with autoload=false and schedules hourly cleanup',
	is_array( $mock_blog_state[2]['options']['wm_newsletter_settings'] ?? null )
	&& false === ( $mock_blog_state[2]['autoload']['wm_newsletter_settings'] ?? null )
	&& isset( $mock_blog_state[2]['cron'][ SyncQueue::CLEANUP_HOOK ] )
	&& ! isset( $mock_options['wm_newsletter_settings'] )
);

// 18.4 Exception inside Activator::activate() during wp_initialize_site still restores blog via finally.
$mock_blog_switches               = [];
$mock_blog_restores               = [];
$mock_filters['cron_schedules'][] = [ static function () {
	throw new RuntimeException( 'simulated activation failure' );
}, 1 ];
$caught_init_failure = false;
try {
	do_action( 'wp_initialize_site', (object) [ 'blog_id' => 3 ] );
} catch ( RuntimeException $e ) {
	$caught_init_failure = ( 'simulated activation failure' === $e->getMessage() );
}
array_pop( $mock_filters['cron_schedules'] );
assert_test(
	'wp_initialize_site restores current blog in finally block even if activation throws',
	$caught_init_failure && 1 === $mock_current_blog_id && 'wp_' === $wpdb->prefix && [] === $mock_blog_stack && [ 3 ] === $mock_blog_restores
);

// 18.5 Network-wide activation via Activator::activate( true ) initializes every site in get_sites().
$mock_sites                  = [ 1, 4, 5 ];
$mock_current_blog_id        = 1;
$mock_blog_stack             = [];
$mock_blog_state             = [];
$mock_blog_switches          = [];
$mock_blog_restores          = [];
$mock_options                = [ 'admin_email' => 'admin@example.de' ];
$mock_option_autoload        = [];
$mock_cron                   = [];
unset(
	$mock_db_tables['wp_4_wm_newsletter_doi_staging'],
	$mock_db_tables['wp_4_wm_newsletter_subscribers'],
	$mock_db_tables['wp_5_wm_newsletter_doi_staging'],
	$mock_db_tables['wp_5_wm_newsletter_subscribers']
);
Activator::activate( true );
assert_test(
	'Activator::activate( true ) initializes all network sites and restores original blog',
	[ 1, 4, 5 ] === $mock_blog_switches
	&& [ 1, 4, 5 ] === $mock_blog_restores
	&& 1 === $mock_current_blog_id
	&& 'wp_' === $wpdb->prefix
	&& isset( $mock_db_tables['wp_4_wm_newsletter_doi_staging'], $mock_db_tables['wp_5_wm_newsletter_subscribers'] )
	&& false === ( $mock_option_autoload['wm_newsletter_settings'] ?? null )
	&& false === ( $mock_blog_state[4]['autoload']['wm_newsletter_settings'] ?? null )
	&& false === ( $mock_blog_state[5]['autoload']['wm_newsletter_settings'] ?? null )
);

// 18.6 Single-site Activator::activate() and SettingsManager::update_all() pass autoload=false explicitly.
$mock_is_multisite    = false;
$mock_options         = [ 'admin_email' => 'admin@example.de' ];
$mock_option_autoload = [];
$mock_cron            = [];
Activator::activate();
assert_test( 'Activator::activate() stores default settings with autoload=false', false === ( $mock_option_autoload['wm_newsletter_settings'] ?? null ) );

$mock_options         = [];
$mock_option_autoload = [];
( new SettingsManager() )->update_all( [ 'active_provider' => 'local', 'doi_ttl_hours' => 24 ] );
assert_test( 'SettingsManager::update_all() stores wm_newsletter_settings with autoload=false', false === ( $mock_option_autoload['wm_newsletter_settings'] ?? null ) && 24 === ( $mock_options['wm_newsletter_settings']['doi_ttl_hours'] ?? 0 ) );

// 18.7 Stored provider credentials are never echoed in admin DOM, stay intact on blank submit, clear via clear_secrets, and respect WM_NEWSLETTER_* constants.
$mock_options         = [ 'admin_email' => 'admin@example.de' ];
$mock_option_autoload = [];
$_GET['tab']          = 'settings';
$sm_sec               = new SettingsManager();
$dash_sec             = new AdminDashboard( new DoiRepository(), $sm_sec, new SyncQueue( new DoiRepository(), $sm_sec ) );
$sm_sec->update_all( [ 'active_provider' => 'brevo', 'brevo_api_key' => 'xkeysib-secret-dom-check-99' ] );
ob_start();
$dash_sec->render_dashboard();
$masked_html = (string) ob_get_clean();
assert_test(
	'Admin settings HTML masks stored Brevo key (never echoes value, uses placeholder)',
	! str_contains( $masked_html, 'xkeysib-secret-dom-check-99' )
		&& (bool) preg_match( '/<input[^>]*id="wm-nl-brevo"[^>]*value=""[^>]*placeholder="[^"]+"/', $masked_html )
		&& str_contains( $masked_html, 'name="wm_settings[clear_secrets][brevo_api_key]"' )
);
$dash_sec->save_submitted_settings( [ 'active_provider' => 'brevo', 'brevo_api_key' => '', 'sender_name' => 'Updated' ] );
assert_test( 'blank secret submit preserves existing stored API key', 'xkeysib-secret-dom-check-99' === (string) $sm_sec->get( 'brevo_api_key' ) && 'Updated' === (string) $sm_sec->get( 'sender_name' ) );
$dash_sec->save_submitted_settings( [ 'active_provider' => 'brevo', 'brevo_api_key' => '', 'clear_secrets' => [ 'brevo_api_key' => '1' ] ] );
assert_test( 'clear_secrets checkbox erases stored API key cleanly', '' === (string) $sm_sec->get( 'brevo_api_key' ) );
define( 'WM_NEWSLETTER_BREVO_API_KEY', 'env-brevo-override-2026' );
ob_start();
$dash_sec->render_dashboard();
$env_html = (string) ob_get_clean();
assert_test(
	'WM_NEWSLETTER_BREVO_API_KEY overrides stored setting cleanly and locks HTML input without leaking secret',
	'env-brevo-override-2026' === (string) $sm_sec->get( 'brevo_api_key' )
		&& $sm_sec->has_env_constant( 'brevo_api_key' )
		&& ! str_contains( $env_html, 'env-brevo-override-2026' )
		&& str_contains( $env_html, 'WM_NEWSLETTER_BREVO_API_KEY' )
		&& (bool) preg_match( '/<input[^>]*id="wm-nl-brevo"[^>]*disabled="disabled"/', $env_html )
);
unset( $_GET['tab'] );

// ---------------------------------------------------------------------------------------------------
print_results();
@unlink( $wm_test_log );

exit( 0 === $failed_count ? 0 : 1 );
