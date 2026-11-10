<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Services;

use PlugixaInventory\Core\Entities\EntityRegistry;
use PlugixaInventory\Core\Entities\RecordWriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a column may be mapped to.
 *
 * WHICH ENTITIES: exactly the ones that registered a generic writer. An entity with no writer is not
 * offered rather than being offered and then failing on the first row — and the four kinds that
 * register none did so deliberately. A document is not a row you import, and a ledger that could be
 * written by an importer is not an audit trail. Both refusals are written up where they are made,
 * and this class must never work around either.
 *
 * WHICH FIELDS: not simply "all of them", which is where the sibling plugin's version stops. Three
 * exclusions, each for a failure that would otherwise be silent:
 *
 *  1. **`readonly` fields.** `stock_quantity` is a cache the Stock module owns and is not in the
 *     item repository's fillable list, so mapping a column to it writes nothing at all — the import
 *     reports success and the number never changes. A column that maps, "works", and does nothing is
 *     worse than one that was never offered. Opening stock has a real target instead; see below.
 *  2. **`media` fields.** `image_id` is validated as an attachment id, so every non-empty cell in a
 *     spreadsheet is a row error and every empty one a no-op. Importing images by URL is a different
 *     feature with its own security surface.
 *  3. **Anything starting `_` or `custom_`,** reserving the two synthetic namespaces below. No
 *     descriptor field on any of the five entities begins with either, and this keeps it that way.
 *
 * THE SYNTHETIC TARGETS ARE NOT FIELDS AT ALL. `_opening_quantity` does not fill a column; it makes
 * the run post a real, finalized stock movement. That is why they are a separate group with a
 * heading that says so, and why the guess on the client is forbidden from ever selecting one: the
 * CSV export writes an "On hand" column, and a guess that reached synthetics by label would turn
 * re-importing your own export into an opening balance for the entire catalogue.
 *
 * CUSTOM FIELDS ARRIVE THROUGH A FILTER RATHER THAN BY NAMING THE MODULE THAT OWNS THEM. Nothing
 * else in this plugin reaches into the custom-fields module, and this does not become the first.
 * The filter also gets the behaviour right for free: a module contributes from `registerHooks()`,
 * which a DISABLED module never runs, so switching custom fields off stops them being offered here
 * without this class knowing that is what happened.
 */
final class ImportTargets {

	/** How much of this item exists on day one. */
	public const OPENING_QUANTITY = '_opening_quantity';

	/** Where that stock lands. */
	public const OPENING_WAREHOUSE = '_opening_warehouse';

	/** What it cost per unit. */
	public const OPENING_COST = '_opening_cost';

	/** The prefix marking a custom-field target. */
	public const CUSTOM_PREFIX = 'custom_';

	/** Field types no spreadsheet can meaningfully carry. */
	private const UNIMPORTABLE_TYPES = array( 'media', 'datetime' );

	public function __construct(
		private readonly EntityRegistry $entities,
		private readonly RecordWriter $writer
	) {}

