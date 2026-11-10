<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Services;

use PlugixaInventory\Core\Entities\RecordWriter;
use PlugixaInventory\Modules\Import\Repositories\ImportRepository;
use PlugixaInventory\Modules\Import\Repositories\ImportRowRepository;
use PlugixaInventory\Modules\Import\Storage;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Running a CSV import, one bounded batch at a time.
 *
 * IT RUNS IN BATCHES BECAUSE ONE REQUEST IS NOT ENOUGH. A realistic item export is tens of thousands
 * of rows, and every one of them is a service call that validates, writes, and fires the lifecycle
 * events the rest of the plugin listens to. No shared host will do that inside `max_execution_time`.
 * So the client asks for a bounded batch, gets progress back, and asks again — which also gives the
 * user a real progress bar instead of a spinner that either finishes or does not.
 *
 * EVERY ROW IS RECORDED, INCLUDING THE ONES THAT FAILED. "Something went wrong with your import" is
 * useless against a four-thousand-line spreadsheet; "line 2,847: no category called 'Power tolls'"
 * is something a person can fix. Failures do not stop the run for the same reason — one bad row in a
 * file of four thousand should cost you that row, not the afternoon.
 *
 * WRITES GO THROUGH {@see RecordWriter}, NEVER THROUGH A REPOSITORY. A row inserted directly would
 * be missing derived columns, the ACL scoping and every lifecycle event, and would look identical in
 * a list until somebody noticed. That seam's own docblock calls itself "the seam CSV import needs";
 * this is the consumer it was waiting for.
 *
 * NOTHING HERE TOUCHES STOCK. A quantity cell is parked on the row log and becomes a real finalized
 * document later — see {@see OpeningStock}, and note that it happens only for rows that CREATED
 * something, which is the guard that stops a re-run doubling an inventory.
 */
final class Importer {

	/** Espo's three, and they mean exactly what they say. */
	public const MODES = array( 'create', 'create_update', 'update' );

	/**
	 * Rows per batch.
	 *
	 * Small enough that the slowest plausible entity — an item, whose service runs several queries
	 * per write — still finishes inside a default `max_execution_time`, with room for the request
	 * itself and for a stock document at the end of it.
	 */
	private const BATCH = 100;

	public function __construct(
		private readonly ImportRepository $imports,
		private readonly ImportRowRepository $rows,
		private readonly RecordWriter $writer,
		private readonly CsvReader $reader,
		private readonly ImportTargets $targets,
		private readonly CellCoercer $coercer,
		private readonly LookupCache $lookups,
		private readonly NaturalKey $keys,
		private readonly OpeningStock $stock
	) {}

