<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Admin\SettingsManager;
use WMCyber\Newsletter\Database\DoiRepository;

final class DoiMailer {
	private SettingsManager $settings;

	public function __construct( SettingsManager $settings ) {
		$this->settings = $settings;
	}

	/**
	 * RFC 8058 target: the address plus its HMAC token, so only the holder of the mail
	 * can unsubscribe that address (fleet audit 2026-09-13 §6.2 #9).
	 *
	 * add_query_arg() does not encode values. Until 2026-09-15 the address went in raw, so the `+` of
	 * anna+news@example.de arrived as a space, sanitize_email() dropped it and the token no longer
	 * matched: the link answered 403 for every plus address.
	 */
	public static function build_unsubscribe_url( string $email ): string {
		return add_query_arg(
			[
				'email' => rawurlencode( $email ),
				'token' => DoiRepository::unsubscribe_token( $email ),
			],
			rest_url( 'wm-newsletter/v1/unsubscribe' )
		);
	}

	/** The confirmation mail; its deletion sentence states the configured window (until 2026-09-15 a fixed 48 hours). */
	public function send_doi_confirmation( string $email, string $plainToken ): bool {
		$confirmUrl = add_query_arg( [
			'wm_action' => 'confirm_newsletter',
			'token'     => $plainToken,
		], home_url( '/' ) );

		$settings    = $this->settings->get_all();
		$senderName  = $settings['sender_name'] ?: get_bloginfo( 'name' );
		$senderEmail = $settings['sender_email'] ?: get_option( 'admin_email' );
		$subject     = $settings['doi_email_subject'] ?: __( 'Bitte bestätigen Sie Ihre Anmeldung', 'wm-dsgvo-newsletter-suite' );

		$legalNoticeUrl   = $settings['legal_notice_url'] ?: home_url( '/impressum/' );
		$privacyPolicyUrl = $settings['privacy_policy_url'] ?: home_url( '/datenschutz/' );
		$ttlHours         = $this->settings->ttl_hours();

		$deletionNote = sprintf(
			/* translators: %d: hours an unconfirmed address is kept */
			__( 'Falls Sie diese Anfrage nicht ausgelöst haben, können Sie diese E-Mail ignorieren. Ohne Bestätigung wird Ihre Adresse nach %d Stunden gelöscht.', 'wm-dsgvo-newsletter-suite' ),
			$ttlHours
		);

		// The mail carries only the confirmation: no advertising, surveys or other content.
		$body = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n<title>" . esc_html( $subject ) . "</title>\n</head>\n"
			. "<body style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; padding: 24px; line-height: 1.6;\">\n"
			. "  <div style=\"max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);\">\n"
			. "    <h2 style=\"margin-top: 0; font-size: 1.3rem; color: #0f172a;\">" . esc_html__( 'Bestätigung Ihrer E-Mail-Adresse', 'wm-dsgvo-newsletter-suite' ) . "</h2>\n"
			. "    <p style=\"font-size: 0.95rem; color: #334155;\">" . esc_html__( 'Sie haben sich mit dieser E-Mail-Adresse für unseren Newsletter angemeldet. Um Ihre Anmeldung abzuschließen und den Empfang zu aktivieren, klicken Sie bitte auf den folgenden Bestätigungslink:', 'wm-dsgvo-newsletter-suite' ) . "</p>\n"
			. "    <div style=\"margin: 28px 0; text-align: center;\">\n"
			. "      <a href=\"" . esc_url( $confirmUrl ) . "\" style=\"background-color: #0284c7; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 700; font-size: 0.95rem; display: inline-block;\">"
			. esc_html__( 'Anmeldung bestätigen', 'wm-dsgvo-newsletter-suite' )
			. "      </a>\n"
			. "    </div>\n"
			. "    <p style=\"font-size: 0.85rem; color: #64748b; margin-top: 24px;\">" . esc_html( $deletionNote ) . "</p>\n"
			. "    <hr style=\"border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;\">\n"
			. "    <div style=\"font-size: 0.78rem; color: #94a3b8; line-height: 1.5;\">\n"
			. "      <strong>" . esc_html( $senderName ) . "</strong><br>\n"
			. "      <a href=\"" . esc_url( $legalNoticeUrl ) . "\" style=\"color: #64748b; text-decoration: underline;\">" . esc_html__( 'Impressum', 'wm-dsgvo-newsletter-suite' ) . "</a> · "
			. "      <a href=\"" . esc_url( $privacyPolicyUrl ) . "\" style=\"color: #64748b; text-decoration: underline;\">" . esc_html__( 'Datenschutzerklärung', 'wm-dsgvo-newsletter-suite' ) . "</a>\n"
			. "    </div>\n"
			. "  </div>\n"
			. "</body>\n</html>";

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			"From: {$senderName} <{$senderEmail}>",
			"Reply-To: {$senderEmail}",
			'X-Auto-Response-Suppress: OOF, AutoReply',
		];

		// Inject RFC 8058 One-Click Unsubscribe headers
		if ( ! empty( $settings['enable_rfc8058'] ) ) {
			$unsubUrl   = self::build_unsubscribe_url( $email );
			$headers[] = "List-Unsubscribe: <{$unsubUrl}>";
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		return wp_mail( $email, $subject, $body, $headers );
	}
}
