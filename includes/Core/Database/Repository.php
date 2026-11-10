<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Database;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base repository. Every inventory entity gets one.
 *
 * Beyond CRUD, this class is the single place that fires the entity-lifecycle hooks.
 * That centralisation is deliberate and load-bearing: because REST writes, CSV
 * imports, mass actions and premium code all go through a repository, a listener hooked
 * here sees *every* change to *every* entity. It is what lets the Notifications module react
 * to "an item fell below its reorder level" without being wired into Items, and the premium
 * Roles module scope a write it never knew was going to happen.
 *
 * Subclasses supply the table and the entity slug; the hooks come for free.
 *
 * CONTRACT: every table backing a Repository must have these columns, because this
 * class writes them unconditionally —
 *   deleted_at  datetime DEFAULT NULL   (soft delete; KEY it)
 *   created_at, modified_at  datetime
 *   created_by, modified_by  bigint(20) unsigned
 */
abstract class Repository {

	/**
	 * Bare table name, e.g. "items".
	 */
	abstract protected function table(): string;

	/**
	 * Entity slug used in hook payloads, e.g. "item".
	 */
	abstract protected function entity_type(): string;

	/**
	 * Columns that may be written. Anything else in the payload is dropped.
	 *
	 * @return string[]
	 */
	abstract protected function fillable(): array;

	/**
	 * Columns that may be sorted on. Guards against SQL injection via ?orderby=.
	 *
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'created_at', 'modified_at' );
	}

	/**
	 * WHAT A LIST MAY BE FILTERED BY. The twin of sortable(), and for the same reason.
	 *
	 * `prepare()` binds values, never columns, so the only safe way to let a request narrow a list
	 * is to have it send a KEY that is looked up here and never a column that is used. An unknown
	 * key is a 400, not a query. `Modules\Reports\Sources` says this at length and this is the same
	 * idea applied to record lists.
	 *
	 * Each entry is `key => array( label, sql, type, options?, entity? )`:
	 *   sql     a developer-written fragment, always `t.<column>`, never touched by the request
	 *   type    one of Core\Filters\Operators::TYPES — decides which operators are offered
	 *   options for `select`, a LIST OF VALUES — not a value => label map
	 *   labels  optional value => label map, ONLY for options the client cannot know (see below)
	 *   entity  for `entity`, the slug the id points at, so the client knows what to pick from
	 *
	 * OPTIONS ARE VALUES AND THE CLIENT LABELS THEM. `LEAD_STATUS_LABELS` and its siblings already
	 * exist in the React app, already translated, and are already what every other screen renders a
	 * status with. Sending labels from here would mean a second set to keep in step, and the first
	 * time they disagreed the filter would offer a choice worded unlike the column it filters.
	 *
	 * `labels` is the exception, for options that are ROWS rather than constants — a warehouse
	 * or a unit the user created. The client cannot have a map for those, so the server sends one. Anything a
	 * constant can express does not use it.
	 *
	 * DEFAULT IS EMPTY, AND DELIBERATELY SO. Returning the common timestamps here would put a
	 * Filters button on all twenty lists at once — including email templates and portal users,
	 * where nobody asked for one. A repository opts in by overriding, and gets `common_filters()`
	 * to avoid retyping the three every entity shares.
	 *
	 * This is NOT where custom fields come from. They are derived from the definitions table by
	 * `Core\Filters\FilterCatalogue`, so that defining a field is the only step needed to filter by
	 * it. See the note there.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array();
	}

	/**
	 * The three filters every assignable entity shares.
	 *
	 * `assigned_user_id` is in here rather than in `filterable()` above because the class contract
	 * does not promise the column — it promises `deleted_at`, the timestamps and the by-columns.
	 * Categories, units and the stock ledger have no owner, and a filter on a column that is not
	 * there is a SQL error rather than an empty result. So this is opt-in: a repository that calls it is
	 * asserting it has the column.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * `'stored' => 'utc'` IS NOT DECORATION. These two columns are written by `insert()` and
	 * `update()` below with `current_time( 'mysql', true )` — the UTC form — while a date picker
	 * sends the day the user is living in. Without the flag, "created on the 4th" on a UTC+2 site
	 * compiles to `t.created_at BETWEEN '2026-09-04 00:00:00' AND '2026-09-04 23:59:59'` and so
	 * silently means 02:00 on the 4th to 01:59 on the 5th: the first two hours of the user's day
	 * are missing from their own list, and two hours of the next day are in it.
	 *
	 * A DOCUMENT'S OWN `date` COLUMN MUST NOT CARRY THIS FLAG. Those are naive wall-clock — see
	 * `AdjustmentService::stamp()` — because a stock movement dated the 31st happened on the 31st
	 * wherever you read it from. The flag says how the column is STORED, and the two kinds of
	 * datetime in this schema are stored differently on purpose.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @return array<string, array<string, mixed>>
	 */
	final protected function common_filters(): array {
		return array(
			/*
			 * NO `assigned_user_id` FILTER HERE, AND ITS ABSENCE IS THE FIX RATHER THAN AN OVERSIGHT.
			 *
			 * An "Owner" filter was offered on five entities and NOTHING in this plugin can set an
			 * owner: `assigned_user_id` is on the schema, `AssignedUserSelect.tsx` has zero
			 * importers, no form writes the column and no payload carries it. So the control opened,
			 * listed every WordPress user, and matched nothing whichever one you picked -- a filter
			 * whose only possible answer is "no records", which reads as an empty inventory rather
			 * than as a feature that was never finished.
			 *
			 * The COLUMN stays. `Roles`' `own` access level narrows on it (`QueryBuilder::
			 * apply_acl_clauses()`), so it is load-bearing for the premium permission model even
			 * though nothing assigns it yet. When something does -- a form field, an import, a bulk
			 * assign -- the filter belongs back here, and this comment is the note that it was
			 * removed deliberately rather than never written.
			 */
			'created_at'       => array(
				'label'  => __( 'Created', 'plugixa-inventory' ),
				'sql'    => 't.created_at',
				'type'   => 'date',
				'stored' => 'utc',
			),
			'modified_at'      => array(
				'label'  => __( 'Modified', 'plugixa-inventory' ),
				'sql'    => 't.modified_at',
				'type'   => 'date',
				'stored' => 'utc',
			),
		);
	}

