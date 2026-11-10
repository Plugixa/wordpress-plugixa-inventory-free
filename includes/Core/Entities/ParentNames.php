<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Entities;

use PlugixaInventory\Core\Database\IdList;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attach `parent_name` and `assigned_user_name` to a page of rows that hang off another record.
 *
 * The lists show "Site visit — at Northwind Traders, assigned to Adam", and neither name lives on the row
 * being listed. Resolved in BULK — one query per parent TYPE present on the page, and one for the users —
 * never one per row: a page is at most 200 rows but at most five lookups.
 *
 * WHY IT IS HERE. This was a private method on ActivityRepository, where it worked out a contact's name by
 * reaching into the contacts table itself, from a hardcoded map of four parent types to four
 * (table, column) pairs. That was one module holding inventory knowledge it did not own — survivable while there
 * was one activity module, and about to be copied into three.
 *
 * Core does NOT inherit that knowledge. It groups the rows and asks; the module that owns an entity
 * answers for it, the same way `plugixa_inventory_roll_up_account` and `plugixa_inventory_reminder_targets` work. So
 * Core still does not know that a contact's display name is `full_name` and an account's is `name` — and a
 * premium module that invents a new parent type gets named on every timeline by answering one filter.
 *
 * An unanswered type simply has no name, which renders as "—" rather than an error: a parent whose module
 * has been deactivated is a record with a dangling reference, not a crash.
 */
final class ParentNames {

	/**
	 * @param array<int, array<string, mixed>> $rows A page of rows carrying parent_type/parent_id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function attach( array $rows ): array {
		$names      = self::parent_names( $rows );
		$user_names = self::user_names( $rows );

		foreach ( $rows as &$row ) {
			$key                       = ( $row['parent_type'] ?? '' ) . ':' . (int) ( $row['parent_id'] ?? 0 );
			$row['parent_name']        = $names[ $key ] ?? null;
			$row['assigned_user_name'] = $user_names[ (int) ( $row['assigned_user_id'] ?? 0 ) ] ?? null;
		}

		return $rows;
	}

	/**
	 * The display names of some records of ONE type, whoever owns them.
	 *
	 * The seam itself, exposed because rows are not the only thing that needs it: a meeting's attendee list
	 * carries (participant_type, participant_id) pairs and wants exactly this. That list used to be built by
	 * LEFT JOINing contacts, leads and wp_users in one query — a module reading three tables it does not
	 * own, and about to be forked into Meetings and Calls alike.
	 *
	 * `user` is answered here rather than by a module, because a WordPress user belongs to WordPress. No
	 * module owns them, so no module can be asked.
	 *
	 * @param string $entity_type Registry slug, e.g. "contact", or "user".
	 * @param int[]  $ids         Records to resolve.
	 *
	 * @return array<int, string> Keyed by record id.
	 */
	public static function resolve( string $entity_type, array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		if ( 'user' === $entity_type ) {
			return self::users( $ids );
		}

		/**
		 * The display names of some records, answered by the module that owns them.
		 *
		 * One call per type, never one per row.
		 *
		 * @param array<int, string> $names       Keyed by record id.
		 * @param string             $entity_type Registry slug, e.g. "contact".
		 * @param int[]              $ids         Records asked about.
		 */
		$names = (array) apply_filters( 'plugixa_inventory_record_names', array(), $entity_type, $ids );

		return array_map( 'strval', $names );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows A page of rows.
	 *
	 * @return array<string, string> Keyed "type:id".
	 */
	private static function parent_names( array $rows ): array {
		$by_type = array();

		foreach ( $rows as $row ) {
			$type = (string) ( $row['parent_type'] ?? '' );

			if ( '' !== $type && ! empty( $row['parent_id'] ) ) {
				$by_type[ $type ][] = (int) $row['parent_id'];
			}
		}

		$names = array();

		foreach ( $by_type as $type => $ids ) {
			foreach ( self::resolve( $type, $ids ) as $id => $name ) {
				$names[ $type . ':' . (int) $id ] = $name;
			}
		}

		return $names;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows A page of rows.
	 *
	 * @return array<int, string> Keyed by user id.
	 */
	private static function user_names( array $rows ): array {
		$user_ids = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['assigned_user_id'] ) ) {
				$user_ids[] = (int) $row['assigned_user_id'];
			}
		}

		return self::users( $user_ids );
	}

	/**
	 * @param int[] $user_ids WordPress users.
	 *
	 * @return array<int, string> Keyed by user id.
	 */
	private static function users( array $user_ids ): array {
		global $wpdb;

		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );

		if ( ! $user_ids ) {
			return array();
		}

		$names = array();

		// Fixed-width batches so the query text is a literal. {@see IdList} — and note that this is
		// the call site that made FIND_IN_SET the wrong answer for this plugin: `wp_users` is the
		// one table here a site can grow to six figures without ever touching the inventory, and a
		// function on the column would turn every list screen that shows "checked out by" into a
		// full scan of it.
		foreach ( IdList::chunks( $user_ids ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- display_name is not in any core cache keyed the way this reads it, and one batched query beats N get_userdata() calls.
			$found = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ID, display_name FROM %i
					  WHERE ID IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)',
					$wpdb->users,
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
				),
				ARRAY_A
			);

			foreach ( (array) $found as $record ) {
				$names[ (int) $record['ID'] ] = (string) $record['display_name'];
			}
		}

		return $names;
	}
}
