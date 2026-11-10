<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Items\Services;

use PlugixaInventory\Core\Entities\RawInput;
use PlugixaInventory\Core\Entities\RecordLoader;
use PlugixaInventory\Core\Settings;
use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creating and editing items.
 *
 * HOW THIS VALIDATES A CATEGORY WITHOUT KNOWING THE CATEGORIES MODULE EXISTS. It asks
 * `RecordLoader`, which resolves any entity slug to a row through the registry, and which only
 * ever returns LIVE rows. Injecting CategoryRepository would be the obvious alternative and is the
 * one the architecture forbids: a module reaches another through the container, a filter or an
 * action, never by importing its repository. The same call validates the warehouse and the unit,
 * and it will validate anything a later module registers without this file changing.
 */
final class ItemService {

	/** @var string[] */
	public const STATUSES = array( 'published', 'unpublished' );

	/**
	 * @var string[]
	 *
	 * The three kinds of thing an inventory holds, and the distinction is not cosmetic: it decides
	 * which stock movements an item may appear on. An ASSET is checked in and out — a drill, a
	 * laptop, a thing that comes back. A CONSUMABLE is added and removed — cable ties, gloves. A
	 * SELLABLE is a consumable that also carries a sale price. The Stock module enforces the
	 * pairing; this is where the vocabulary is declared.
	 */
	public const TYPES = array( 'asset', 'consumable', 'sellable' );

	/**
	 * Weight units, and what one of each is in kilograms.
	 *
	 * A LOOKUP TABLE RATHER THAN THE UNIT TREE, deliberately. `weight_unit` is a short string on
	 * the item, not a row in `units`, because it answers a different question: `unit_id` is how the
	 * item is COUNTED (pieces, boxes, litres) and this is how it is WEIGHED. An item counted in
	 * boxes still has a mass in kilograms. Routing this through the editable unit tree would let a
	 * user redefine what a kilogram is, and would make `weight_base` mean something different
	 * before and after they did.
	 *
	 * The two imperial factors are exact by definition, not approximations: the international
	 * pound is defined as exactly 0.45359237 kg, and an ounce is a sixteenth of that.
	 *
	 * @var array<string, float>
	 */
	public const WEIGHT_UNITS = array(
		'mg' => 0.000001,
		'g'  => 0.001,
		'kg' => 1.0,
		't'  => 1000.0,
		'oz' => 0.028349523125,
		'lb' => 0.45359237,
	);

	private const SKU_PATTERN = '/^[A-Za-z0-9._\/-]+$/';

	public function __construct(
		private readonly ItemRepository $items,
		private readonly RecordLoader $records
	) {}

	/**
	 * @param int $id Item id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->items->find( $id );

		return null === $row ? null : $this->items->cast( $row );
	}

	/**
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return int|WP_Error
	 */
	public function create( array $input ): int|WP_Error {
		$refused = $this->refuse_unusable( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, true );

		$invalid = $this->validate( $data, 0 );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$id = $this->items->insert( $data );

		return $id > 0
			? $id
			: new WP_Error( 'plugixa_inventory_insert_failed', __( 'The item could not be saved.', 'plugixa-inventory' ), array( 'status' => 500 ) );
	}

	/**
	 * @param int                  $id    Item id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		$existing = $this->items->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$refused = $this->refuse_unusable( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, false );

		/*
		 * Weight normalisation needs BOTH halves, and a PATCH may carry only one. Changing the unit
		 * from kg to g without restating the number has to recompute `weight_base` against the new
		 * unit -- otherwise the item keeps a base of 2.0 kg while its form reads "2 g".
		 */
		$stored = $this->items->cast( $existing );
		$data   = $this->renormalise_weight( $data, $stored );