	final protected function table_name(): string {
		return Schema::table( $this->table() );
	}

	/**
	 * The column holding a record's display name. Override where it is not `name`.
	 *
	 * Units are the exception. Their `code` is what appears on every movement line and every report
	 * column — the reader wants "kg", not "Kilogram" — so UnitRepository overrides this even
	 * though the table has a perfectly good `name`.
	 */
	protected function name_column(): string {
		return 'name';
	}

	/**
	 * The display names of some records, keyed by id, in one query.
	 *
	 * Exists so that a module can answer `plugixa_inventory_record_names` for its own entities without
	 * every module writing the same lookup. That filter is how the transactions ledger renders
	 * "Checked out to Ada Lovelace" without the ledger knowing what a recipient is, or which of its
	 * columns holds its name.
	 *
	 * Trashed rows are resolved too, deliberately: a row pointing at a trashed parent should render the
	 * parent's name and let the reader see what happened, not silently show "—" as though the link had
	 * never existed.
	 *
	 * @param int[] $ids Records to resolve.
	 *
	 * @return array<int, string> Keyed by record id.
	 */
	public function names( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		global $wpdb;

		$table  = $this->table_name();
		$column = $this->name_column();

		$names = array();

		// The query text never varies: exactly IdList::CHUNK placeholders, always bound, however
		// many ids were asked for. {@see IdList} for why the list is batched and padded rather than
		// interpolated, and why it is not FIND_IN_SET. Identifiers bind in the order they appear:
		// the column, then the table, then the twenty ids.
		foreach ( IdList::chunks( $ids ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, so there is no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, %i AS display_name FROM %i
					  WHERE id IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)',
					$column,
					$table,
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$names[ (int) $row['id'] ] = (string) $row['display_name'];
			}
		}