	/**
	 * Process the next batch.
	 *
	 * @param int $import_id The run.
	 *
	 * @return array<string, mixed>|WP_Error Progress, or why it cannot continue.
	 */
	public function run( int $import_id ): array|WP_Error {
		$import = $this->imports->find( $import_id );

		if ( null === $import ) {
			return $this->not_found();
		}

		if ( null !== $import['reverted_at'] ) {
			return new WP_Error(
				'plugixa_inventory_import_reverted',
				__( 'That import has been reverted.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$path = Storage::path( $this->imports->disk_name( $import_id ) );

		if ( null === $path ) {
			return new WP_Error(
				'plugixa_inventory_file_missing',
				__( 'The uploaded file is no longer available, so this import cannot continue.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$entity_type = (string) $import['entity_type'];

		// A module can be switched off between two batches of the same run. Better to stop with a
		// sentence than to spend the next forty requests failing every row.
		if ( ! $this->writer->knows( $entity_type ) ) {
			return new WP_Error(
				'plugixa_inventory_not_writable',
				__( 'That kind of record can no longer be imported.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$target = $this->targets->for_entity( $entity_type );

		if ( null === $target ) {
			return new WP_Error(
				'plugixa_inventory_not_writable',
				__( 'That kind of record can no longer be imported.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$mapping = $this->mapping( $import );
		$options = $this->options( $import );
		$offset  = (int) $import['offset_rows'];

		$batch = $this->reader->rows(
			$path,
			$offset,
			self::BATCH,
			(string) $import['delimiter_char'],
			(string) $import['enclosure_char'],
			(bool) $import['has_header']
		);

		$this->warm( $entity_type, $target, $mapping, $batch );

		$counts = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'error'   => 0,
		);

		foreach ( $batch as $index => $row ) {
			// The number a person sees in their spreadsheet: one-based, and one further along when
			// the file has a header. Reporting a zero-based data index would send them to the wrong
			// line, which is worse than reporting none.
			$line = $offset + $index + 1 + ( (bool) $import['has_header'] ? 1 : 0 );

			$outcome = $this->import_row( $entity_type, $target, $row, $mapping, $options, (string) $import['mode'] );

			++$counts[ $outcome['action'] ];

			$this->rows->record( $import_id, $line, $outcome );
		}

		$processed = $offset + count( $batch );

		/*
		 * TWO DIFFERENT QUESTIONS, AND CONFLATING THEM REPORTED A RUN FINISHED WITH STOCK STILL
		 * UNPOSTED. Reading the last row of the file is not the end of the work: opening stock is
		 * posted one document per call so no single request finalizes thousands of lines, so a file
		 * whose last batch leaves three warehouses part-full needs three more calls after the
		 * reading is over. `done` is the one the client loops on, and it has to mean both.
		 */
		$read = count( $batch ) < self::BATCH;

		$posted = $this->stock->flush( $import_id, $import, $read );

		$done = $read && 0 === $posted['pending'];

		$this->imports->update(
			$import_id,
			array(
				'offset_rows'       => $processed,
				'created_count'     => (int) $import['created_count'] + $counts['created'],
				'updated_count'     => (int) $import['updated_count'] + $counts['updated'],
				'skipped_count'     => (int) $import['skipped_count'] + $counts['skipped'],
				'error_count'       => (int) $import['error_count'] + $counts['error'],
				'stock_lines'       => (int) $import['stock_lines'] + $posted['lines'],
				'stock_error_count' => (int) $import['stock_error_count'] + $posted['errors'],
				'status'            => $done ? 'done' : 'running',
			)
		);

		return array(
			'import_id' => $import_id,
			'processed' => $processed,
			'total'     => (int) $import['total_rows'],
			'done'      => $done,
			'batch'     => $counts,
			'stock'     => $posted,
			// The client's second measure of progress. Once the file is read `processed` stops
			// moving while the documents are posted, and a loop watching only that would decide the
			// server had stalled.
			'pending'   => $posted['pending'],
		);
	}

	/**
	 * Look up everything this batch is going to ask about, in a handful of queries.
	 *
	 * THE WHOLE BATCH AT ONCE, NOT ROW BY ROW. A hundred rows referencing three categories is one
	 * query here and a hundred queries if each row resolves its own — and the difference is not
	 * academic on a file with forty thousand lines.
	 *
	 * @param string                  $entity_type Entity slug.
	 * @param array<string, mixed>    $target      The entity's mapping targets.
	 * @param array<int, string>      $mapping     Column index => target name.
	 * @param array<int, string[]>    $batch       The rows.
	 */
	private function warm( string $entity_type, array $target, array $mapping, array $batch ): void {
		$this->lookups->reset();
		$this->keys->reset();

		$fields = (array) $target['fields'];

		$records = array();
		$keys    = array();

		$key_columns = NaturalKey::columns_for( $entity_type );

		foreach ( $mapping as $column => $field ) {
			$related = $this->related_entity( $field, $fields, (array) $target['synthetic'] );
			$is_key  = in_array( $field, $key_columns, true );

			if ( null === $related && ! $is_key ) {
				continue;
			}

			foreach ( $batch as $row ) {
				$value = trim( (string) ( $row[ $column ] ?? '' ) );

				if ( '' === $value ) {
					continue;
				}

				if ( null !== $related ) {
					$records[ $related ][] = $value;
				}

				if ( $is_key ) {
					$keys[ $field ][] = $value;
				}
			}
		}

		if ( array() !== $records ) {
			$this->lookups->warm( $records );
		}

		if ( array() !== $keys ) {
			$this->keys->warm( $entity_type, $keys );
		}
	}

	/**
	 * One row.
	 *
	 * @param string                  $entity_type Entity slug.
	 * @param array<string, mixed>    $target      The entity's mapping targets.
	 * @param string[]                $row         The cells.
	 * @param array<int, string>      $mapping     Column index => target name.
	 * @param array<string, mixed>    $options     The run's synthetic choices.
	 * @param string                  $mode        create|create_update|update.
	 *
	 * @return array{action: string, entity_id: int|null, message: string, stock?: array{warehouse_id: int, quantity: float, cost: float}}
	 */
	private function import_row( string $entity_type, array $target, array $row, array $mapping, array $options, string $mode ): array {
		$fields    = (array) $target['fields'];
		$synthetic = (array) $target['synthetic'];
		$customs   = (array) $target['custom'];

		$data   = array();
		$extras = array();
		$stock  = array();

		foreach ( $mapping as $column => $field ) {
			if ( '' === $field || ! array_key_exists( $column, $row ) ) {
				continue;
			}

			$value = trim( (string) $row[ $column ] );

			/*
			 * AN EMPTY CELL IS "NO VALUE IN THIS FILE", NOT "SET THIS FIELD TO EMPTY". Sending ''
			 * would have update mode wipe every field the spreadsheet simply did not carry a column
			 * for — which is how somebody importing a price list erases every description they have.
			 */
			if ( '' === $value ) {
				continue;
			}

			if ( isset( $customs[ $field ] ) ) {
				// RAW, deliberately. The module that contributed this target owns what its own
				// types mean, and a second opinion here would be one too many.
				$extras[ $field ] = $value;

				continue;
			}

			if ( isset( $synthetic[ $field ] ) ) {
				$coerced = $this->coerce_one( $field, (array) $synthetic[ $field ], $value );

				if ( $coerced instanceof WP_Error ) {
					return $this->outcome( 'error', null, $coerced->get_error_message() );
				}

				$stock[ $field ] = $coerced;

				continue;
			}

			if ( ! isset( $fields[ $field ] ) ) {
				continue;
			}

			$coerced = $this->coerce_one( $field, (array) $fields[ $field ], $value );

			if ( $coerced instanceof WP_Error ) {
				return $this->outcome( 'error', null, $coerced->get_error_message() );
			}

			$data[ $field ] = $coerced;
		}

		if ( array() === $data && array() === $extras ) {
			return $this->outcome( 'skipped', null, __( 'The row was empty.', 'plugixa-inventory' ) );
		}

		$existing = $this->keys->match( $entity_type, $data );

		if ( null !== $existing ) {
			if ( 'create' === $mode ) {
				return $this->outcome( 'skipped', $existing, __( 'A record with that identifier already exists.', 'plugixa-inventory' ) );
			}

			$updated = $this->writer->update( $entity_type, $existing, $data );

			if ( $updated instanceof WP_Error ) {
				return $this->outcome( 'error', $existing, $updated->get_error_message() );
			}

			return $this->outcome( 'updated', $existing, $this->write_extras( $entity_type, $existing, $extras ) );
		}

		if ( 'update' === $mode ) {
			return $this->outcome( 'skipped', null, __( 'No existing record matched.', 'plugixa-inventory' ) );
		}

		$created = $this->writer->create( $entity_type, $data );

		if ( $created instanceof WP_Error ) {
			return $this->outcome( 'error', null, $created->get_error_message() );
		}

		$this->keys->remember( $entity_type, $created, $data );

		// So a later row in the same file can refer to what this one just created — a units file
		// that defines a base unit and then its derivatives is the ordinary way to write one.
		$this->lookups->remember( $entity_type, $data, $created );

		$message = $this->write_extras( $entity_type, $created, $extras );

		return $this->opening_stock( $entity_type, $created, $data, $stock, $options, $message );
	}

	/**
	 * Attach an opening-stock line to a row that CREATED a record.
	 *
	 * CREATED ONLY, AND THIS IS THE MOST IMPORTANT GUARD IN THE MODULE. Re-running the same file in
	 * "update, and add the ones that are new" is an ordinary thing for somebody to do — it is how
	 * you fix a column and try again. Posting an "opening" balance onto an item that already has
	 * stock would double the business's inventory, silently, through a document that looks entirely
	 * legitimate and reconciles perfectly.
	 *
	 * @param string                  $entity_type Entity slug.
	 * @param int                     $id          The new record.
	 * @param array<string, mixed>    $data        What was written.
	 * @param array<string, mixed>    $stock       The synthetic values from this row.
	 * @param array<string, mixed>    $options     The run's synthetic choices.
	 * @param string                  $message     Anything already worth saying about this row.
	 *
	 * @return array{action: string, entity_id: int|null, message: string, stock?: array{warehouse_id: int, quantity: float, cost: float}}
	 */
	private function opening_stock( string $entity_type, int $id, array $data, array $stock, array $options, string $message ): array {
		if ( 'item' !== $entity_type ) {
			return $this->outcome( 'created', $id, $message );
		}

		$quantity = (float) ( $stock[ ImportTargets::OPENING_QUANTITY ] ?? 0 );

		// No quantity column, or a zero in it, is not an error. Plenty of catalogues are imported
		// without any stock at all, and a zero opening balance is the same thing as none.
		if ( $quantity <= 0 ) {
			return $this->outcome( 'created', $id, $message );
		}

		$warehouse = (int) ( $stock[ ImportTargets::OPENING_WAREHOUSE ] ?? 0 );

		if ( $warehouse <= 0 ) {
			$warehouse = (int) ( $data['default_warehouse_id'] ?? 0 );
		}

		if ( $warehouse <= 0 ) {
			$warehouse = (int) ( $options['warehouse_id'] ?? 0 );
		}

		if ( $warehouse <= 0 ) {
			// The item exists and is correct. Calling the row an error because its stock had nowhere
			// to go would be a lie about the thing the user actually asked for.
			return $this->outcome(
				'created',
				$id,
				$this->join( $message, __( 'Opening stock was not posted: no warehouse.', 'plugixa-inventory' ) )
			);
		}

		$cost = array_key_exists( ImportTargets::OPENING_COST, $stock )
			? (float) $stock[ ImportTargets::OPENING_COST ]
			: (float) ( $data['cost_price'] ?? 0 );

		$outcome = $this->outcome( 'created', $id, $message );

		$outcome['stock'] = array(
			'warehouse_id' => $warehouse,
			'quantity'     => $quantity,
			'cost'         => $cost,
		);

		return $outcome;
	}

	/**
	 * Hand a module its own values to write, and collect what it could not.
	 *
	 * SEQUENCED AFTER THE RECORD, because a value needs something to belong to. A failure here keeps
	 * the record and names the line: rolling back an otherwise-correct item because one extra column
	 * was mistyped would be a worse trade, and it is the same one the coercer already makes — you
	 * lose the value, not the row.
	 *
	 * @param string                $entity_type Entity slug.
	 * @param int                   $id          The record.
	 * @param array<string, string> $extras      Target name => raw cell.
	 */
	private function write_extras( string $entity_type, int $id, array $extras ): string {
		if ( array() === $extras ) {
			return '';
		}

		/**
		 * Write the import targets a module contributed.
		 *
		 * The module that added a target through `plugixa_inventory_import_targets` writes it here,
		 * and returns a sentence for anything it could not store. Returning problems rather than
		 * throwing is deliberate: one bad extra costs a row its extra, never its record.
		 *
		 * @param string[]              $problems    What could not be written.
		 * @param string                $entity_type Entity slug.
		 * @param int                   $id          The record just written.
		 * @param array<string, string> $extras      Target name => the raw cell.
		 */
		$problems = (array) apply_filters( 'plugixa_inventory_import_write_extras', array(), $entity_type, $id, $extras );

		return implode( ' ', array_map( 'strval', $problems ) );
	}

	/**
	 * Coerce one cell, resolving a related record to its id.
	 *
	 * @param string               $field      Target name.
	 * @param array<string, mixed> $definition The target's entry.
	 * @param string               $value      The trimmed cell.
	 *
	 * @return mixed|WP_Error
	 */
	private function coerce_one( string $field, array $definition, string $value ): mixed {
		$type = (string) ( $definition['type'] ?? '' );

		if ( 'user' === $type ) {
			return $this->resolve_user( (string) ( $definition['label'] ?? $field ), $value );
		}

		if ( 'record' !== $type ) {
			return $this->coercer->coerce( $field, $definition, $value );
		}

		$entity = (string) ( $definition['entity'] ?? '' );
		$label  = (string) ( $definition['label'] ?? $field );

		if ( '' === $entity ) {
			return $value;
		}

		$resolved = $this->lookups->resolve( $entity, $value );

		if ( LookupCache::AMBIGUOUS === $resolved ) {
			return new WP_Error(
				'plugixa_inventory_import_ambiguous_reference',
				sprintf(
					/* translators: 1: a column label, 2: the value in the file, 3: a comma-separated list of record ids. */
					__( '%1$s: more than one record is called “%2$s” (%3$s). Use its code instead.', 'plugixa-inventory' ),
					$label,
					$value,
					implode( ', ', $this->lookups->clashing( $entity, $value ) )
				)
			);
		}

		if ( null === $resolved ) {
			return new WP_Error(
				'plugixa_inventory_import_unknown_reference',
				sprintf(
					/* translators: 1: a column label, 2: the value in the file. */
					__( '%1$s: nothing here is called “%2$s”.', 'plugixa-inventory' ),
					$label,
					$value
				)
			);
		}

		return $resolved;
	}

	/**
	 * A WordPress account, named the way somebody writes it in a spreadsheet.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * WITHOUT THIS THE COLUMN SILENTLY VANISHED. The receiving service casts the value with `(int)`
	 * and turns anything falsy into null, so a cell saying `ada@example.com` became `0`, then
	 * `null`, and the record was created with no user and no error at all — the exact silent
	 * failure the readonly and media exclusions exist to prevent, arriving through a field that
	 * looked importable.
	 *
	 * A NUMERIC ID IS ACCEPTED FIRST, because a file exported from the API carries one — but only
	 * when it really is a user, so a login that happens to be digits is not mistaken for an id.
	 * Then the login, then the email, which is what a person actually types.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $label Column label, for the message.
	 * @param string $value The trimmed cell.
	 *
	 * @return int|WP_Error
	 */
	private function resolve_user( string $label, string $value ): int|WP_Error {
		if ( 1 === preg_match( '/^[1-9][0-9]*$/', $value ) && null !== get_userdata( (int) $value ) ) {
			return (int) $value;
		}

		foreach ( array( 'login', 'email', 'slug' ) as $by ) {
			$user = get_user_by( $by, $value );

			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}

		return new WP_Error(
			'plugixa_inventory_import_unknown_user',
			sprintf(
				/* translators: 1: a column label, 2: the value in the file. */
				__( '%1$s: there is no user called “%2$s”.', 'plugixa-inventory' ),
				$label,
				$value
			)
		);
	}

	/**
	 * Which entity a target resolves against, if any.
	 *
	 * @param string                              $field     Target name.
	 * @param array<string, array<string, mixed>> $fields    Descriptor fields.
	 * @param array<string, array<string, mixed>> $synthetic Synthetic targets.
	 */
	private function related_entity( string $field, array $fields, array $synthetic ): ?string {
		$definition = $fields[ $field ] ?? $synthetic[ $field ] ?? null;

		if ( null === $definition || 'record' !== (string) ( $definition['type'] ?? '' ) ) {
			return null;
		}

		$entity = (string) ( $definition['entity'] ?? '' );

		return '' === $entity ? null : $entity;
	}

	/**
	 * @param array<string, mixed> $import The run.
	 *
	 * @return array<int, string>
	 */
	private function mapping( array $import ): array {
		$decoded = json_decode( (string) $import['mapping'], true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();

		foreach ( $decoded as $column => $field ) {
			$out[ (int) $column ] = sanitize_key( (string) $field );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $import The run.
	 *
	 * @return array<string, mixed>
	 */
	private function options( array $import ): array {
		$decoded = json_decode( (string) $import['options'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param string $first  Whatever was already worth saying.
	 * @param string $second And the next thing.
	 */
	private function join( string $first, string $second ): string {
		return trim( $first . ( '' === $first ? '' : ' ' ) . $second );
	}

	/**
	 * @param string   $action    created|updated|skipped|error.
	 * @param int|null $entity_id The record, if there is one.
	 * @param string   $message   Why, for the ones that need it.
	 *
	 * @return array{action: string, entity_id: int|null, message: string}
	 */
	private function outcome( string $action, ?int $entity_id, string $message ): array {
		return array(
			'action'    => $action,
			'entity_id' => $entity_id,
			'message'   => $message,
		);
	}

	private function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_not_found',
			__( 'That import does not exist.', 'plugixa-inventory' ),
			array( 'status' => 404 )
		);
	}
}
