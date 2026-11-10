<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Categories\Services;

use PlugixaInventory\Core\Entities\RawInput;
use PlugixaInventory\Modules\Categories\Repositories\CategoryRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creating, renaming and retiring categories.
 *
 * Every rule about what a category IS lives here rather than in the controller, because REST is
 * not the only caller: the CSV importer and the demo installer reach the same methods, and a rule
 * enforced at the route is a rule those two do not get.
 */
final class CategoryService {

	/** @var string[] */
	public const STATUSES = array( 'published', 'unpublished' );

	/**
	 * A code is an identifier a human types into a barcode-adjacent field, so it is deliberately
	 * narrow: letters, digits, dash and underscore. Spaces and punctuation are the difference
	 * between a code somebody can read back over a phone and one they cannot.
	 */
	private const CODE_PATTERN = '/^[A-Z0-9_-]+$/';

	public function __construct( private readonly CategoryRepository $categories ) {}

	/**
	 * @param int $id Category id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->categories->find( $id );

		return null === $row ? null : $this->categories->cast( $row );
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

		$id = $this->categories->insert( $data );

		if ( 0 === $id ) {
			return new WP_Error(
				'plugixa_inventory_insert_failed',
				__( 'The category could not be saved.', 'plugixa-inventory' ),
				array( 'status' => 500 )
			);
		}

		return $id;
	}

	/**
	 * @param int                  $id    Category id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		if ( null === $this->categories->find( $id ) ) {
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

		return $this->categories->update( $id, $data );
	}

	/**
	 * Bring a trashed category back.
	 *
	 * THIS IS NOT A PASS-THROUGH TO THE REPOSITORY, and the reason is the hole that
	 * uniqueness-among-live-rows leaves open. `code` has no unique index, deliberately — a trashed
	 * category must release its code, or "ELEC" could be deleted and never used again. But that
	 * means the code can be taken by somebody else while the row sits in the trash, and a plain
	 * `restore()` would then produce two LIVE categories with the same code, silently, past a
	 * uniqueness rule that create() and update() both enforce.
	 *
	 * It is the exact mirror of the bug the missing index was avoiding, and it is worse: a
	 * duplicate that arrived through the trash looks like data, not like a mistake.
	 *
	 * @param int $id Category id.
	 *
	 * @return bool|WP_Error
	 */
	public function restore( int $id ): bool|WP_Error {
		$row = $this->categories->find( $id, true );

		if ( null === $row ) {
			return $this->not_found();
		}

		$code = (string) ( $row['code'] ?? '' );

		// An uncoded category can never collide, so it restores unconditionally.
		if ( '' === $code || null === $this->categories->find_by_code( $code, $id ) ) {
			return $this->categories->restore( $id );
		}

		return $this->restore_collision( $id, $code );
	}

	/**
	 * A trashed category's code has been claimed by a live one. Refuse, and say by whom.
	 *
	 * THE DECISION IS TO REFUSE RATHER THAN TO FIX IT SILENTLY, and the alternatives are worth
	 * recording because each looks reasonable until you follow it through. Clearing the code on
	 * the way back in loses information the user entered, without telling them. Auto-suffixing to
	 * ELEC-2 invents a code nobody chose, which then gets printed on reports and read out over the
	 * phone — and it has to loop, because ELEC-2 can be taken too. Allowing the duplicate makes
	 * `find_by_code()` non-deterministic, which in P6 means the barcode scanner resolves to
	 * whichever row MySQL happened to order first.
	 *
	 * Refusing is the only one of the four where nothing is lost and nothing is decided behind the
	 * user's back. The cost is that the restore fails and they have to rename one first, which is
	 * a cost paid by the person who is already looking at the problem.
	 *
	 * The same rule applies to unit codes and to item SKUs and barcodes.
	 *
	 * @param int    $id   Category id.
	 * @param string $code The code it is trying to reclaim.
	 */
	private function restore_collision( int $id, string $code ): WP_Error {
		return new WP_Error(
			'plugixa_inventory_duplicate_code',
			sprintf(
				/* translators: %s: the category code. */
				__( 'This category cannot be restored: another category is now using the code "%s". Rename that one first.', 'plugixa-inventory' ),
				$code
			),
			array(
				'status' => 409,
				'id'     => $id,
				'code'   => $code,
			)
		);
	}

	/**
	 * The not-found error, in one place because three methods return it.
	 */
	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_not_found',
			__( 'That category does not exist.', 'plugixa-inventory' ),
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
			// Upper-cased rather than rejected for case: "elec" and "ELEC" are the same code to
			// everyone who reads them, and storing both would make the uniqueness check a lie.
			$data['code'] = strtoupper( sanitize_text_field( (string) ( $input['code'] ?? '' ) ) );
		}

		if ( $with_defaults || array_key_exists( 'description', $input ) ) {
			// wp_kses_post, not sanitize_text_field: the Joomla field is a rich-text area and its
			// data will arrive with markup in it.
			$data['description'] = wp_kses_post( (string) ( $input['description'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'status', $input ) ) {
			$status           = sanitize_key( (string) ( $input['status'] ?? 'published' ) );
			$data['status']   = in_array( $status, self::STATUSES, true ) ? $status : 'published';
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
				__( 'A category needs a name.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$too_long = $this->categories->too_long( $data );
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

		// Uniqueness among LIVE rows only, and only for a code that was actually given — an empty
		// code is "no code", not a value two categories can collide on. See the repository.
		if ( '' !== $code && null !== $this->categories->find_by_code( $code, $id ) ) {
			return new WP_Error(
				'plugixa_inventory_duplicate_code',
				sprintf(
					/* translators: %s: the category code. */
					__( 'A category with the code "%s" already exists.', 'plugixa-inventory' ),
					$code
				),
				array( 'status' => 409 )
			);
		}

		return null;
	}
}
