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
 * Creating and updating a record whose type is only known at runtime.
 *
 * THE WRITE TWIN OF {@see RecordLoader}, and the seam CSV import needs. Import is handed an entity
 * slug from a dropdown and a row from a file; it has to produce a record without knowing what kind of
 * record that is. Nothing in the plugin could do that before this, because every module keeps its own
 * service and nothing indexed them.
 *
 * IT GOES THROUGH SERVICES, NOT REPOSITORIES, AND THAT IS THE ENTIRE POINT. A repository insert is a
 * row; a service call is the business logic — validation, sanitisation, derived columns like a
 * contact's `full_name` or an article's `body_plain`, the `closed_at` stamp on a case, and every
 * lifecycle event the stream and the notification bell listen to. An importer that wrote rows
 * directly would produce records that look right in a list and are missing everything a record
 * created through the UI has. `Repository` and `RestController` both carry docblocks promising that
 * business logic lives in services precisely so that import and REST reach the same code; this is
 * what makes that promise keepable.
 *
 * A MODULE OPTS IN. Registering an entity in `plugixa_inventory_entity_types` says "this thing exists";
 * registering a writer says "and it can be written to generically". The two are separate because some
 * entities should not be: an `email` is a message that was sent or received, and manufacturing one
 * from a spreadsheet row would be inventing history that never happened.
 */
final class RecordWriter {

	/** @var array<string, array{create?: callable, update?: callable}>|null */
	private ?array $writers = null;

	public function __construct( private readonly EntityRegistry $entities ) {}

	/**
	 * Every entity that can be written generically, as slug => label.
	 *
	 * What the import screen's entity picker is built from — an entity with no writer simply is not
	 * offered, rather than being offered and then failing on the first row.
	 *
	 * @return array<string, string>
	 */
	public function writable(): array {
		$out = array();

		foreach ( array_keys( $this->all() ) as $slug ) {
			$descriptor = $this->entities->get( $slug );

			if ( null !== $descriptor ) {
				$out[ $slug ] = (string) ( $descriptor['plural'] ?? $descriptor['label'] ?? $slug );
			}
		}

		asort( $out );

		return $out;
	}

	/**
	 * Whether this entity can be written generically.
	 *
	 * @param string $entity_type Entity slug.
	 */
	public function knows( string $entity_type ): bool {
		return isset( $this->all()[ $entity_type ]['create'] );
	}

	/**
	 * Create one record.
	 *
	 * THE PERMISSION CHECK IS HERE, not only in the caller. This is a generic write path reachable
	 * from an import, and a check that lives in one caller is a check the second caller forgets.
	 *
	 * @param string               $entity_type Entity slug.
	 * @param array<string, mixed> $data        Field values.
	 *
	 * @return int|WP_Error The new id.
	 */
	public function create( string $entity_type, array $data ): int|WP_Error {
		$writer = $this->all()[ $entity_type ]['create'] ?? null;

		if ( null === $writer ) {
			return $this->cannot( $entity_type );
		}

		if ( ! Capabilities::user_can( 'create', $entity_type ) ) {
			return $this->forbidden();
		}

		$result = $writer( $data );

		return is_int( $result ) || $result instanceof WP_Error
			? $result
			: new WP_Error( 'plugixa_inventory_write_failed', __( 'The record could not be created.', 'plugixa-inventory' ) );
	}

	/**
	 * Update one record.
	 *
	 * @param string               $entity_type Entity slug.
	 * @param int                  $id          Record id.
	 * @param array<string, mixed> $data        Field values.
	 *
	 * @return true|WP_Error
	 */
	public function update( string $entity_type, int $id, array $data ): true|WP_Error {
		$writer = $this->all()[ $entity_type ]['update'] ?? null;

		if ( null === $writer ) {
			return $this->cannot( $entity_type );
		}

		if ( ! Capabilities::user_can( 'edit', $entity_type ) ) {
			return $this->forbidden();
		}

		$result = $writer( $id, $data );

		return true === $result || $result instanceof WP_Error
			? $result
			: new WP_Error( 'plugixa_inventory_write_failed', __( 'The record could not be updated.', 'plugixa-inventory' ) );
	}

	/**
	 * Trash one record.
	 *
	 * TRASH, NOT PURGE, and the registered callable is the repository's `delete()` for exactly that
	 * reason. Its only caller is reverting an import, and an import of four thousand contacts reverted
	 * by mistake has to be recoverable — which it is from the trash and is not from a purge.
	 *
	 * @param string $entity_type Entity slug.
	 * @param int    $id          Record id.
	 */
	public function delete( string $entity_type, int $id ): bool {
		$writer = $this->all()[ $entity_type ]['delete'] ?? null;

		if ( null === $writer || ! Capabilities::user_can( 'delete', $entity_type ) ) {
			return false;
		}

		$result = $writer( $id );

		/*
		 * A REFUSAL IS NOT A SUCCESS, AND `(bool) $wp_error` IS `true`.
		 *
		 * Four of the five registered `delete` callables are service methods returning
		 * `bool|WP_Error`, and a WP_Error is an object — so the obvious `return (bool) $writer( $id );`
		 * reported every refused delete as done. That is not a theoretical bug: `ItemService::delete()`
		 * refuses any item holding stock or carrying ledger history, so a mass delete of two hundred
		 * such items answered `done: 200, failed: 0` and removed nothing.
		 *
		 * {@see MassAction::run()} already tests `$result instanceof WP_Error || false === $result`,
		 * and neither branch could ever be reached from here. Both callers depend on this returning
		 * what actually happened: the importer's revert falls back to unpublishing precisely when a
		 * delete was refused, and a false success there would leave the records in place while
		 * telling the user they had been taken back.
		 */
		return ! $result instanceof WP_Error && (bool) $result;
	}

	/**
	 * The registered writers.
	 *
	 * @return array<string, array{create?: callable, update?: callable}>
	 */
	private function all(): array {
		if ( null !== $this->writers ) {
			return $this->writers;
		}

		/**
		 * Register generic write access to an entity.
		 *
		 * Each entry is keyed by entity slug and holds `create` and `update` callables — in practice
		 * the module's own service methods, which is what keeps import and the REST API on one code
		 * path.
		 *
		 * @param array<string, array{create?: callable, update?: callable}> $writers Slug => callables.
		 */
		$writers = (array) apply_filters( 'plugixa_inventory_record_writers', array() );

		// An entity that is not registered cannot be written, whatever a filter says: without a
		// descriptor there is no field list to validate against and no ACL scope to check.
		foreach ( array_keys( $writers ) as $slug ) {
			if ( ! $this->entities->has( (string) $slug ) ) {
				unset( $writers[ $slug ] );
			}
		}

		$this->writers = $writers;

		return $this->writers;
	}

	/**
	 * @param string $entity_type Entity slug.
	 */
	private function cannot( string $entity_type ): WP_Error {
		return new WP_Error(
			'plugixa_inventory_not_writable',
			sprintf(
				/* translators: %s: an entity slug, e.g. "contact". */
				__( 'Records of type "%s" cannot be imported.', 'plugixa-inventory' ),
				$entity_type
			),
			array( 'status' => 422 )
		);
	}

	private function forbidden(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_forbidden',
			__( 'You do not have permission to save that kind of record.', 'plugixa-inventory' ),
			array( 'status' => 403 )
		);
	}
}
