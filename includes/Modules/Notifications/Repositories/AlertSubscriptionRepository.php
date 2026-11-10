<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications\Repositories;

use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who has asked to hear about what.
 *
 * TIER C — a plain final class with raw `$wpdb`, no audit columns and no soft delete — for the same
 * reason `ItemStockRepository` is: the table's `UNIQUE (user_id, alert_type)` is what makes the
 * upsert race-free, and a unique key cannot coexist with a soft delete. A trashed preference would
 * keep occupying the slot, so unsubscribing and resubscribing would fail against a row nobody can
 * see.
 *
 * A MISSING ROW IS NOT "NO", IT IS "NEVER ASKED". `AlertType::defaults()` answers for anybody with
 * no row, which is what lets the default be changed later without rewriting everyone's settings.
 */
final class AlertSubscriptionRepository {

	private function table(): string {
		return Db::table( 'alert_subscriptions' );
	}

	/**
	 * How many preferences anybody has actually saved.
	 *
	 * WHAT WOULD BE LOST, WHICH IS THE ONLY REASON THIS EXISTS. The App Manager card needs a figure
	 * that tells an administrator what switching Notifications off would throw away, and rows here
	 * are the only part of this module people have personally set — the notifications themselves
	 * are generated and would be regenerated.
	 *
	 * No soft-delete clause and no `query()` builder: this is a Tier C table, so a row is either
	 * there or it is not.
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table() )
		);
	}

	/**
	 * One user's stored preferences, keyed by alert type.
	 *
	 * @param int $user_id The user.
	 *
	 * @return array<string, array{email: bool, browser: bool}>
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT alert_type, email_enabled, browser_enabled FROM %i WHERE user_id = %d',
				$this->table(),
				$user_id
			),
			ARRAY_A
		);

		$found = array();

		foreach ( (array) $rows as $row ) {
			$found[ (string) $row['alert_type'] ] = array(
				'email'   => (bool) (int) $row['email_enabled'],
				'browser' => (bool) (int) $row['browser_enabled'],
			);
		}

		return $found;
	}

	/**
	 * Everybody who has an explicit preference about one alert type.
	 *
	 * ONLY THE EXPLICIT ONES. It cannot answer "who should be told", because most people have no row
	 * and are covered by the default — deciding that is `AlertSubscriptionService`'s job, and it
	 * needs the list of candidate users from WordPress rather than from here.
	 *
	 * @param string $alert_type The type.
	 *
	 * @return array<int, array{email: bool, browser: bool}> Keyed by user id.
	 */
	public function for_type( string $alert_type ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, email_enabled, browser_enabled FROM %i WHERE alert_type = %s',
				$this->table(),
				$alert_type
			),
			ARRAY_A
		);

		$found = array();

		foreach ( (array) $rows as $row ) {
			$found[ (int) $row['user_id'] ] = array(
				'email'   => (bool) (int) $row['email_enabled'],
				'browser' => (bool) (int) $row['browser_enabled'],
			);
		}

		return $found;
	}

	/**
	 * Record one person's choice about one type.
	 *
	 * `INSERT … ON DUPLICATE KEY UPDATE`, race-free BECAUSE of the unique key — the same idiom and
	 * the same argument as `ItemStockRepository::ensure_row()`. Two tabs saving preferences at once
	 * produce one row, last writer winning, rather than a duplicate-key error surfacing as "could
	 * not save" on a form that looks fine.
	 *
	 * @param int    $user_id    The user.
	 * @param string $alert_type The type.
	 * @param bool   $email      Send email.
	 * @param bool   $browser    Show in the bell.
	 */
	public function put( int $user_id, string $alert_type, bool $email, bool $browser ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$done = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (user_id, alert_type, email_enabled, browser_enabled, updated_at)
				 VALUES (%d, %s, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE
				   email_enabled = VALUES(email_enabled),
				   browser_enabled = VALUES(browser_enabled),
				   updated_at = VALUES(updated_at)',
				$this->table(),
				$user_id,
				$alert_type,
				$email ? 1 : 0,
				$browser ? 1 : 0,
				current_time( 'mysql', true )
			)
		);

		return false !== $done;
	}

	/**
	 * Forget one person's preferences entirely.
	 *
	 * The cascade when a WordPress user is deleted. Their notifications go too — see
	 * `Notifications\Module` — because a bell addressed to a user who no longer exists is a row
	 * nothing will ever read and nothing will ever remove.
	 *
	 * @param int $user_id The user.
	 */
	public function forget_user( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$done = $wpdb->delete( $this->table(), array( 'user_id' => $user_id ), array( '%d' ) );

		return false === $done ? 0 : (int) $done;
	}
}
