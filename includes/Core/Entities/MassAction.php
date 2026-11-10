<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Entities;

use PlugixaInventory\Core\Security\Capabilities;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Doing one thing to many records.
 *
 * WHY THIS EXISTS NOW. Every write route in the plugin was single-record, and the mass-action bar in
 * the list screens papered over it by looping `DELETE /contacts/{id}` from the browser — one HTTP
 * round trip per record, each with its own nonce check and its own transaction. That is tolerable for
 * the six rows somebody ticks by hand and indefensible for the four thousand a campaign selects, so
 * mass email could not be built on top of it. This is the layer the plan called the blocker.
 *
 * IT IS A LOOP, AND THAT IS DELIBERATE. A single `UPDATE … WHERE id IN (…)` would be one query
 * instead of N, and it would also skip every service: no derived columns, no lifecycle events, no
 * per-record permission check. A mass update that quietly bypassed the stream and the notification
 * bell would make those features lie about what happened to the data. So it goes through
 * {@see RecordWriter}, exactly as CSV import does, and buys its speed back by doing the loop on the
 * server in one request rather than across N.
 *
 * THE PER-RECORD CHECK IS THE POINT. `Capabilities::user_can( 'edit', $type, $record )` is asked for
 * every single record, because "may edit contacts" and "may edit THIS contact" are different
 * questions once Roles are in play. A record the user may not touch is SKIPPED and counted, never
 * refused as a whole — the same rule PDF printing follows, and for the same reason: a selection that
 * happens to include one of somebody else's records should still act on the rest.
 */
final class MassAction {

	/**
	 * The most records one request will act on.
	 *
	 * The client batches above this. A cap that is too high turns a mis-click into a request that
	 * cannot finish and cannot be interrupted; too low and a large selection becomes chatty again.
	 */
	public const BATCH = 200;

	/**
	 * What can be done to many records at once.
	 *
	 * No `restore`: every module would have to register a fourth callable on `RecordWriter` to support
	 * it, and the trash is worked one record at a time by design — you look at what you are bringing
	 * back. Nothing in this phase needs it.
	 */
	public const ACTIONS = array( 'update', 'delete' );

	public function __construct(
		private readonly RecordWriter $writer,
		private readonly RecordLoader $records
	) {}

	/**
	 * Apply one action to many records.
	 *
	 * @param string               $entity_type Entity slug.
	 * @param string               $action      One of self::ACTIONS.
	 * @param int[]                $ids         Record ids.
	 * @param array<string, mixed> $values      Fields to set, for `update`.
	 *
	 * @return array{done: int, skipped: int, failed: int}|WP_Error
	 */
	public function run( string $entity_type, string $action, array $ids, array $values = array() ): array|WP_Error {
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'plugixa_inventory_invalid_action', __( 'That is not a mass action.', 'plugixa-inventory' ), array( 'status' => 422 ) );
		}

		if ( ! $this->writer->knows( $entity_type ) ) {
			return new WP_Error(
				'plugixa_inventory_not_writable',
				__( 'That kind of record cannot be changed in bulk.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );

		if ( array() === $ids ) {
			return new WP_Error( 'plugixa_inventory_nothing_selected', __( 'Nothing was selected.', 'plugixa-inventory' ), array( 'status' => 400 ) );
		}

		if ( count( $ids ) > self::BATCH ) {
			return new WP_Error(
				'plugixa_inventory_too_many',
				sprintf(
					/* translators: %d: the maximum number of records per request. */
					__( 'At most %d records can be changed in one go.', 'plugixa-inventory' ),
					self::BATCH
				),
				array( 'status' => 422 )
			);
		}

		// The entity-level question, asked once before any work starts.
		$capability = 'delete' === $action ? 'delete' : 'edit';

		if ( ! Capabilities::user_can( $capability, $entity_type ) ) {
			return new WP_Error( 'plugixa_inventory_forbidden', __( 'You do not have permission to do that.', 'plugixa-inventory' ), array( 'status' => 403 ) );
		}

		if ( 'update' === $action && array() === $values ) {
			return new WP_Error( 'plugixa_inventory_nothing_to_change', __( 'There is nothing to change.', 'plugixa-inventory' ), array( 'status' => 422 ) );
		}

		$done    = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( $ids as $id ) {
			$record = $this->records->load( $entity_type, $id );

			/*
			 * THE PER-RECORD CHECK IS MADE HERE RATHER THAN LEFT TO THE WRITER, so that a refusal can be
			 * COUNTED as a skip. `RecordWriter::delete()` answers with a bare bool, which cannot tell
			 * "you may not touch this one" apart from "the delete failed" — and those are different
			 * facts needing different responses from whoever reads the result. The writer still makes
			 * its own check; this one exists to classify, not to replace it.
			 */
			if ( ! is_array( $record ) || ! Capabilities::user_can( $capability, $entity_type, $record ) ) {
				++$skipped;

				continue;
			}

			$result = 'update' === $action
				? $this->writer->update( $entity_type, $id, $values )
				: $this->writer->delete( $entity_type, $id );

			if ( $result instanceof WP_Error || false === $result ) {
				++$failed;

				continue;
			}

			++$done;
		}

		return array(
			'done'    => $done,
			'skipped' => $skipped,
			'failed'  => $failed,
		);
	}
}
