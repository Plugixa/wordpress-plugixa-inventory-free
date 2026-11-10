<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications\Repositories;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Modules\Notifications\AlertType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bell's rows.
 *
 * EVERY READ HERE IS SCOPED TO ONE USER, and that is the access control. A notification is
 * addressed to a person; there is no record-level rule to write because the row's own `user_id` IS
 * the rule, and it is applied in SQL rather than after the fact. The service never takes a user id
 * from a request.
 */
final class NotificationRepository extends Repository {

	protected function table(): string {
		return 'notifications';
	}

	protected function entity_type(): string {
		return 'notification';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'user_id',
			'alert_type',
			'title',
			'message',
			'priority',
			'action_url',
			'action_label',
			'metadata',
			'group_key',
			'item_id',
			'read_at',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'created_at', 'read_at', 'priority' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array_merge(
			$this->common_filters(),
			array(
				'alert_type' => array(
					'label'   => __( 'Kind', 'plugixa-inventory' ),
					'type'    => 'select',
					'options' => AlertType::keys(),
				),
				'priority'   => array(
					'label'   => __( 'Priority', 'plugixa-inventory' ),
					'type'    => 'select',
					'options' => array( 'low', 'normal', 'high', 'urgent' ),
				),
			)
		);
	}

	/**
	 * How many a user has not read.
	 *
	 * THE HOTTEST QUERY IN THE PLUGIN once the bell exists: it runs on a timer, on every admin page,
	 * for every logged-in user. `KEY unread (user_id, deleted_at, read_at)` is built for exactly
	 * this shape, and it is a covering index for it — MySQL answers without touching the rows.
	 *
	 * @param int $user_id The user.
	 */
	public function unread_count( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND deleted_at IS NULL AND read_at IS NULL',
				$this->table_name(),
				$user_id
			)
		);
	}

	/**
	 * An existing notification for this user in this group, if there is one.
	 *
	 * WHAT STOPS THE BELL BECOMING NOISE. Forty items falling below their reorder level on one day
	 * is ONE thing that happened; the low-stock run passes a group key of the day, finds this row,
	 * and updates it instead of writing a forty-first.
	 *
	 * DISMISSED ROWS ARE INCLUDED IN THE SEARCH — `find()` with trashed — and that is deliberate:
	 * somebody who dismissed today's low-stock notice has SEEN it, and re-raising the same group an
	 * hour later would be the plugin arguing with them.
	 *
	 * @param int    $user_id   The user.
	 * @param string $group_key The group.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_in_group( int $user_id, string $group_key ): ?array {
		global $wpdb;

		if ( '' === $group_key ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND group_key = %s ORDER BY id DESC LIMIT 1',
				$this->table_name(),
				$user_id,
				$group_key
			),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Mark every one of a user's unread notifications as read.
	 *
	 * @param int $user_id The user.
	 */
	public function mark_all_read( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$done = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET read_at = %s WHERE user_id = %d AND deleted_at IS NULL AND read_at IS NULL',
				$this->table_name(),
				current_time( 'mysql', true ),
				$user_id
			)
		);

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Delete notifications older than a cutoff, for good.
	 *
	 * THE BELL IS NOT AN ARCHIVE. Every low-stock run, every finalized transfer and every adjustment
	 * writes rows, so this table grows with activity and nothing else ever shrinks it. A read
	 * notification from eight months ago has no reader.
	 *
	 * @param int $days How long to keep them.
	 */
	public function purge_older_than( int $days ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$done = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table_name(), $cutoff )
		);

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Give the columns their real types, and decode the metadata.
	 *
	 * @param array<string, mixed> $row One row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		foreach ( array( 'id', 'user_id', 'created_by', 'modified_by' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		$row['item_id'] = isset( $row['item_id'] ) && null !== $row['item_id'] ? (int) $row['item_id'] : null;

		// `read` is what the client asks about; `read_at` is what support asks about. Both are sent
		// so neither has to derive the other.
		$row['read'] = ! empty( $row['read_at'] );

		$decoded           = json_decode( (string) ( $row['metadata'] ?? '' ), true );
		$row['metadata']   = is_array( $decoded ) ? $decoded : array();

		return $row;
	}
}
