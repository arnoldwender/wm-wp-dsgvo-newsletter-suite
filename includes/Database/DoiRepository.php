<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Domain\Enum\DoiState;

final class DoiRepository {
	private string $stagingTable;
	private string $subsTable;

	public function __construct() {
		$this->stagingTable = Schema::get_staging_table_name();
		$this->subsTable    = Schema::get_subscribers_table_name();
	}

	public static function blind_ip( string $ip ): string {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : wp_salt( 'auth' );
		return hash_hmac( 'sha256', trim( $ip ), $salt );
	}

	public static function hash_token( string $plainToken ): string {
		return hash( 'sha256', trim( $plainToken ) );
	}

	/**
	 * Per-subscriber unsubscribe token. Derived, not stored: the mail carries it, the
	 * route recomputes it. Rotating AUTH salts invalidates older links, which is the
	 * accepted trade-off (the ESP's own unsubscribe keeps working).
	 */
	public static function unsubscribe_token( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}

	public static function hash_consent( string $text ): string {
		return hash( 'sha256', trim( $text ) );
	}

	/**
	 * Cutoff for the confirmation window in the same clock as `created_at`.
	 *
	 * Rows are written with current_time( 'mysql' ), i.e. the site's local time. Until 2026-09-15 the
	 * cutoff was gmdate(), so on a site in Europe/Berlin a link stayed valid two hours longer than
	 * configured (one in winter) and on sites west of UTC it expired early.
	 */
	public static function cutoff_for( int $ttlHours ): string {
		return wp_date( 'Y-m-d H:i:s', time() - ( max( 1, $ttlHours ) * HOUR_IN_SECONDS ) );
	}

	public function create_pending_doi(
		string $email,
		string $clientIp,
		string $consentText,
		array $attributes = [],
		array $tags = [],
		string $listId = 'default',
		string $provider = 'local',
		string $legalBasis = 'art_6_1_a',
		string $formId = 'default'
	): array {
		global $wpdb;

		$plainToken  = bin2hex( random_bytes( 32 ) );
		$tokenHash   = self::hash_token( $plainToken );
		$blindedIp   = self::blind_ip( $clientIp );
		$consentHash = self::hash_consent( $consentText );

		$inserted = $wpdb->insert(
			$this->stagingTable,
			[
				'email'                 => $email,
				'token_hash'            => $tokenHash,
				'blinded_ip'            => $blindedIp,
				'consent_snapshot_hash' => $consentHash,
				'consent_text'          => $consentText,
				'attributes_json'       => wp_json_encode( $attributes ),
				'tags_json'             => wp_json_encode( $tags ),
				'list_id'               => $listId,
				'provider_adapter'      => $provider,
				'legal_basis'           => $legalBasis,
				'form_id'               => $formId,
				'status'                => DoiState::PENDING_CONFIRMATION->value,
				'created_at'            => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		// id 0 tells the caller nothing was stored. Until 2026-09-15 the insert result was ignored, and the
		// earlier pending row of the address was deleted first, so a refused insert also killed a valid link.
		$newId = (int) $wpdb->insert_id;
		if ( false === $inserted || $newId < 1 ) {
			return [
				'id'          => 0,
				'plain_token' => '',
				'token_hash'  => '',
				'email'       => $email,
			];
		}

		// Only the new row stays pending for this address.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- replaces older pending sign-ups of the address.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE email = %s AND status = %s AND id <> %d',
				$this->stagingTable,
				$email,
				DoiState::PENDING_CONFIRMATION->value,
				$newId
			)
		);

		return [
			'id'          => $newId,
			'plain_token' => $plainToken,
			'token_hash'  => $tokenHash,
			'email'       => $email,
		];
	}

