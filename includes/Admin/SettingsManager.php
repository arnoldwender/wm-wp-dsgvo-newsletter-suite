<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings in the option `wm_newsletter_settings`.
 *
 * Until 2026-09-15 two secrets came from the defaults on every read: `webhook_secret` was a new
 * wp_generate_password() each time, so no provider webhook could ever authenticate; and
 * `altcha_hmac_key` fell back to wp_salt( 'auth' ), which the first settings save then copied into the
 * options table. Both are now generated once, stored, and never derived from the WordPress salts.
 */
final class SettingsManager {
	private const OPTION_KEY        = 'wm_newsletter_settings';
	public const DELETE_DATA_OPTION = 'wm_newsletter_delete_data_on_uninstall';

	/** Generated once per site and stored; never shown outside the settings screen. */
	private const STORED_SECRETS = [
		'webhook_secret'  => 32,
		'altcha_hmac_key' => 64,
	];

	public const PROVIDERS = [ 'local', 'brevo', 'rapidmail', 'cleverreach', 'kit', 'mailchimp' ];

	public function get_all(): array {
		$defaults = [
			'active_provider'      => 'local',
			'doi_ttl_hours'        => 48,
			'sender_name'          => get_bloginfo( 'name' ),
			'sender_email'         => get_option( 'admin_email' ),
			'altcha_enabled'       => 1,
			'default_list_id'      => 'default',
			'enable_rfc8058'       => 1,
			'legal_notice_url'     => home_url( '/impressum/' ),
			'privacy_policy_url'   => home_url( '/datenschutz/' ),
			'doi_email_subject'    => __( 'Bitte bestätigen Sie Ihre Anmeldung', 'wm-dsgvo-newsletter-suite' ),
			'brevo_api_key'        => '',
			'rapidmail_username'   => '',
			'rapidmail_password'   => '',
			'cleverreach_token'    => '',
			'kit_api_key'          => '',
			'mailchimp_api_key'    => '',
		];

		$stored = get_option( self::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$missing = false;
		foreach ( self::STORED_SECRETS as $key => $length ) {
			if ( empty( $stored[ $key ] ) || ! is_string( $stored[ $key ] ) ) {
				$stored[ $key ] = wp_generate_password( $length, false, false );
				$missing        = true;
			}
		}
		if ( $missing ) {
			update_option( self::OPTION_KEY, $stored );
		}

		return array_merge( $defaults, $stored );
	}

	public function get( string $key ): mixed {
		$all = $this->get_all();
		return $all[ $key ] ?? null;
	}

	/** Confirmation window in hours, clamped to what the settings screen allows (1–168). */
	public function ttl_hours(): int {
		return max( 1, min( 168, (int) ( $this->get( 'doi_ttl_hours' ) ?: 48 ) ) );
	}

	/**
	 * Settings the active provider needs and that are empty, as setting keys. The local provider
	 * needs none. Rapidmail, CleverReach and Mailchimp refuse the list id "default".
	 *
	 * @return string[]
	 */
	public function missing_provider_settings(): array {
		$all      = $this->get_all();
		$provider = (string) ( $all['active_provider'] ?? 'local' );
		$required = match ( $provider ) {
			'brevo'       => [ 'brevo_api_key' ],
			'rapidmail'   => [ 'rapidmail_username', 'rapidmail_password', 'default_list_id' ],
			'cleverreach' => [ 'cleverreach_token', 'default_list_id' ],
			'kit'         => [ 'kit_api_key' ],
			'mailchimp'   => [ 'mailchimp_api_key', 'default_list_id' ],
			default       => [],
		};

		$missing = [];
		foreach ( $required as $key ) {
			$value = trim( (string) ( $all[ $key ] ?? '' ) );
			if ( '' === $value || ( 'default_list_id' === $key && 'default' === $value ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	public function update_all( array $data ): bool {
		$current = $this->get_all();
		$updated = array_merge( $current, $data );
		return update_option( self::OPTION_KEY, $updated, false );
	}

	/** Explicit opt-in flag controlling whether uninstall.php removes options and DOI tables. */
	public function register_settings(): void {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}
		register_setting(
			'wm_newsletter_settings_group',
			'wm_newsletter_delete_data_on_uninstall',
			[
				'type'              => 'boolean',
				'sanitize_callback' => static fn( $value ): int => ! empty( $value ) ? 1 : 0,
				'default'           => 0,
			]
		);
	}

	public function delete_data_on_uninstall(): bool {
		return ! empty( get_option( 'wm_newsletter_delete_data_on_uninstall', 0 ) );
	}

	public function set_delete_data_on_uninstall( bool $enabled ): bool {
		return update_option( 'wm_newsletter_delete_data_on_uninstall', $enabled ? 1 : 0, false );
	}
}