		return $names;
	}

	/**
	 * Find one live row by primary key.
	 *
	 * Soft-deleted rows are invisible here by default, so no caller can leak a trashed
	 * record by forgetting the trash exists. Pass $with_trashed to reach one — restore()
	 * and purge() do.
	 *
	 * @param int  $id           Row id.
	 * @param bool $with_trashed Include a soft-deleted row.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id, bool $with_trashed = false ): ?array {
		global $wpdb;

		$table = $this->table_name();

		// Two shapes, two literal statements. Concatenating the trash clause onto a
		// variable would read more cheaply and defeat the point: prepare() can only be
		// audited when the statement it receives is visible at the call site.
		if ( $with_trashed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL', $table, $id ), ARRAY_A );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A query builder scoped to this entity, with the ACL seam already wired.
	 *
	 * @param string $context Query context.
	 */
	public function query( string $context = 'list' ): QueryBuilder {
		return new QueryBuilder( $this->table_name(), $this->entity_type(), $context );
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string, mixed> $data Column => value.
	 *
	 * @return int The new row id, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$entity = $this->entity_type();

		/**
		 * Filter an entity's data immediately before insert.
		 *
		 * @param array<string, mixed> $data   Sanitised, fillable-filtered payload.
		 * @param string               $entity Entity slug.
		 */
		$data = (array) apply_filters( 'plugixa_inventory_before_insert', $this->only_fillable( $data ), $entity );

		$data['created_at']  = $data['created_at'] ?? current_time( 'mysql', true );
		$data['modified_at'] = $data['created_at'];
		$data['created_by']  = $data['created_by'] ?? get_current_user_id();
		$data['modified_by'] = $data['created_by'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ok = $wpdb->insert( $this->table_name(), $data );
		if ( ! $ok ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after any inventory entity is created.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     New row id.
		 * @param array<string, mixed> $data   The inserted data.
		 */
		do_action( 'plugixa_inventory_entity_created', $entity, $id, $data );

		return $id;
	}

	/**
	 * Update a row.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Column => value.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$old = $this->find( $id );
		if ( null === $old ) {
			return false;
		}

		$entity = $this->entity_type();

		/**
		 * Filter an entity's data immediately before update.
		 *
		 * @param array<string, mixed> $data   Sanitised, fillable-filtered payload.
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $old    The row as it currently stands.
		 */
		$data = (array) apply_filters( 'plugixa_inventory_before_update', $this->only_fillable( $data ), $entity, $id, $old );

		$data['modified_at'] = current_time( 'mysql', true );
		$data['modified_by'] = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$result = $wpdb->update( $this->table_name(), $data, array( 'id' => $id ) );
		if ( false === $result ) {
			return false;
		}

		// One event per changed field. The premium Workflow module's "when Status
		// becomes Won" trigger listens here; reconstructing it from a whole-row diff
		// downstream would be lossy and every consumer would reimplement it.
		foreach ( $data as $field => $new_value ) {
			if ( array_key_exists( $field, $old ) && (string) $old[ $field ] !== (string) $new_value ) {
				/**
				 * Fires for each individual field that changed on an inventory entity.
				 *
				 * @param string $entity    Entity slug.
				 * @param int    $id        Row id.
				 * @param string $field     Column name.
				 * @param mixed  $new_value New value.
				 * @param mixed  $old_value Previous value.
				 */
				do_action( 'plugixa_inventory_entity_field_changed', $entity, $id, $field, $new_value, $old[ $field ] );
			}
		}

		if ( isset( $data['assigned_user_id'] ) && (int) $old['assigned_user_id'] !== (int) $data['assigned_user_id'] ) {
			/**
			 * Fires when an inventory entity is reassigned to a different user.
			 *
			 * @param string $entity  Entity slug.
			 * @param int    $id      Row id.
			 * @param int    $new_user New assignee.
			 * @param int    $old_user Previous assignee.
			 */
			do_action( 'plugixa_inventory_entity_assigned', $entity, $id, (int) $data['assigned_user_id'], (int) $old['assigned_user_id'] );
		}

		/**
		 * Fires after any inventory entity is updated.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $data   The applied changes.
		 * @param array<string, mixed> $old    The row before the update.
		 */
		do_action( 'plugixa_inventory_entity_updated', $entity, $id, $data, $old );

		return true;
	}

	/**
	 * Move a row to the trash.
	 *
	 * Inventory records are soft-deleted. A hard DELETE on a warehouse would take its stock rows,
	 * every ledger entry pointing at it and every document that ever moved through it, with no way
	 * back — and an accidental click is not worth that. purge() is the deliberate, irreversible one.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$old = $this->find( $id );
		if ( null === $old ) {
			return false;
		}

		$entity = $this->entity_type();

		/**
		 * Veto a trash. Return anything but `true` to refuse.
		 *
		 * THE ONE PLACE "THIS CANNOT BE DELETED" IS ENFORCED, rather than in each service that
		 * happens to remember. Two rules currently answer it: the stock ledger is append-only, and
		 * a finalized document is permanent. Both are the Stock module's, and neither the ledger's
		 * own repository nor three document services can be relied on to be the only route in — a
		 * mass action, an importer, or a module written next year all reach this method.
		 *
		 * A fourth document type inherits the rule by existing, which is the whole point. This
		 * mirrors the argument `RestController::ok()` makes for being the one place field-level
		 * access control can live: a rule one caller forgets is a rule that is not enforced.
		 *
		 * A FILTER CANNOT CARRY A MESSAGE, so this is the backstop and not the explanation. The
		 * service that knows why produces the wording and the HTTP status; this makes sure that
		 * even a caller that skipped the service cannot get past it.
		 *
		 * @param bool                 $allowed Whether the trash may proceed.
		 * @param string               $entity  Entity slug.
		 * @param int                  $id      Row id.
		 * @param array<string, mixed> $old     The row about to be trashed.
		 */
		$allowed = apply_filters( 'plugixa_inventory_can_delete', true, $entity, $id, $old );

		if ( true !== $allowed ) {
			return false;
		}

		/**
		 * Fires before an inventory entity is trashed.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $old    The row about to be trashed.
		 */
		do_action( 'plugixa_inventory_before_delete', $entity, $id, $old );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$result = $wpdb->update(
			$this->table_name(),
			array( 'deleted_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id )
		);

		if ( false === $result ) {
			return false;
		}

		/**
		 * Fires after an inventory entity is trashed. The row still exists and can be restored.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $old    The trashed row.
		 */
		do_action( 'plugixa_inventory_entity_deleted', $entity, $id, $old );

		return true;
	}

	/**
	 * Bring a row back out of the trash.
	 *
	 * @param int $id Row id.
	 */
	public function restore( int $id ): bool {
		global $wpdb;

		$row = $this->find( $id, true );
		if ( null === $row || null === $row['deleted_at'] ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$result = $wpdb->update(
			$this->table_name(),
			array( 'deleted_at' => null ),
			array( 'id' => $id )
		);

		if ( false === $result ) {
			return false;
		}

		/**
		 * Fires after a trashed inventory entity is restored.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $row    The restored row.
		 */
		do_action( 'plugixa_inventory_entity_restored', $this->entity_type(), $id, $row );

		return true;
	}

	/**
	 * Delete a row permanently. There is no way back from here.
	 *
	 * @param int $id Row id.
	 */
	public function purge( int $id ): bool {
		global $wpdb;

		$row = $this->find( $id, true );
		if ( null === $row ) {
			return false;
		}

		$entity = $this->entity_type();

		/**
		 * Veto a permanent delete. Return anything but `true` to refuse.
		 *
		 * SEPARATE FROM `plugixa_inventory_can_delete`, because the two questions genuinely differ.
		 * A draft document may be trashed and may also be destroyed; a ledger row may be neither;
		 * and a link row is purged rather than trashed as a matter of course. A single filter would
		 * force every listener to work out which of the two it was being asked about.
		 *
		 * @param bool                 $allowed Whether the purge may proceed.
		 * @param string               $entity  Entity slug.
		 * @param int                  $id      Row id.
		 * @param array<string, mixed> $row     The row about to be destroyed.
		 */
		$allowed = apply_filters( 'plugixa_inventory_can_purge', true, $entity, $id, $row );

		if ( true !== $allowed ) {
			return false;
		}

		/**
		 * Fires before an inventory entity is permanently deleted. Listeners should clean up
		 * anything that references it — this is the last moment the row exists.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $row    The row about to be destroyed.
		 */
		do_action( 'plugixa_inventory_before_purge', $entity, $id, $row );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ok = (bool) $wpdb->delete( $this->table_name(), array( 'id' => $id ) );
		if ( ! $ok ) {
			return false;
		}

		/**
		 * Fires after an inventory entity is permanently deleted.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $row    The destroyed row.
		 */
		do_action( 'plugixa_inventory_entity_purged', $entity, $id, $row );

		return true;
	}

	/**
	 * Refuse text its column cannot hold, naming the field — before `$wpdb` refuses it without a word.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * WHY A 300-CHARACTER NAME WAS A 500. `wpdb::insert()` checks every value against its column and,
	 * when one is too long, returns false with nothing but a line in the database error log. `insert()`
	 * above turns false into 0, and every service turns 0 into "could not be saved" with status 500 —
	 * so a typo-level mistake by a client read as a server fault, and the form had no field to point
	 * at. A reference is only 50 characters; a unit code, 20.
	 *
	 * THE LIMIT IS THE DATABASE'S OWN, read through `wpdb::get_col_length()` — the same metadata
	 * `wpdb` itself uses to refuse the value, cached per table for the request — so it cannot drift
	 * from the schema the way a limit copied into PHP would. Text columns are counted in CHARACTERS,
	 * which is what VARCHAR(255) holds under utf8mb4: a byte count would refuse a perfectly valid name
	 * the moment it contained an accent.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param array<string, mixed> $data Column => value, sanitized.
	 */
	final public function too_long( array $data ): ?WP_Error {
		global $wpdb;

		foreach ( $this->only_fillable( $data ) as $column => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			$limit = $wpdb->get_col_length( $this->table_name(), $column );

			if ( ! is_array( $limit ) || empty( $limit['length'] ) ) {
				continue;
			}

			$length = 'byte' === ( $limit['type'] ?? '' ) ? strlen( $value ) : mb_strlen( $value, 'UTF-8' );

			if ( $length > (int) $limit['length'] ) {
				return new WP_Error(
					'plugixa_inventory_too_long',
					sprintf(
						/* translators: %d: the maximum number of characters the field holds. */
						__( 'This is too long: at most %d characters.', 'plugixa-inventory' ),
						(int) $limit['length']
					),
					array( 'status' => 422, 'field' => $column, 'max' => (int) $limit['length'] )
				);
			}
		}

		return null;
	}

	/**
	 * How many characters a text column holds, read from the database, or null when it has no such limit.
	 *
	 * For a value the plugin DERIVES — a key made from a label — which should be cut to fit rather than
	 * refused: the user never typed it, so there is no field of theirs to point at.
	 *
	 * @param string $column The column.
	 */
	final public function max_length( string $column ): ?int {
		global $wpdb;

		$limit = $wpdb->get_col_length( $this->table_name(), $column );

		return is_array( $limit ) && 'char' === ( $limit['type'] ?? '' ) && ! empty( $limit['length'] ) ? (int) $limit['length'] : null;
	}

	/**
	 * Drop any key that is not a writable column.
	 *
	 * @param array<string, mixed> $data Raw payload.
	 *
	 * @return array<string, mixed>
	 */
	final protected function only_fillable( array $data ): array {
		return array_intersect_key( $data, array_flip( $this->fillable() ) );
	}

	/**
	 * Resolve a requested sort column against the allow-list.
	 *
	 * @param string $requested Column asked for by the client.
	 */
	final public function safe_sort_column( string $requested ): string {
		return in_array( $requested, $this->sortable(), true ) ? $requested : 'id';
	}
}