	public function confirm_token_atomic( string $plainToken, int $ttlHours = 48 ): array {
		global $wpdb;

		$tokenHash    = self::hash_token( $plainToken );
		$expiryCutoff = self::cutoff_for( $ttlHours );

		// Atomic transaction with pessimistic locking
		$wpdb->query( 'START TRANSACTION' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE token_hash = %s FOR UPDATE',
				$this->stagingTable,
				$tokenHash
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$wpdb->query( 'ROLLBACK' );
			return [
				'success' => false,
				'code'    => 404,
				'message' => __( 'Ungültiger oder nicht gefundener Bestätigungstoken.', 'wm-dsgvo-newsletter-suite' ),
			];
		}

		// SYNC_FAILED is a confirmed row whose push failed; until 2026-09-15 a second click on it read "no longer valid".
		if ( in_array( $row['status'], [ DoiState::CONFIRMED->value, DoiState::SYNCED_TO_PROVIDER->value, DoiState::SYNC_FAILED->value ], true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return [
				'success' => true,
				'already' => true,
				'code'    => 200,
				'message' => __( 'Ihre E-Mail-Adresse wurde bereits erfolgreich bestätigt.', 'wm-dsgvo-newsletter-suite' ),
				'record'  => $row,
			];
		}

		if ( $row['status'] !== DoiState::PENDING_CONFIRMATION->value ) {
			$wpdb->query( 'ROLLBACK' );
			return [
				'success' => false,
				'code'    => 410,
				'message' => __( 'Dieser Bestätigungslink ist nicht mehr gültig.', 'wm-dsgvo-newsletter-suite' ),
			];
		}

		if ( strtotime( $row['created_at'] ) < strtotime( $expiryCutoff ) ) {
			$wpdb->update(
				$this->stagingTable,
				[ 'status' => DoiState::EXPIRED->value ],
				[ 'id' => $row['id'] ],
				[ '%s' ],
				[ '%d' ]
			);
			$wpdb->query( 'COMMIT' );
			return [
				'success' => false,
				'code'    => 410,
				'message' => sprintf(
					/* translators: %d: hours a confirmation link stays valid */
					__( 'Der Bestätigungslink ist abgelaufen (gültig %d Stunden). Bitte melden Sie sich erneut an.', 'wm-dsgvo-newsletter-suite' ),
					max( 1, $ttlHours )
				),
			];
		}

		// Transition state to CONFIRMED
		$now = current_time( 'mysql' );
		$wpdb->update(
			$this->stagingTable,
			[
				'status'       => DoiState::CONFIRMED->value,
				'confirmed_at' => $now,
			],
			[ 'id' => $row['id'] ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		// Also persist/update in local subscribers table
		$this->upsert_subscriber_local(
			$row['email'],
			$row['blinded_ip'],
			$row['consent_snapshot_hash'],
			$row['consent_text'],
			json_decode( $row['attributes_json'] ?? '[]', true ) ?: [],
			json_decode( $row['tags_json'] ?? '[]', true ) ?: [],
			$row['list_id'],
			$row['provider_adapter'],
			$row['legal_basis'],
			$row['form_id']
		);

		$wpdb->query( 'COMMIT' );

		$row['status']       = DoiState::CONFIRMED->value;
		$row['confirmed_at'] = $now;

		do_action( 'wm_newsletter_confirmed', (string) $row['email'], $row );

		return [
			'success' => true,
			'code'    => 200,
			'message' => __( 'Vielen Dank! Ihre E-Mail-Adresse wurde erfolgreich bestätigt.', 'wm-dsgvo-newsletter-suite' ),
			'record'  => $row,
		];
	}

	public function upsert_subscriber_local(
		string $email,
		string $blindedIp,
		string $consentHash,
		string $consentText,
		array $attributes,
		array $tags,
		string $listId,
		string $provider,
		string $legalBasis,
		string $formId
	): void {
		global $wpdb;

		$existing = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $this->subsTable, $email )
		);

		if ( $existing ) {
			$wpdb->update(
				$this->subsTable,
				[
					'blinded_ip'       => $blindedIp,
					'status'           => 'subscribed',
					'attributes_json'  => wp_json_encode( $attributes ),
					'tags_json'        => wp_json_encode( $tags ),
					'list_id'          => $listId,
					'provider_adapter' => $provider,
					'consent_hash'     => $consentHash,
					'consent_text'     => $consentText,
					'legal_basis'      => $legalBasis,
					'form_id'          => $formId,
					'confirmed_at'     => current_time( 'mysql' ),
					'unsubscribed_at'  => null,
				],
				[ 'id' => $existing->id ],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert(
				$this->subsTable,
				[
					'email'            => $email,
					'blinded_ip'       => $blindedIp,
					'status'           => 'subscribed',
					'attributes_json'  => wp_json_encode( $attributes ),
					'tags_json'        => wp_json_encode( $tags ),
					'list_id'          => $listId,
					'provider_adapter' => $provider,
					'consent_hash'     => $consentHash,
					'consent_text'     => $consentText,
					'legal_basis'      => $legalBasis,
					'form_id'          => $formId,
					'confirmed_at'     => current_time( 'mysql' ),
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}
	}

	public function unsubscribe( string $email ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );

		$res = $wpdb->update(
			$this->subsTable,
			[
				'status'          => 'unsubscribed',
				'unsubscribed_at' => $now,
			],
			[ 'email' => $email ],
			[ '%s', '%s' ],
			[ '%s' ]
		);

		return false !== $res;
	}

	public function mark_synced( int $stagingId, ?string $providerSubId = null ): void {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->update(
			$this->stagingTable,
			[
				'status'    => DoiState::SYNCED_TO_PROVIDER->value,
				'synced_at' => $now,
			],
			[ 'id' => $stagingId ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( $providerSubId ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT email FROM %i WHERE id = %d', $this->stagingTable, $stagingId ) );
			if ( $row ) {
				$wpdb->update(
					$this->subsTable,
					[ 'provider_id' => $providerSubId ],
					[ 'email' => $row->email ],
					[ '%s' ],
					[ '%s' ]
				);
			}
		}
	}

	public function mark_sync_failed( int $stagingId ): void {
		global $wpdb;
		$wpdb->update(
			$this->stagingTable,
			[ 'status' => DoiState::SYNC_FAILED->value ],
			[ 'id' => $stagingId ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Rows waiting for the provider, oldest first.
	 *
	 * Rows whose address is unsubscribed in the local table are left out. Until 2026-09-15 a person
	 * who confirmed and unsubscribed before the next sync run was still pushed to the provider as
	 * subscribed.
	 */
	public function get_pending_sync_queue( int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue read for the sync run.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.* FROM %i AS s LEFT JOIN %i AS u ON u.email = s.email
				 WHERE s.status IN (%s, %s) AND ( u.status IS NULL OR u.status = %s )
				 ORDER BY s.id ASC LIMIT %d',
				$this->stagingTable,
				$this->subsTable,
				DoiState::CONFIRMED->value,
				DoiState::SYNC_FAILED->value,
				'subscribed',
				$limit
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Deletes unconfirmed sign-ups (and rows marked expired) older than the confirmation window.
	 *
	 * The confirmation mail tells the person that an unconfirmed address is deleted after that window.
	 * Until 2026-09-15 the hourly run only set the status to EXPIRED and kept address, consent text and
	 * blinded IP indefinitely.
	 *
	 * @return int Deleted rows.
	 */
	public function purge_expired_pending( int $ttlHours = 48 ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scheduled deletion.
		$count = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status IN (%s, %s) AND created_at < %s',
				$this->stagingTable,
				DoiState::PENDING_CONFIRMATION->value,
				DoiState::EXPIRED->value,
				self::cutoff_for( $ttlHours )
			)
		);

		return (int) $count;
	}

	/** Removes one unconfirmed row, used when the confirmation mail could not be sent. */
	public function delete_pending( int $stagingId ): void {
		global $wpdb;
		$wpdb->delete(
			$this->stagingTable,
			[
				'id'     => $stagingId,
				'status' => DoiState::PENDING_CONFIRMATION->value,
			],
			[ '%d', '%s' ]
		);
	}

	/**
	 * Counts for the admin screen, WP-CLI and the hub adapter.
	 *
	 * - `synced_to_provider`: rows an external provider accepted; `kept_local`: rows the provider "local"
	 *   accepted, which never left the site.
	 * - `waiting_for_sync` and `sync_failed`: rows the next run will send, with the same subscriber
	 *   condition as get_pending_sync_queue().
	 * - `held_unsubscribed`: confirmed or failed rows whose address is unsubscribed locally; the queue
	 *   never sends them.
	 *
	 * Until 2026-09-15 `sync_failed` counted those held rows too, so a failed push of an address that was
	 * unsubscribed afterwards kept the hub on DEGRADED for good, and every push with the provider "local"
	 * was shown as "An den Anbieter übergeben".
	 */
	public function get_stats(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counts.
		$subs = fn( string $status ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $this->subsTable, $status ) );

		$pendingDoi = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $this->stagingTable, DoiState::PENDING_CONFIRMATION->value ) );
		$synced     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND provider_adapter <> %s', $this->stagingTable, DoiState::SYNCED_TO_PROVIDER->value, 'local' ) );
		$keptLocal  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND provider_adapter = %s', $this->stagingTable, DoiState::SYNCED_TO_PROVIDER->value, 'local' ) );
		$sendable   = fn( string $status ): int => (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS s LEFT JOIN %i AS u ON u.email = s.email WHERE s.status = %s AND ( u.status IS NULL OR u.status = %s )',
				$this->stagingTable,
				$this->subsTable,
				$status,
				'subscribed'
			)
		);
		$held       = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS s INNER JOIN %i AS u ON u.email = s.email WHERE s.status IN (%s, %s) AND u.status <> %s',
				$this->stagingTable,
				$this->subsTable,
				DoiState::CONFIRMED->value,
				DoiState::SYNC_FAILED->value,
				'subscribed'
			)
		);
		// phpcs:enable

		return [
			'active_subscribers' => $subs( 'subscribed' ),
			'unsubscribers'      => $subs( 'unsubscribed' ),
			'pending_doi'        => $pendingDoi,
			'synced_to_provider' => $synced,
			'kept_local'         => $keptLocal,
			'waiting_for_sync'   => $sendable( DoiState::CONFIRMED->value ),
			'sync_failed'        => $sendable( DoiState::SYNC_FAILED->value ),
			'held_unsubscribed'  => $held,
		];
	}

	public function get_staging_records( int $limit = 100, int $offset = 0, string $status = '' ): array {
		global $wpdb;
		$where = '';
		$args  = [];

		if ( ! empty( $status ) ) {
			$where  = 'WHERE status = %s';
			$args[] = $status;
		}

		$args[] = $limit;
		$args[] = $offset;

		// The table name goes through %i; $where is a literal fragment with its own placeholder.
		$sql = "SELECT * FROM %i {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		array_unshift( $args, $this->stagingTable );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- assembled from literals and placeholders above; all values pass through prepare().
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) ?: [];
	}
}
