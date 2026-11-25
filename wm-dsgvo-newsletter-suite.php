<?php
/**
 * Plugin Name:       WM DSGVO Newsletter Suite
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-dsgvo-newsletter-suite/
 * Description:       Newsletter-Anmeldung mit Double-Opt-In: Formular mit Einwilligungs-Checkbox und Proof-of-Work, Bestätigungsmail, Protokoll je Anmeldung (Einwilligungstext, Hash, geschlüsselter IP-Hash) und serverseitige Übergabe bestätigter Adressen an Brevo, Rapidmail, CleverReach, Kit oder Mailchimp. Keine rechtliche Bewertung.
 * Version:           1.2.2
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-dsgvo-newsletter-suite
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace WMCyber\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WM_NEWSLETTER_VERSION', '1.2.2' );
define( 'WM_NEWSLETTER_FILE', __FILE__ );
define( 'WM_NEWSLETTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'WM_NEWSLETTER_URL', plugin_dir_url( __FILE__ ) );
define( 'WM_NEWSLETTER_PLUGIN_DIR', WM_NEWSLETTER_PATH );
define( 'WM_NEWSLETTER_PLUGIN_URL', WM_NEWSLETTER_URL );
define( 'WM_NEWSLETTER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader
spl_autoload_register( function ( string $class ): void {
	$prefix = 'WMCyber\\Newsletter\\';
	$base_dir = WM_NEWSLETTER_PATH . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Lifecycle Hooks
register_activation_hook( __FILE__, [ Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Core\Deactivator::class, 'deactivate' ] );

// Initialize newly created Multisite blogs when network-activated.
add_action( 'wp_initialize_site', function ( $new_site ): void {
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		return;
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}
	if ( ! is_object( $new_site ) || empty( $new_site->blog_id ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	try {
		Core\Activator::activate();
	} finally {
		restore_current_blog();
	}
} );

// Register as Spoke in WM Suite Hub
add_filter( 'wm_register_suite_module', function ( array $modules ): array {
	if ( ! class_exists( 'WMCyber\Newsletter\Adapters\Newsletter_Spoke_Adapter' ) && file_exists( WM_NEWSLETTER_PATH . 'includes/Adapters/Newsletter_Spoke_Adapter.php' ) ) {
		require_once WM_NEWSLETTER_PATH . 'includes/Adapters/Newsletter_Spoke_Adapter.php';
	}
	if ( class_exists( 'WMCyber\Newsletter\Adapters\Newsletter_Spoke_Adapter' ) ) {
		$modules[] = new Adapters\Newsletter_Spoke_Adapter();
	}
	return $modules;
} );

add_action( 'plugins_loaded', function (): void {
	Core\Plugin::instance()->boot();
} );