		/*
		 * THE STORED ROW TRAVELS WITH THE MERGE, so `validate()` can tell a reference this edit CHANGED
		 * from one it merely carried. The merge is still what gets validated — name, SKU and barcode
		 * rules apply to the item as it will be — but a reference nobody touched is not re-judged.
		 */
		$invalid = $this->validate( array_merge( $stored, $data ), $id, $stored );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		return $this->items->update( $id, $data );
	}

	/**
	 * Bring a trashed item back, refusing if its SKU or barcode has since been taken.
	 *
	 * @param int $id Item id.
	 *
	 * @return bool|WP_Error
	 */
	public function restore( int $id ): bool|WP_Error {
		$row = $this->items->find( $id, true );

		if ( null === $row ) {
			return $this->not_found();
		}

		$sku = (string) ( $row['sku'] ?? '' );
		if ( '' !== $sku && null !== $this->items->find_by_sku( $sku, $id ) ) {
			return $this->restore_collision( __( 'SKU', 'plugixa-inventory' ), $sku, $id );
		}

		$barcode = (string) ( $row['barcode'] ?? '' );
		if ( '' !== $barcode && null !== $this->items->find_by_barcode( $barcode, $id ) ) {
			return $this->restore_collision( __( 'barcode', 'plugixa-inventory' ), $barcode, $id );
		}

		return $this->items->restore( $id );
	}

	/**
	 * Trash an item, unless something still points at it.
	 *
	 * An item with stock on hand or with ledger history is not a row to remove quietly: the
	 * quantity is real and somebody is responsible for it. Stock answers this filter.
	 *
	 * @param int $id Item id.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$item = $this->items->find( $id );

		if ( null === $item ) {
			return $this->not_found();
		}

		/**
		 * Reasons this item must not be trashed.
		 *
		 * @param string[]            $blockers Human-readable sentences. Empty means "go ahead".
		 * @param int                 $id       Item id.
		 * @param array<string,mixed> $item     The row.
		 */
		$blockers = (array) apply_filters( 'plugixa_inventory_item_delete_blockers', array(), $id, $item );

		if ( array() !== $blockers ) {
			return new WP_Error(
				'plugixa_inventory_item_in_use',
				implode( ' ', array_map( 'strval', $blockers ) ),
				array( 'status' => 409, 'blockers' => array_values( $blockers ) )
			);
		}

		return $this->items->delete( $id );
	}

	/**
	 * Write the denormalised stock total. Called by the Stock module and by nothing else.
	 *
	 * It lives on the service rather than being reached on the repository directly because the
	 * architecture says a module calls another module's SERVICE — and because the day this needs
	 * to raise an event or clear a cache, there is somewhere to put it.
	 *
	 * @param int   $id    Item id.
	 * @param float $total The item's total across every warehouse.
	 */
	public function set_cached_stock( int $id, float $total ): bool {
		$done = $this->items->set_cached_stock( $id, $total );

		if ( $done ) {
			/**
			 * Fires when an item's on-hand total changes.
			 *
			 * ONE EVENT FOR THE WHOLE ITEM, not one per changed column. This is what the
			 * Notifications module listens to for low stock, and it is deliberately not
			 * `plugixa_inventory_entity_field_changed`: that fires per column, and a forty-line
			 * movement would raise forty of them for a column nobody edited.
			 *
			 * @param int   $id    Item id.
			 * @param float $total The new total.
			 */
			do_action( 'plugixa_inventory_stock_level_changed', $id, $total );
		}

		return $done;
	}

	/**
	 * The denormalised totals, for the Stock module's reconcile to check its own work against.
	 *
	 * A READ PASSTHROUGH, and it is here rather than on the repository for the same reason
	 * `set_cached_stock()` is: the Stock module talks to this service, so the day an item's totals
	 * come from somewhere else there is one place to change.
	 *
	 * @param int|null $item_id Narrow to one item, or null.
	 * @param int[]    $ids     Items to include regardless of their stored total.
	 *
	 * @return array<int, float> Item id => stored `stock_quantity`.
	 */
	public function cached_totals( ?int $item_id = null, array $ids = array() ): array {
		return $this->items->cached_totals( $item_id, $ids );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_not_found',
			__( 'That item does not exist.', 'plugixa-inventory' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * @param string $label What collided, already translated.
	 * @param string $value The colliding value.
	 * @param int    $id    Item id.
	 */
	private function restore_collision( string $label, string $value, int $id ): WP_Error {
		return new WP_Error(
			'plugixa_inventory_duplicate_identifier',
			sprintf(
				/* translators: 1: "SKU" or "barcode", 2: the value. */
				__( 'This item cannot be restored: another item is now using the %1$s "%2$s". Change that one first.', 'plugixa-inventory' ),
				$label,
				$value
			),
			array( 'status' => 409, 'id' => $id, 'value' => $value )
		);
	}

	/**
	 * Refuse what `sanitize()` below would otherwise replace without a word.
	 *
	 * Each check compares the way its sanitizer does — the type and status through `sanitize_key()`,
	 * the weight unit exactly — so a value cannot pass here and still be swapped for a default there.
	 * See `RawInput` for why this runs first.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 */
	private function refuse_unusable( array $input ): ?WP_Error {
		return RawInput::first(
			RawInput::choice( $input, 'item_type', self::TYPES, __( 'Type', 'plugixa-inventory' ) ),
			RawInput::choice( $input, 'status', self::STATUSES, __( 'Status', 'plugixa-inventory' ) ),
			RawInput::choice( $input, 'weight_unit', array_keys( self::WEIGHT_UNITS ), __( 'Weight unit', 'plugixa-inventory' ), false ),
			RawInput::number( $input, 'reorder_level', __( 'Reorder level', 'plugixa-inventory' ) ),
			RawInput::number( $input, 'unit_price', __( 'Sale price', 'plugixa-inventory' ) ),
			RawInput::number( $input, 'cost_price', __( 'Cost price', 'plugixa-inventory' ) ),
			RawInput::number( $input, 'weight', __( 'Weight', 'plugixa-inventory' ) )
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

		foreach ( array( 'name', 'serial_number' ) as $field ) {
			if ( $with_defaults || array_key_exists( $field, $input ) ) {
				$data[ $field ] = sanitize_text_field( (string) ( $input[ $field ] ?? '' ) );
			}
		}

		/*
		 * SKU AND BARCODE KEEP THEIR CASE, unlike a category code. A barcode is read off a label by
		 * a scanner and compared byte for byte; upper-casing it would break the P6 lookup for every
		 * code that contains a lower-case letter. Uniqueness is still case-insensitive, because
		 * MySQL's default collation is.
		 */
		foreach ( array( 'sku', 'barcode' ) as $field ) {
			if ( $with_defaults || array_key_exists( $field, $input ) ) {
				$data[ $field ] = trim( sanitize_text_field( (string) ( $input[ $field ] ?? '' ) ) );
			}
		}

		if ( $with_defaults || array_key_exists( 'item_type', $input ) ) {
			$type              = sanitize_key( (string) ( $input['item_type'] ?? 'consumable' ) );
			$data['item_type'] = in_array( $type, self::TYPES, true ) ? $type : 'consumable';
		}

		foreach ( array( 'category_id', 'default_warehouse_id', 'unit_id', 'image_id', 'assigned_user_id' ) as $field ) {
			if ( $with_defaults || array_key_exists( $field, $input ) ) {
				$value          = (int) ( $input[ $field ] ?? 0 );
				$data[ $field ] = $value > 0 ? $value : null;
			}
		}

		foreach ( array( 'reorder_level', 'unit_price', 'cost_price', 'weight' ) as $field ) {
			if ( $with_defaults || array_key_exists( $field, $input ) ) {
				// Negative money and negative reorder levels are not opinions, they are typos.
				$data[ $field ] = max( 0.0, (float) ( $input[ $field ] ?? 0 ) );
			}
		}

		/*
		 * THE TWO SITE DEFAULTS ARE APPLIED HERE RATHER THAN IN THE FORM, and only when the payload
		 * is silent about them. Putting them in the React form would mean a CSV import and an API
		 * create quietly disagreeing with what the settings screen says happens to a new item —
		 * and an import is exactly where "every item weighs something" matters most. The form still
		 * reads them, so the checkbox a user is looking at agrees with what will be saved, but it
		 * is not the thing enforcing them. See `Items\Module::register_settings()`.
		 */
		if ( $with_defaults || array_key_exists( 'weight_unit', $input ) ) {
			$fallback            = $with_defaults ? (string) Settings::get( 'default_weight_unit', 'kg' ) : '';
			$unit                = (string) ( $input['weight_unit'] ?? $fallback );
			$data['weight_unit'] = isset( self::WEIGHT_UNITS[ $unit ] ) ? $unit : '';
		}

		if ( $with_defaults || array_key_exists( 'track_weight', $input ) ) {
			$tracked = array_key_exists( 'track_weight', $input )
				? ! empty( $input['track_weight'] )
				: ( $with_defaults && (bool) Settings::get( 'track_weight_by_default', false ) );

			$data['track_weight'] = $tracked ? 1 : 0;
		}

		if ( $with_defaults || array_key_exists( 'description', $input ) ) {
			$data['description'] = wp_kses_post( (string) ( $input['description'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'status', $input ) ) {
			$status         = sanitize_key( (string) ( $input['status'] ?? 'published' ) );
			$data['status'] = in_array( $status, self::STATUSES, true ) ? $status : 'published';
		}

		if ( array_key_exists( 'weight', $data ) || array_key_exists( 'weight_unit', $data ) ) {
			$data = $this->renormalise_weight( $data, array() );
		}

		return $data;
	}

	/**
	 * Recompute `weight_base` from whichever of weight and weight_unit we now have.
	 *
	 * The whole reason `weight_base` exists is that summing `weight` across mixed units adds grams
	 * to tonnes. It is only correct if it is recomputed whenever EITHER half changes, which is why
	 * this is called from both sanitize() (create) and update() (where the other half comes off the
	 * stored row).
	 *
	 * An unknown or empty unit gives a base of zero rather than assuming kilograms. A guess here
	 * would be a number that looks like a weight and is not one.
	 *
	 * @param array<string, mixed> $data     The payload so far.
	 * @param array<string, mixed> $existing The stored row, or empty on create.
	 *
	 * @return array<string, mixed>
	 */
	private function renormalise_weight( array $data, array $existing ): array {
		if ( ! array_key_exists( 'weight', $data ) && ! array_key_exists( 'weight_unit', $data ) ) {
			return $data;
		}

		$weight = (float) ( $data['weight'] ?? $existing['weight'] ?? 0 );
		$unit   = (string) ( $data['weight_unit'] ?? $existing['weight_unit'] ?? '' );

		$data['weight_base'] = isset( self::WEIGHT_UNITS[ $unit ] )
			? round( $weight * self::WEIGHT_UNITS[ $unit ], 4 )
			: 0.0;

		return $data;
	}

	/**
	 * @param array<string, mixed> $data   Sanitized payload, merged over the stored row on update.
	 * @param int                  $id     Row being updated, or 0 when creating.
	 * @param array<string, mixed> $stored The stored row, cast; empty on create.
	 */
	private function validate( array $data, int $id, array $stored = array() ): ?WP_Error {
		if ( array_key_exists( 'name', $data ) && '' === trim( (string) $data['name'] ) ) {
			return new WP_Error(
				'plugixa_inventory_missing_name',
				__( 'An item needs a name.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$too_long = $this->items->too_long( $data );
		if ( $too_long instanceof WP_Error ) {
			return $too_long;
		}

		foreach ( array( 'sku' => __( 'SKU', 'plugixa-inventory' ), 'barcode' => __( 'barcode', 'plugixa-inventory' ) ) as $field => $label ) {
			$value = (string) ( $data[ $field ] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			if ( 1 !== preg_match( self::SKU_PATTERN, $value ) ) {
				return new WP_Error(
					'plugixa_inventory_invalid_identifier',
					sprintf(
						/* translators: %s: "SKU" or "barcode". */
						__( 'A %s may only contain letters, digits, dots, dashes, underscores and slashes.', 'plugixa-inventory' ),
						$label
					),
					array( 'status' => 422, 'field' => $field )
				);
			}

			$clash = 'sku' === $field
				? $this->items->find_by_sku( $value, $id )
				: $this->items->find_by_barcode( $value, $id );

			if ( null !== $clash ) {
				return new WP_Error(
					'plugixa_inventory_duplicate_identifier',
					sprintf(
						/* translators: 1: "SKU" or "barcode", 2: the value, 3: the other item's name. */
						__( 'The %1$s "%2$s" is already used by "%3$s".', 'plugixa-inventory' ),
						$label,
						$value,
						(string) $clash['name']
					),
					array( 'status' => 409, 'field' => $field, 'conflict_id' => (int) $clash['id'] )
				);
			}
		}

		/*
		 * ═══════════════════════════════════════════════════════════════════════════════════════════
		 * EVERY FOREIGN KEY, CHECKED GENERICALLY — BUT ONLY WHEN THIS EDIT CHANGES IT.
		 *
		 * The references used to be judged from the merged row, so a value nobody touched was
		 * re-validated on every save. That locked items out of editing in three real situations: the
		 * item's category or default warehouse was trashed later (`RecordLoader` returns live rows
		 * only); the site went back to the free build, which registers no `unit` entity, so any item
		 * given a unit on the premium build refused every save — with the error attached to a field
		 * the free form does not even show. Reproduced on Linux: renaming such an item returned 422.
		 *
		 * A NO-OP IS NOT AN ATTEMPT. Leaving a reference as it was is not a claim that it is valid
		 * today, and refusing the rename for it punishes the one thing the user did not do. Pointing
		 * an item at a record that cannot be verified IS still refused, and clearing one is allowed,
		 * because `null` below is never checked.
		 * ═══════════════════════════════════════════════════════════════════════════════════════════
		 */
		$references = array(
			'category_id'          => array( 'category', __( 'category', 'plugixa-inventory' ) ),
			'default_warehouse_id' => array( 'warehouse', __( 'warehouse', 'plugixa-inventory' ) ),
			'unit_id'              => array( 'unit', __( 'unit', 'plugixa-inventory' ) ),
		);

		foreach ( $references as $field => list( $entity, $label ) ) {
			$value = $data[ $field ] ?? null;

			if ( null === $value || self::unchanged( $field, $value, $stored ) ) {
				continue;
			}

			if ( null === $this->records->load( $entity, (int) $value ) ) {
				return new WP_Error(
					'plugixa_inventory_unknown_reference',
					sprintf(
						/* translators: %s: the kind of record, e.g. "category". */
						__( 'That %s does not exist.', 'plugixa-inventory' ),
						$label
					),
					array( 'status' => 422, 'field' => $field )
				);
			}
		}

		/*
		 * AN IMAGE HAS TO BE AN ATTACHMENT, not just a post id. Without the type check any post id
		 * is accepted, `wp_get_attachment_image_url()` returns false for it, and the item list
		 * renders a broken thumbnail with nothing to explain it.
		 */
		$image = $data['image_id'] ?? null;

		if ( null !== $image && ! self::unchanged( 'image_id', $image, $stored ) && 'attachment' !== get_post_type( (int) $image ) ) {
			return new WP_Error(
				'plugixa_inventory_invalid_image',
				__( 'That image is not in the media library.', 'plugixa-inventory' ),
				array( 'status' => 422, 'field' => 'image_id' )
			);
		}

		if (
			isset( $data['assigned_user_id'] )
			&& ! self::unchanged( 'assigned_user_id', $data['assigned_user_id'], $stored )
			&& false === get_userdata( (int) $data['assigned_user_id'] )
		) {
			return new WP_Error(
				'plugixa_inventory_unknown_user',
				__( 'That WordPress user does not exist.', 'plugixa-inventory' ),
				array( 'status' => 422, 'field' => 'assigned_user_id' )
			);
		}

		return null;
	}

	/**
	 * Does this value merely repeat what is already stored?
	 *
	 * Compared as integers because every reference is an id: `cast()` yields `int|null` and a
	 * payload may carry the same id as a string. On create `$stored` is empty, so nothing is ever
	 * "unchanged" and everything is validated.
	 *
	 * @param string               $field  The column.
	 * @param mixed                $value  The value the merged row carries.
	 * @param array<string, mixed> $stored The stored row, cast.
	 */
	private static function unchanged( string $field, mixed $value, array $stored ): bool {
		$before = $stored[ $field ] ?? null;

		return null !== $before && (int) $before === (int) $value;
	}
}
