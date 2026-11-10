<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Entities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalogue of inventory entities and their fields.
 *
 * This is the highest-leverage seam in the plugin. Anything that needs to reason
 * about "what entities exist and what fields do they have" reads it: the generic
 * list/detail/form UI, global search, CSV import mapping, custom fields, and — in
 * the premium build — the Workflow condition builder and the Report field picker.
 *
 * Because the catalogue is a filter, a premium module registers a whole new entity
 * (a Case, a Document) with one call and immediately inherits list views, filters,
 * detail pages, custom fields, tags, timeline and CSV export, without writing any of
 * that UI. That is what keeps the premium modules small.
 */
final class EntityRegistry {

	/**
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $entities = null;

	/**
	 * Every registered entity, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== $this->entities ) {
			return $this->entities;
		}

		/**
		 * Register inventory entities.
		 *
		 * Each entry is keyed by slug and describes the entity to the generic UI and
		 * to any consumer that reasons about entities generically.
		 *
		 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
		 */
		$entities = (array) apply_filters( 'plugixa_inventory_entity_types', array() );

		foreach ( $entities as $slug => $descriptor ) {
			/**
			 * Filter the field definitions of a single inventory entity.
			 *
			 * Lets a module add computed or rolled-up fields to an entity it does not
			 * own — e.g. premium Cases adding an "Open cases" field to Account.
			 *
			 * @param array<string, mixed> $fields Field definitions.
			 * @param string               $slug   Entity slug.
			 */
			$entities[ $slug ]['fields'] = (array) apply_filters(
				'plugixa_inventory_entity_fields',
				$descriptor['fields'] ?? array(),
				$slug
			);
		}

		$this->entities = $entities;

		return $this->entities;
	}

	/**
	 * One entity descriptor.
	 *
	 * @param string $slug Entity slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $slug ): ?array {
		return $this->all()[ $slug ] ?? null;
	}

	/**
	 * Whether an entity is registered.
	 *
	 * @param string $slug Entity slug.
	 */
	public function has( string $slug ): bool {
		return isset( $this->all()[ $slug ] );
	}
}