	/**
	 * Every importable entity, with everything a column may be mapped to.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$targets = array();

		foreach ( $this->writer->writable() as $slug => $label ) {
			$slug = (string) $slug;

			$targets[] = array(
				'entity_type' => $slug,
				'label'       => (string) $label,
				'fields'      => $this->fields_for( $slug ),
				'custom'      => array(),
				/*
				 * THE HEADING THE `custom` GROUP IS DRAWN UNDER, EMPTY UNTIL SOMETHING FILLS IT.
				 *
				 * The mapping screen groups its options and needs a word for each heading. Two of
				 * the three are this module's own; the third belongs to whichever module
				 * contributes `custom` targets, and spelling it out on the client put that
				 * module's vocabulary in a bundle that may not contain the module.
				 *
				 * The client draws no heading for an empty label, which is exactly right: with no
				 * custom targets there are no options under it either.
				 */
				'custom_label' => '',
				'synthetic'   => $this->synthetic_for( $slug ),
				'natural_key' => NaturalKey::columns_for( $slug ),
			);
		}

		/**
		 * Add mapping targets to an import.
		 *
		 * Each entry is one entity, with `fields`, `custom` and `synthetic` maps of target name =>
		 * `array{ label, type, required?, options?, entity? }`. A module that owns a kind of value
		 * an entity can hold contributes it here rather than this class naming that module.
		 *
		 * A target added to `custom` is passed to the importer as a RAW cell — the module that
		 * contributed it owns what its own types mean.
		 *
		 * @param array<int, array<string, mixed>> $targets One entry per importable entity.
		 */
		return (array) apply_filters( 'plugixa_inventory_import_targets', $targets );
	}

	/**
	 * One entity's targets, or null when it cannot be imported at all.
	 *
	 * @param string $entity_type Entity slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function for_entity( string $entity_type ): ?array {
		foreach ( $this->all() as $target ) {
			if ( $entity_type === ( $target['entity_type'] ?? '' ) ) {
				return $target;
			}
		}

		return null;
	}

	/**
	 * The descriptor fields a spreadsheet may actually write.
	 *
	 * @param string $entity_type Entity slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields_for( string $entity_type ): array {
		$descriptor = $this->entities->get( $entity_type );

		if ( null === $descriptor ) {
			return array();
		}

		$fields = array();

		foreach ( (array) ( $descriptor['fields'] ?? array() ) as $name => $definition ) {
			$name = (string) $name;

			if ( ! $this->importable( $name, (array) $definition ) ) {
				continue;
			}

			$entry = array(
				'label' => (string) ( $definition['label'] ?? $name ),
				'type'  => (string) ( $definition['type'] ?? 'text' ),
			);

			if ( ! empty( $definition['required'] ) ) {
				$entry['required'] = true;
			}

			if ( isset( $definition['options'] ) ) {
				$entry['options'] = array_values( array_map( 'strval', (array) $definition['options'] ) );
			}

			if ( isset( $definition['entity'] ) ) {
				$entry['entity'] = (string) $definition['entity'];
			}

			$fields[ $name ] = $entry;
		}

		return $fields;
	}

	/**
	 * Whether one descriptor field may be written from a cell.
	 *
	 * @param string               $name       Field name.
	 * @param array<string, mixed> $definition The descriptor's entry.
	 */
	private function importable( string $name, array $definition ): bool {
		if ( ! empty( $definition['readonly'] ) ) {
			return false;
		}

		if ( in_array( (string) ( $definition['type'] ?? 'text' ), self::UNIMPORTABLE_TYPES, true ) ) {
			return false;
		}

		return ! str_starts_with( $name, '_' ) && ! str_starts_with( $name, self::CUSTOM_PREFIX );
	}

	/**
	 * The targets that post stock rather than filling a column. Items only.
	 *
	 * `_opening_cost` IS NAMED FOR WHAT IT MEANS TO THE USER, and translated to the document's own
	 * key in exactly one place. The document line key is `cost_per_unit` while the item's own
	 * columns are `unit_price` and `cost_price` — three plausible spellings of the same idea, and
	 * writing the wrong one into a line array does not fail. It returns success and stores zero, so
	 * every cost layer opens at nothing and the whole opening inventory values at zero. Keeping the
	 * user-facing name distinct from all three is what stops that being copied around.
	 *
	 * @param string $entity_type Entity slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function synthetic_for( string $entity_type ): array {
		if ( 'item' !== $entity_type ) {
			return array();
		}

		return array(
			self::OPENING_QUANTITY  => array(
				'label'  => __( 'Opening quantity', 'plugixa-inventory' ),
				'type'   => 'number',
				'effect' => 'stock',
			),
			self::OPENING_WAREHOUSE => array(
				'label'  => __( 'Warehouse the stock lands in', 'plugixa-inventory' ),
				'type'   => 'record',
				'entity' => 'warehouse',
				'effect' => 'stock',
			),
			self::OPENING_COST      => array(
				'label'  => __( 'Cost per unit', 'plugixa-inventory' ),
				'type'   => 'money',
				'effect' => 'stock',
			),
		);
	}
}
