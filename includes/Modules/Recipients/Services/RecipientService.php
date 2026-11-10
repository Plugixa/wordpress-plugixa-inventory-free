<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Recipients\Services;

use PlugixaInventory\Core\Entities\RawInput;
use PlugixaInventory\Modules\Recipients\Repositories\RecipientRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creating and editing the people assets get checked out to.
 */
final class RecipientService {

	/** @var string[] */
	public const STATUSES = array( 'published', 'unpublished' );

	public function __construct( private readonly RecipientRepository $recipients ) {}

	/**
	 * @param int $id Recipient id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->recipients->find( $id );

		return null === $row ? null : $this->recipients->cast( $row );
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

		$invalid = $this->validate( $data, $input );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$id = $this->recipients->insert( $data );

		return $id > 0
			? $id
			: new WP_Error( 'plugixa_inventory_insert_failed', __( 'The recipient could not be saved.', 'plugixa-inventory' ), array( 'status' => 500 ) );
	}

	/**
	 * @param int                  $id    Recipient id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		if ( null === $this->recipients->find( $id ) ) {
			return $this->not_found();
		}

		$refused = RawInput::choice( $input, 'status', self::STATUSES, __( 'Status', 'plugixa-inventory' ) );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, false );

		$invalid = $this->validate( $data, $input );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		return $this->recipients->update( $id, $data );
	}

	/**
	 * Trash a recipient, unless something still points at them.
	 *
	 * A recipient with assets checked out to them is the thing this guard exists for: trashing the
	 * row does not return the laptop, it just removes the only record of who has it. The Stock
	 * module answers the filter once it exists.
	 *
	 * @param int $id Recipient id.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$recipient = $this->recipients->find( $id );

		if ( null === $recipient ) {
			return $this->not_found();
		}

		/**
		 * Reasons this recipient must not be trashed.
		 *
		 * @param string[]            $blockers  Human-readable sentences. Empty means "go ahead".
		 * @param int                 $id        Recipient id.
		 * @param array<string,mixed> $recipient The row.
		 */
		$blockers = (array) apply_filters( 'plugixa_inventory_recipient_delete_blockers', array(), $id, $recipient );

		if ( array() !== $blockers ) {
			return new WP_Error(
				'plugixa_inventory_recipient_in_use',
				implode( ' ', array_map( 'strval', $blockers ) ),
				array( 'status' => 409, 'blockers' => array_values( $blockers ) )
			);
		}

		return $this->recipients->delete( $id );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_not_found',
			__( 'That recipient does not exist.', 'plugixa-inventory' ),
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

		if ( $with_defaults || array_key_exists( 'email', $input ) ) {
			$data['email'] = sanitize_email( (string) ( $input['email'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'phone', $input ) ) {
			$data['phone'] = sanitize_text_field( (string) ( $input['phone'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'department', $input ) ) {
			$data['department'] = sanitize_text_field( (string) ( $input['department'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'notes', $input ) ) {
			$data['notes'] = wp_kses_post( (string) ( $input['notes'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'user_id', $input ) ) {
			$user            = (int) ( $input['user_id'] ?? 0 );
			$data['user_id'] = $user > 0 ? $user : null;
		}

		if ( $with_defaults || array_key_exists( 'status', $input ) ) {
			$status         = sanitize_key( (string) ( $input['status'] ?? 'published' ) );
			$data['status'] = in_array( $status, self::STATUSES, true ) ? $status : 'published';
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data  Sanitized payload.
	 * @param array<string, mixed> $input The RAW payload. See the email check below.
	 */
	private function validate( array $data, array $input ): ?WP_Error {
		if ( array_key_exists( 'name', $data ) && '' === trim( (string) $data['name'] ) ) {
			return new WP_Error(
				'plugixa_inventory_missing_name',
				__( 'A recipient needs a name.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$too_long = $this->recipients->too_long( $data );
		if ( $too_long instanceof WP_Error ) {
			return $too_long;
		}

		/*
		 * AN EMPTY EMAIL IS FINE; A MALFORMED ONE IS NOT -- AND THIS HAS TO READ THE RAW INPUT.
		 *
		 * sanitize_email() returns an empty string for anything it cannot rescue, so by the time
		 * the sanitized payload gets here "ada@@example" and "" are the same value. Checking the
		 * clean one would therefore accept a typo and store it as blank, and the first anybody
		 * would know is a low-stock digest that silently never reached that person.
		 *
		 * So the test is on what the user actually typed: they gave something, and it did not
		 * survive.
		 */
		if ( array_key_exists( 'email', $input ) ) {
			$given = trim( (string) $input['email'] );

			if ( '' !== $given && '' === (string) ( $data['email'] ?? '' ) ) {
				return new WP_Error(
					'plugixa_inventory_invalid_email',
					__( 'That email address does not look right.', 'plugixa-inventory' ),
					array( 'status' => 422 )
				);
			}
		}

		if ( isset( $data['user_id'] ) && null === get_userdata( (int) $data['user_id'] ) ) {
			return new WP_Error(
				'plugixa_inventory_unknown_user',
				__( 'That WordPress user does not exist.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		return null;
	}
}
