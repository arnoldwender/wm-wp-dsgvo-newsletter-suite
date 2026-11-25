<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proof-of-work check for the subscription form (ALTCHA-style payload).
 *
 * The server hands out `challenge = SHA-256( salt . secret )` with an HMAC signature; the browser
 * searches the number 0…maxnumber whose hash matches and sends it back base64-encoded as JSON
 * `{algorithm, challenge, number, salt, signature}` (assets/js/frontend-form.js).
 *
 * Until 2026-09-15 no browser code solved the challenge, so with the default setting every form
 * submission was answered 403; and a solved payload carried no expiry and could be replayed forever.
 * The salt now ends in `?expires=<unix time>` and each accepted challenge is remembered until then.
 */
final class AltchaVerifier {
	/** Seconds a challenge can be solved and submitted. */
	public const CHALLENGE_TTL = 600;

	public static function create_challenge( string $hmacKey, int $maxNumber = 50000 ): array {
		$expires = time() + self::CHALLENGE_TTL;
		$salt    = bin2hex( random_bytes( 12 ) ) . '?expires=' . $expires;
		$secret  = random_int( 0, $maxNumber );

		$challenge = hash( 'sha256', $salt . $secret );
		$signature = hash_hmac( 'sha256', $challenge, $hmacKey );

		return [
			'algorithm' => 'SHA-256',
			'challenge' => $challenge,
			'salt'      => $salt,
			'signature' => $signature,
			'maxnumber' => $maxNumber,
		];
	}

	/**
	 * @param string $payloadBase64 Base64 JSON from the browser.
	 * @param string $hmacKey       Key the challenge was signed with.
	 * @param bool   $consume       Remember the challenge so the same payload is refused next time.
	 */
	public static function verify( string $payloadBase64, string $hmacKey, bool $consume = true ): bool {
		$json = base64_decode( $payloadBase64, true );
		if ( ! $json ) {
			return false;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['challenge'] ) || empty( $data['signature'] ) || ! isset( $data['number'] ) || empty( $data['salt'] ) ) {
			return false;
		}
		if ( ! is_string( $data['challenge'] ) || ! is_string( $data['signature'] ) || ! is_string( $data['salt'] ) || ! is_numeric( $data['number'] ) ) {
			return false;
		}

		// 1. Signature: the challenge was issued by this site.
		$expectedSignature = hash_hmac( 'sha256', $data['challenge'], $hmacKey );
		if ( ! hash_equals( $expectedSignature, $data['signature'] ) ) {
			return false;
		}

		// 2. Expiry carried in the salt (covered by the signature through the challenge hash).
		if ( ! preg_match( '/[?&]expires=(\d+)/', $data['salt'], $m ) || (int) $m[1] < time() ) {
			return false;
		}

		// 3. The work: SHA-256( salt . number ) must reproduce the challenge.
		$computedChallenge = hash( 'sha256', $data['salt'] . (string) (int) $data['number'] );
		if ( ! hash_equals( $data['challenge'], $computedChallenge ) ) {
			return false;
		}

		// 4. Single use until the challenge would have expired anyway.
		$usedKey = 'wm_nl_altcha_' . substr( $data['challenge'], 0, 40 );
		if ( false !== get_transient( $usedKey ) ) {
			return false;
		}
		if ( $consume ) {
			set_transient( $usedKey, 1, max( 1, (int) $m[1] - time() ) );
		}

		return true;
	}

	/** Server-side solver, for tests and the adapter self test. */
	public static function solve( array $challenge ): ?int {
		$max = (int) ( $challenge['maxnumber'] ?? 0 );
		for ( $n = 0; $n <= $max; $n++ ) {
			if ( hash_equals( (string) $challenge['challenge'], hash( 'sha256', $challenge['salt'] . $n ) ) ) {
				return $n;
			}
		}
		return null;
	}
}
