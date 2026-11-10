<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mutual exclusion for the stock engine, using a MySQL named lock.
 *
 * WHY NOT `START TRANSACTION` + `SELECT ... FOR UPDATE`, which is the obvious answer.
 *
 * Three reasons, in order of weight.
 *
 * 1. A PLUGIN DOES NOT OWN THE CONNECTION. MySQL has no nested transactions, so an explicit COMMIT
 *    here would also commit whatever transaction the surrounding code had open -- another plugin's,
 *    WP-CLI's, a migration's -- and silently make their half-finished work permanent. This is a
 *    house rule inherited from the CRM this architecture came from, where it is written up on
 *    `LeadConverter` and where `grep -rn "START TRANSACTION"` returns nothing at all.
 *
 * 2. `FOR UPDATE` OUTSIDE A TRANSACTION IS A NO-OP THAT LOOKS LIKE A LOCK. With autocommit on, the
 *    row lock is taken and released within the statement. So the two are a package deal and the
 *    package is unavailable -- you cannot take half of it and get half the safety.
 *
 * 3. Even granted the connection, `FOR UPDATE` over N rows across concurrent documents is a
 *    textbook deadlock generator unless every writer sorts its lock set identically. That is one
 *    more invariant nobody will remember to check.
 *
 * WHY A NAMED LOCK IS THE RIGHT PRIMITIVE HERE, rather than merely the available one:
 *
 *   - It is CONNECTION-scoped, not transaction-scoped, so it composes with whatever transaction the
 *     caller has open. That is precisely the property reason 1 demands, and no other MySQL
 *     primitive has it.
 *   - ONE COARSE LOCK CANNOT DEADLOCK. There is no acquisition order to get wrong. It also sidesteps
 *     the pre-5.7 "one named lock per connection" limit entirely.
 *   - The lock is released automatically when the connection drops, so a PHP fatal mid-batch never
 *     wedges it for the next request.
 *
 * AND WHY THE CONTENTION COST IS ACCEPTABLE. Finalizing a document is human-initiated, seconds
 * apart, and the critical section is a handful of indexed statements per line. If it ever does
 * contend, `GET_LOCK` returning 0 produces a truthful 503 -- and a truthful 503 beats overselling.
 *
 * This is a coarse lock ON PURPOSE rather than one per item. A per-item lock set would be finer and
 * would reintroduce exactly the acquisition-order problem that reason 3 is about.
 */
final class StockLock {

	/**
	 * How long to wait for the lock before giving up, in seconds.
	 *
	 * Long enough to ride out a concurrent finalize, short enough that a wedged request answers
	 * rather than holding a PHP worker until the web server times it out.
	 */
	public const TIMEOUT = 5;

	private bool $held = false;

	/**
	 * The lock's name.
	 *
	 * PREFIXED WITH THE TABLE PREFIX, so two WordPress installs sharing one MySQL server do not
	 * serialise against each other -- which is the normal shape of shared hosting, and would
	 * otherwise make one site's stock operations wait on a different site's.
	 *
	 * MySQL caps a lock name at 64 characters and silently truncates beyond it; the longest this
	 * can be is a long table prefix plus 24 characters, which leaves ample room.
	 */
	private function key(): string {
		global $wpdb;

		return $wpdb->prefix . 'plugixainventory_stock';
	}

	/**
	 * Take the lock, or report that somebody else has it.
	 *
	 * @param int $timeout Seconds to wait.
	 */
	public function acquire( int $timeout = self::TIMEOUT ): bool {
		global $wpdb;

		if ( $this->held ) {
			/*
			 * ALREADY HELD BY THIS INSTANCE. GET_LOCK is re-entrant on the same connection and
			 * would return 1 again -- but it also needs a matching RELEASE_LOCK per acquire, and a
			 * caller that acquired twice and released once would leave the lock held for the rest
			 * of the request. Answering true without re-taking it keeps acquire/release balanced.
			 */
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock is not a value to cache; caching it would be the bug.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->key(), $timeout ) );

		/*
		 * GET_LOCK RETURNS THREE THINGS, NOT TWO: 1 for acquired, 0 for timed out, and NULL for an
		 * error -- which on some configurations includes the lock name being too long. Only '1' is
		 * success, so the comparison is against the string rather than a loose truthiness check
		 * that would read NULL as failure and 0 as failure but tell them apart nowhere.
		 */
		$this->held = '1' === (string) $got;

		return $this->held;
	}

	/**
	 * Give it back. Safe to call when the lock is not held.
	 */
	public function release(): void {
		if ( ! $this->held ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see acquire().
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->key() ) );

		$this->held = false;
	}

	/** Whether this instance currently holds the lock. */
	public function held(): bool {
		return $this->held;
	}
}
