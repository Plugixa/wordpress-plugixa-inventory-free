<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\StockMovements\Services;

use PlugixaInventory\Core\Documents\StatusMachine;
use PlugixaInventory\Core\Entities\RawInput;
use PlugixaInventory\Core\Entities\RecordLoader;
use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use PlugixaInventory\Modules\Items\Services\ItemService;
use PlugixaInventory\Modules\Stock\Services\DocumentService;
use PlugixaInventory\Modules\Stock\Services\StockBatch;
use PlugixaInventory\Modules\Stock\Services\StockEngine;
use PlugixaInventory\Modules\Stock\Services\StockLine;
use PlugixaInventory\Modules\StockMovements\Repositories\StockMovementLineRepository;
use PlugixaInventory\Modules\StockMovements\Repositories\StockMovementRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock movements: things arriving, leaving, going out to somebody and coming back.
 *
 * THE SAME MACHINERY AS AN ADJUSTMENT PLUS TWO RULES, and both are about the fact that this is the
 * document type that distinguishes an asset from a consumable.
 *
 *   1. THE PERSON RULE. `check_in`/`check_out` are asset-only, because they are the pair that
 *      involves somebody: "checking out" a box of screws is not a thing that means anything, since
 *      nobody brings them back, and the recipient a check-out demands would be a person
 *      permanently holding a consumable.
 *   2. A CHECK-OUT NEEDS A RECIPIENT. A laptop that has left the building with no record of who has
 *      it is the single most useful fact about the movement, missing.
 *
 * Neither is expressible in the schema, so both live here — which is also where a CSV import would
 * hit them, because an importer reaches services rather than tables.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * `add_stock` AND `remove_stock` TAKE EVERY ITEM TYPE, ASSETS INCLUDED, AND THAT IS A CHANGE.
 *
 * They used to be consumable-and-sellable only, which made the table read as two disjoint halves —
 * one pair for assets, one for everything else. That was a misreading of its own rule. What
 * actually separates the pairs is not the ITEM, it is whether a PERSON is on the other side:
 * check-in and check-out are about custody, add and remove are about the quantity on the shelf.
 * Ten laptops arriving from a supplier, or five written off after a flood, are movements with
 * nobody on either side — and there was no free document that could record them.
 *
 * IT MATTERS BECAUSE THE ALTERNATIVE HAD NO ANSWER AT ALL. An opening balance for an asset had to
 * be a `check_in`, and its reversal a `check_out` — which demands a recipient who does not exist,
 * because nobody has the stock; it never left the building. So the operation was expressible in one
 * direction and not the other, which for a CSV import means a run that can be made and cannot be
 * undone.
 *
 * Rule 2 is untouched: a check-out still needs a recipient, and that is the rule this was ever
 * really about.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final class StockMovementService extends DocumentService {

	/** @var string[] */
	public const STATUSES = array( 'draft', 'finalized' );

	/** @var string[] */
	public const TYPES = array( 'check_in', 'check_out', 'add_stock', 'remove_stock' );

	/** Which types take stock away. */
	private const NEGATIVE = array( 'check_out', 'remove_stock' );

	/** Which type needs somebody's name on it. */
	private const NEEDS_RECIPIENT = array( 'check_out' );

	/**
	 * Which item types each movement type may carry.
	 *
	 * ONE ARRAY, CHECKED PER LINE. The Joomla component this ports spells the same rule out in a
	 * method per case; a table is checked by being read, and adding a fifth movement type is a row
	 * rather than a branch.
	 *
	 * @var array<string, string[]>
	 */
	private const ALLOWED_ITEM_TYPES = array(
		'check_in'     => array( 'asset' ),
		'check_out'    => array( 'asset' ),
		// Every type, deliberately — see the class docblock. These two are the pair with nobody on
		// the other side, which is what an opening balance and a write-off both are.
		'add_stock'    => array( 'asset', 'consumable', 'sellable' ),
		'remove_stock' => array( 'asset', 'consumable', 'sellable' ),
	);

	public function __construct(
		private readonly StockMovementRepository $movements,
		private readonly StockMovementLineRepository $movement_lines,
		StockEngine $engine,
		ItemRepository $items,
		private readonly RecordLoader $records
	) {
		parent::__construct( $movements, $movement_lines, $engine, $items );
	}

	protected function entity_type(): string {
		return 'stock_movement';
	}

	protected function prefix(): string {
		return 'SM-';
	}

	protected function date_column(): string {
		return 'movement_date';
	}

	protected function machine(): StatusMachine {
		return $this->draft_to_finalized();
	}

	/**
	 * The item types a movement type may carry, for the client's picker.
	 *
	 * @param string $movement_type One of TYPES.
	 *
	 * @return string[]
	 */
	public static function item_types_for( string $movement_type ): array {
		return self::ALLOWED_ITEM_TYPES[ $movement_type ] ?? array();
	}

	/**
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return int|WP_Error
	 */
	public function create( array $input ): int|WP_Error {
		/*
		 * BEFORE sanitize(), which turned an unknown type into `add_stock` and an impossible date into
		 * "now" — and a document built from those finalizes like any other. See RawInput.
		 */
		$refused = $this->refuse_unusable( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, true );

		$invalid = $this->validate( $data );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$data['status'] = 'draft';

		$id = $this->movements->insert( $data );

		if ( 0 === $id ) {
			return new WP_Error(
				'plugixa_inventory_insert_failed',
				__( 'The stock movement could not be saved.', 'plugixa-inventory' ),
				array( 'status' => 500 )
			);
		}

		$lines = $this->replace_lines( $id, (array) ( $input['lines'] ?? array() ) );

		if ( $lines instanceof WP_Error ) {
			$this->movement_lines->purge_for_document( $id );
			$this->movements->purge( $id );

			return $lines;
		}

		return $id;
	}

	/**
	 * @param int                  $id    Movement id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		$existing = $this->movements->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$refused = $this->refuse_unusable( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, false );

		unset( $data['status'], $data['finalized_at'], $data['finalized_by'], $data['batch_id'] );

		/*
		 * THE MOVEMENT TYPE IS FROZEN ONCE THERE ARE LINES, even on a draft, and this is stricter
		 * than `Immutability` requires. The lines were chosen against the type's allowed item types:
		 * a draft full of assets, switched to `add_stock`, becomes a document every line of which is
		 * illegal — and the user finds out at finalize, having lost the reason they were valid. Type
		 * first, then lines.
		 */
		if ( array_key_exists( 'movement_type', $data ) && $data['movement_type'] !== $existing['movement_type'] ) {
			if ( $this->movement_lines->count_for_document( $id ) > 0 && ! array_key_exists( 'lines', $input ) ) {
				return new WP_Error(
					'plugixa_inventory_type_locked',
					__( 'Remove the lines before changing the movement type: they were added for the type this document had.', 'plugixa-inventory' ),
					array( 'status' => 409, 'field' => 'movement_type' )
				);
			}
		}

		$guard = $this->guard_edit( $id, $data );
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$invalid = $this->validate( array_merge( (array) $this->get( $id ), $data ) );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		if ( array_key_exists( 'lines', $input ) ) {
			$lines_guard = $this->guard_lines( $id );
			if ( $lines_guard instanceof WP_Error ) {
				return $lines_guard;
			}

			$lines = $this->replace_lines( $id, (array) $input['lines'] );
			if ( $lines instanceof WP_Error ) {
				return $lines;
			}
		}

		return $this->movements->update( $id, $data );
	}

	/**
	 * The two rules this document type adds.
	 *
	 * BOTH REPORT EVERY OFFENDING LINE AT ONCE. A movement with four wrong-typed items should say
	 * so in one response, naming each — that is what turns an unusable form into one that can be
	 * fixed in a single pass.
	 *
	 * @param array<string, mixed>            $document The row.
	 * @param array<int, array<string,mixed>> $lines    Its lines.
	 */
	protected function validate_for_finalize( array $document, array $lines ): true|WP_Error {
		$type = (string) $document['movement_type'];

		if ( null === $this->records->load( 'warehouse', (int) $document['warehouse_id'] ) ) {
			return new WP_Error(
				'plugixa_inventory_unknown_warehouse',
				__( 'That warehouse does not exist.', 'plugixa-inventory' ),
				array( 'status' => 422, 'field' => 'warehouse_id' )
			);
		}

		if ( in_array( $type, self::NEEDS_RECIPIENT, true ) && null === $this->recipient_of( $document ) ) {
			return new WP_Error(
				'plugixa_inventory_recipient_required',
				__( 'A check-out needs a recipient: somebody has to be recorded as holding this.', 'plugixa-inventory' ),
				array( 'status' => 422, 'field' => 'recipient_id' )
			);
		}

		$allowed  = self::ALLOWED_ITEM_TYPES[ $type ] ?? array();
		$mismatch = array();
		$missing  = array();

		foreach ( $lines as $line ) {
			$item = $this->records->load( 'item', (int) $line['item_id'] );

			if ( null === $item ) {
				$missing[] = array( 'line_id' => (int) $line['id'], 'item_id' => (int) $line['item_id'] );

				continue;
			}

			if ( ! in_array( (string) $item['item_type'], $allowed, true ) ) {
				$mismatch[] = array(
					'line_id'   => (int) $line['id'],
					'item_id'   => (int) $item['id'],
					'item_name' => (string) $item['name'],
					'item_type' => (string) $item['item_type'],
				);
			}
		}

		if ( array() !== $missing ) {
			return new WP_Error(
				'plugixa_inventory_invalid_lines',
				__( 'Some lines point at an item that no longer exists.', 'plugixa-inventory' ),
				array( 'status' => 422, 'lines' => $missing )
			);
		}

		if ( array() !== $mismatch ) {
			return new WP_Error(
				'plugixa_inventory_item_type_mismatch',
				in_array( $type, array( 'check_in', 'check_out' ), true )
					? __( 'Check-in and check-out are for assets — things that come back. Remove the consumable and sellable lines, or use add stock / remove stock instead.', 'plugixa-inventory' )
					: __( 'Add stock and remove stock are for consumables and sellables. Assets are checked in and out instead, so that there is a record of who has them.', 'plugixa-inventory' ),
				array(
					'status'        => 422,
					'movement_type' => $type,
					'lines'         => $mismatch,
				)
			);
		}

		return true;
	}

	/**
	 * Turn the document into stock changes.
	 *
	 * One line in, one line out — the sign from the movement type, and the recipient copied onto
	 * every ledger row so the ledger reads without a join.
	 *
	 * @param array<string, mixed>            $document The row.
	 * @param array<int, array<string,mixed>> $lines    Its lines.
	 * @param string                          $batch_id The batch id.
	 */
	protected function compile( array $document, array $lines, string $batch_id ): StockBatch {
		$type      = (string) $document['movement_type'];
		$warehouse = (int) $document['warehouse_id'];
		$sign      = in_array( $type, self::NEGATIVE, true ) ? -1.0 : 1.0;

		$recipient      = $this->recipient_of( $document );
		$recipient_id   = null === $recipient ? null : (int) $recipient['id'];
		$recipient_name = null === $recipient ? '' : (string) $recipient['name'];

		$reason   = $this->reason_for( $document );
		$occurred = $this->occurred_at( $document );
		$stock    = array();

		foreach ( $lines as $line ) {
			$stock[] = new StockLine(
				item_id: (int) $line['item_id'],
				warehouse_id: $warehouse,
				delta: $sign * (float) $line['quantity'],
				transaction_type: $type,
				cost_per_unit: (float) $line['cost_per_unit'],
				weight: (float) $line['weight'],
				weight_unit: (string) $line['weight_unit'],
				weight_base: (float) $line['weight_base'],
				unit_id: $line['unit_id'] ?? null,
				source_line_id: (int) $line['id'],
				recipient: $recipient_name,
				recipient_id: $recipient_id,
				reason: $reason,
				occurred_at: $occurred
			);
		}

		return new StockBatch( 'stock_movement', (int) $document['id'], $stock, $batch_id );
	}

	/**
	 * The recipient row, or null.
	 *
	 * @param array<string, mixed> $document The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function recipient_of( array $document ): ?array {
		$id = (int) ( $document['recipient_id'] ?? 0 );

		return $id > 0 ? $this->records->load( 'recipient', $id ) : null;
	}

	/**
	 * What the ledger row says this movement was for.
	 *
	 * @param array<string, mixed> $document The row.
	 */
	private function reason_for( array $document ): string {
		$details = trim( wp_strip_all_tags( (string) ( $document['details'] ?? '' ) ) );

		if ( '' !== $details ) {
			return $details;
		}

		$reference = (string) ( $document['reference'] ?? '' );

		return sprintf(
			/* translators: 1: movement type, 2: document reference or id. */
			__( 'Stock movement (%1$s) %2$s', 'plugixa-inventory' ),
			(string) $document['movement_type'],
			'' !== $reference ? $reference : '#' . $document['id']
		);
	}

	/**
	 * Replace a draft's lines wholesale.
	 *
	 * @param int                             $id    Movement id.
	 * @param array<int, array<string,mixed>> $input Raw line payloads.
	 *
	 * @return true|WP_Error
	 */
	private function replace_lines( int $id, array $input ): true|WP_Error {
		$clean    = array();
		$position = 0;

		foreach ( $input as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			/*
			 * THE NUMBERS AS SENT, before `(float)` reads "12,5" as 12 and "abc" as 0. Quantity is only
			 * checked for being a number: zero and negatives already have their own refusal just below,
			 * and its wording is the one the form shows.
			 */
			$refused = RawInput::first(
				RawInput::number( $raw, 'quantity', __( 'Quantity', 'plugixa-inventory' ), true ),
				RawInput::number( $raw, 'cost_per_unit', __( 'Cost per unit', 'plugixa-inventory' ) ),
				RawInput::number( $raw, 'weight', __( 'Weight', 'plugixa-inventory' ) ),
				RawInput::choice( $raw, 'weight_unit', array_keys( ItemService::WEIGHT_UNITS ), __( 'Weight unit', 'plugixa-inventory' ), false )
			);

			if ( $refused instanceof WP_Error ) {
				return RawInput::for_line( $refused, $raw, (int) $index );
			}

			$item_id  = (int) ( $raw['item_id'] ?? 0 );
			$quantity = (float) ( $raw['quantity'] ?? 0 );

			if ( $item_id <= 0 && $quantity <= 0 ) {
				continue;
			}

			if ( $item_id <= 0 ) {
				return new WP_Error(
					'plugixa_inventory_line_needs_item',
					__( 'Every line needs an item.', 'plugixa-inventory' ),
					array( 'status' => 422 )
				);
			}

			if ( $quantity <= 0 ) {
				return new WP_Error(
					'plugixa_inventory_line_needs_quantity',
					__( 'Every line needs a quantity greater than zero.', 'plugixa-inventory' ),
					array( 'status' => 422, 'item_id' => $item_id )
				);
			}

			$weight      = max( 0.0, (float) ( $raw['weight'] ?? 0 ) );
			$weight_unit = (string) ( $raw['weight_unit'] ?? '' );
			$unit        = (int) ( $raw['unit_id'] ?? 0 );

			$clean[] = array(
				'movement_id'    => $id,
				'item_id'        => $item_id,
				'unit_id'        => $unit > 0 ? $unit : null,
				'quantity'       => $quantity,
				'weight'         => $weight,
				'weight_unit'    => isset( ItemService::WEIGHT_UNITS[ $weight_unit ] ) ? $weight_unit : '',
				// Normalised here for the same reason it is on the item: summing a column that
				// mixes grams and tonnes produces a number that means nothing.
				'weight_base'    => isset( ItemService::WEIGHT_UNITS[ $weight_unit ] )
					? round( $weight * ItemService::WEIGHT_UNITS[ $weight_unit ], 4 )
					: 0.0,
				'cost_per_unit'  => max( 0.0, (float) ( $raw['cost_per_unit'] ?? 0 ) ),
				'total_cost'     => 0.0,
				'transaction_id' => 0,
				'notes'          => sanitize_text_field( (string) ( $raw['notes'] ?? '' ) ),
				'position'       => ++$position,
			);
		}

		$this->movement_lines->purge_for_document( $id );

		foreach ( $clean as $line ) {
			$this->movement_lines->insert( $line );
		}

		return true;
	}

	/**
	 * Refuse the header values `sanitize()` below would otherwise replace without a word.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 */
	private function refuse_unusable( array $input ): ?WP_Error {
		return RawInput::first(
			RawInput::choice( $input, 'movement_type', self::TYPES, __( 'Movement type', 'plugixa-inventory' ) ),
			RawInput::date( $input, 'movement_date', __( 'Date', 'plugixa-inventory' ) )
		);
	}

	/**
	 * @param array<string, mixed> $input         Raw payload.
	 * @param bool                 $with_defaults Fill absent keys, for a create.
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input, bool $with_defaults ): array {
		$data = array();

		if ( $with_defaults || array_key_exists( 'reference', $input ) ) {
			$data['reference'] = sanitize_text_field( (string) ( $input['reference'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'movement_type', $input ) ) {
			$type                   = sanitize_key( (string) ( $input['movement_type'] ?? 'add_stock' ) );
			$data['movement_type']  = in_array( $type, self::TYPES, true ) ? $type : 'add_stock';
		}

		if ( $with_defaults || array_key_exists( 'warehouse_id', $input ) ) {
			$data['warehouse_id'] = (int) ( $input['warehouse_id'] ?? 0 );
		}

		if ( $with_defaults || array_key_exists( 'recipient_id', $input ) ) {
			$recipient            = (int) ( $input['recipient_id'] ?? 0 );
			$data['recipient_id'] = $recipient > 0 ? $recipient : null;
		}

		if ( $with_defaults || array_key_exists( 'movement_date', $input ) ) {
			// Empty means today; anything unusable was refused before this ran.
			$data['movement_date'] = RawInput::to_mysql( (string) ( $input['movement_date'] ?? '' ) ) ?? current_time( 'mysql' );
		}

		if ( $with_defaults || array_key_exists( 'details', $input ) ) {
			$data['details'] = wp_kses_post( (string) ( $input['details'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'assigned_user_id', $input ) ) {
			$user                     = (int) ( $input['assigned_user_id'] ?? 0 );
			$data['assigned_user_id'] = $user > 0 ? $user : null;
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data Sanitized payload, merged over the stored row on update.
	 */
	private function validate( array $data ): ?WP_Error {
		$too_long = $this->movements->too_long( $data );
		if ( $too_long instanceof WP_Error ) {
			return $too_long;
		}

		$warehouse = (int) ( $data['warehouse_id'] ?? 0 );

		if ( $warehouse <= 0 ) {
			return new WP_Error(
				'plugixa_inventory_missing_warehouse',
				__( 'A stock movement needs a warehouse.', 'plugixa-inventory' ),
				array( 'status' => 422, 'field' => 'warehouse_id' )
			);
		}

		if ( null === $this->records->load( 'warehouse', $warehouse ) ) {
			return new WP_Error(
				'plugixa_inventory_unknown_warehouse',
				__( 'That warehouse does not exist.', 'plugixa-inventory' ),
				array( 'status' => 422, 'field' => 'warehouse_id' )
			);
		}

		$recipient = $data['recipient_id'] ?? null;

		if ( null !== $recipient && null === $this->records->load( 'recipient', (int) $recipient ) ) {
			return new WP_Error(
				'plugixa_inventory_unknown_recipient',
				__( 'That recipient does not exist.', 'plugixa-inventory' ),
				array( 'status' => 422, 'field' => 'recipient_id' )
			);
		}

		/*
		 * THE RECIPIENT IS NOT REQUIRED AT SAVE, only at finalize. A half-filled draft is a normal
		 * thing to have — somebody starts a check-out, walks to the store, and fills in who took it
		 * when they know. Requiring it here would mean the draft could not be saved at all.
		 */

		return null;
	}
}
