<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Warehouses\Services;

use PlugixaInventory\Core\Entities\RawInput;
use PlugixaInventory\Modules\Warehouses\Repositories\WarehouseRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creating, editing and retiring warehouses.
 */
final class WarehouseService {

	/** @var string[] */
	public const STATUSES = array( 'published', 'unpublished' );

	private const CODE_PATTERN = '/^[A-Z0-9_-]+$/';

	public function __construct( private readonly WarehouseRepository $warehouses ) {}

	/**
	 * @param int $id Warehouse id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->warehouses->find( $id );

		return null === $row ? null : $this->warehouses->cast( $row );
	}

	/**
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return int|WP_Error
	 */
	public function create( array $input ): int|WP_Error {
		// Before sanitize(), which would publish an unknown status rather than refuse it. See RawInput.
		$refused = RawInput::choice( $input, 'status', self::STATUSES, __( 'Status', 'plugixa-inventory' ) );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, true );

		$invalid = $this->validate( $data, 0 );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$id = $this->warehouses->insert( $data );

		return $id > 0
			? $id
			: new WP_Error( 'plugixa_inventory_insert_failed', __( 'The warehouse could not be saved.', 'plugixa-inventory' ), array( 'status' => 500 ) );
	}

	/**
	 * @param int                  $id    Warehouse id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		if ( null === $this->warehouses->find( $id ) ) {
			return $this->not_found();
		}

		$refused = RawInput::choice( $input, 'status', self::STATUSES, __( 'Status', 'plugixa-inventory' ) );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, false );

		$invalid = $this->validate( $data, $id );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		return $this->warehouses->update( $id, $data );
	}

	/**
	 * Trash a warehouse, unless something is still using it.
	 *
	 * THE BLOCKERS FILTER IS THE POINT OF THIS METHOD. A warehouse is the second half of the key on
	 * `item_stock`, so trashing one that still holds stock produces quantities nobody can reach:
	 * the totals still count them, no screen lists them, and the discrepancy surfaces weeks later
	 * as an unexplained valuation.
	 *
	 * This module cannot check for that itself — it does not know the Stock module exists, and
	 * must not. So it asks, and Stock answers. Anything else that comes to hold a warehouse
	 * reference later (a draft transfer, a scheduled count) answers the same filter without this
	 * file changing.
	 *
	 * Each answer is a SENTENCE, not a boolean, because "you cannot delete this" without saying
	 * why leaves the user with no move.
	 *
	 * @param int $id Warehouse id.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$warehouse = $this->warehouses->find( $id );

		if ( null === $warehouse ) {
			return $this->not_found();
		}

		/**
		 * Reasons this warehouse must not be trashed.
		 *
		 * @param string[]             $blockers  Human-readable sentences. Empty means "go ahead".
		 * @param int                  $id        Warehouse id.
		 * @param array<string,mixed>  $warehouse The row.
		 */
		$blockers = (array) apply_filters( 'plugixa_inventory_warehouse_delete_blockers', array(), $id, $warehouse );

		if ( array() !== $blockers ) {
			return new WP_Error(
				'plugixa_inventory_warehouse_in_use',
				implode( ' ', array_map( 'strval', $blockers ) ),
				array( 'status' => 409, 'blockers' => array_values( $blockers ) )
			);
		}

		return $this->warehouses->delete( $id );
	}

	/**
	 * Bring a trashed warehouse back, refusing if its code has since been taken.
	 *
	 * @param int $id Warehouse id.
	 *
	 * @return bool|WP_Error
	 */
	public function restore( int $id ): bool|WP_Error {
		$row = $this->warehouses->find( $id, true );

		if ( null === $row ) {
			return $this->not_found();
		}

		$code = (string) ( $row['code'] ?? '' );

		if ( '' !== $code && null !== $this->warehouses->find_by_code( $code, $id ) ) {
			return new WP_Error(
				'plugixa_inventory_duplicate_code',
				sprintf(
					/* translators: %s: the warehouse code. */
					__( 'This warehouse cannot be restored: another warehouse is now using the code "%s". Rename that one first.', 'plugixa-inventory' ),
					$code
				),
				array( 'status' => 409, 'id' => $id, 'code' => $code )
			);
		}

		return $this->warehouses->restore( $id );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_not_found',
			__( 'That warehouse does not exist.', 'plugixa-inventory' ),
			array( 'status' => 404 )
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

		if ( $with_defaults || array_key_exists( 'name', $input ) ) {
			$data['name'] = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'code', $input ) ) {
			$data['code'] = strtoupper( sanitize_text_field( (string) ( $input['code'] ?? '' ) ) );
		}

		if ( $with_defaults || array_key_exists( 'address', $input ) ) {
			// wp_kses_post rather than sanitize_textarea_field: the Joomla field is rich text and
			// an address is routinely pasted with line breaks and the odd link to a map.
			$data['address'] = wp_kses_post( (string) ( $input['address'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'status', $input ) ) {
			$status         = sanitize_key( (string) ( $input['status'] ?? 'published' ) );
			$data['status'] = in_array( $status, self::STATUSES, true ) ? $status : 'published';
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data Sanitized payload.
	 * @param int                  $id   Row being updated, or 0 when creating.
	 */
	private function validate( array $data, int $id ): ?WP_Error {
		if ( array_key_exists( 'name', $data ) && '' === trim( (string) $data['name'] ) ) {
			return new WP_Error(
				'plugixa_inventory_missing_name',
				__( 'A warehouse needs a name.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$too_long = $this->warehouses->too_long( $data );
		if ( $too_long instanceof WP_Error ) {
			return $too_long;
		}

		$code = (string) ( $data['code'] ?? '' );

		if ( '' !== $code && 1 !== preg_match( self::CODE_PATTERN, $code ) ) {
			return new WP_Error(
				'plugixa_inventory_invalid_code',
				__( 'A code may only contain letters, digits, dashes and underscores.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		if ( '' !== $code && null !== $this->warehouses->find_by_code( $code, $id ) ) {
			return new WP_Error(
				'plugixa_inventory_duplicate_code',
				sprintf(
					/* translators: %s: the warehouse code. */
					__( 'A warehouse with the code "%s" already exists.', 'plugixa-inventory' ),
					$code
				),
				array( 'status' => 409 )
			);
		}

		return null;
	}
}
