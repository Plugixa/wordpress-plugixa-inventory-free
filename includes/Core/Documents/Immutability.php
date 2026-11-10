<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Documents;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a finalized document will and will not accept.
 *
 * A document that has been finalized has already moved stock. Its lines are what the ledger rows
 * were computed from, its date is what those rows are dated, and its warehouse is where the
 * quantity actually went. Change any of those afterwards and the document stops describing what
 * happened — the ledger still says one thing and the paperwork says another, with nothing to
 * reconcile them.
 *
 * SO THE RULE IS: THE ACCOUNTING IS FROZEN, THE ANNOTATION IS NOT. `details`, `attachments`,
 * `reference` and `assigned_user_id` are things a person writes ABOUT a movement; everything else
 * is the movement. Correcting a finalized document means creating a reversing one, which is what
 * every stock system does and what the audit trail is for.
 *
 * WHY THIS IS A CLASS AND NOT THREE `if` STATEMENTS. Three document services would each have to
 * remember the same list, and a fourth document type would inherit nothing. The list is short
 * enough that copying it looks harmless, which is exactly the property that gets it copied wrong —
 * and a service that forgot one field would silently accept an edit that corrupts an audit trail.
 */
final class Immutability {

	/**
	 * The one status at which a document is still editable.
	 *
	 * Named rather than inlined because three services and the delete veto all ask the same
	 * question, and "draft" appearing as a bare string in four files is four chances to typo it into
	 * a permanently uneditable document.
	 */
	public const EDITABLE_STATUS = 'draft';

	/**
	 * Fields a finalized document still accepts.
	 *
	 * `reference` IS THE ARGUABLE ONE and it is deliberately in. A reference is the human-facing
	 * document number, and a business that renumbers after the fact usually has a reason — a
	 * migration, a clash with a paper system, a typo somebody has been reading for a week. It
	 * changes nothing the ledger computed. If an audit practice needs it frozen, remove it from this
	 * array and every document type follows.
	 *
	 * `reason` IS AN ADJUSTMENT'S ANNOTATION — "why did we write these off", a question people answer
	 * later — and its form always said it stayed editable. It was not on this list, so no save of it
	 * after finalizing ever succeeded. `FinalizedDocumentEditTest`.
	 *
	 * THE FORMS KEEP A COPY OF THIS LIST (`ANNOTATION_FIELDS` in `shared/documents/types.ts`) and send
	 * only these fields for a finalized document, because a change set carrying any other key is refused
	 * whole. The same test fails if the two drift.
	 *
	 * @var string[]
	 */
	public const SOFT = array( 'details', 'attachments', 'reference', 'assigned_user_id', 'reason' );

	/**
	 * Is this document still open for editing?
	 *
	 * @param array<string, mixed> $document The row.
	 */
	public static function editable( array $document ): bool {
		return self::EDITABLE_STATUS === ( $document['status'] ?? '' );
	}

	/**
	 * May this change set be applied?
	 *
	 * A CHANGE SET CONTAINING A FROZEN FIELD IS REFUSED WHOLE, rather than having the frozen fields
	 * quietly dropped. Silently applying half a save is the worst of the options: the user sees a
	 * success, goes back to the list, and has no way to know which of their edits took. The 409 says
	 * what happened.
	 *
	 * @param string               $entity   Entity slug, for the error's data.
	 * @param array<string, mixed> $document The stored row.
	 * @param array<string, mixed> $changes  What the caller wants to write.
	 */
	public static function guard_edit( string $entity, array $document, array $changes ): true|WP_Error {
		if ( self::editable( $document ) ) {
			return true;
		}

		$frozen = array_values( array_diff( array_keys( $changes ), self::SOFT ) );

		if ( array() === $frozen ) {
			return true;
		}

		return new WP_Error(
			'plugixa_inventory_document_finalized',
			__( 'A finalized document cannot be changed. Create a reversing document instead.', 'plugixa-inventory' ),
			array(
				'status' => 409,
				'entity' => $entity,
				'fields' => $frozen,
			)
		);
	}

	/**
	 * May this document be deleted?
	 *
	 * @param string               $entity   Entity slug, for the error's data.
	 * @param array<string, mixed> $document The stored row.
	 */
	public static function guard_delete( string $entity, array $document ): true|WP_Error {
		if ( self::editable( $document ) ) {
			return true;
		}

		return new WP_Error(
			'plugixa_inventory_document_finalized',
			__( 'A finalized document cannot be deleted. It is part of the stock audit trail.', 'plugixa-inventory' ),
			array(
				'status' => 409,
				'entity' => $entity,
			)
		);
	}

	/**
	 * May lines be added, changed or removed?
	 *
	 * Separate from `guard_edit()` because a line is not a field: no line change is ever soft, so
	 * there is nothing to compare against `SOFT` and the message can say something more useful than
	 * a list of frozen columns.
	 *
	 * @param string               $entity   Entity slug, for the error's data.
	 * @param array<string, mixed> $document The stored row.
	 */
	public static function guard_lines( string $entity, array $document ): true|WP_Error {
		if ( self::editable( $document ) ) {
			return true;
		}

		return new WP_Error(
			'plugixa_inventory_document_finalized',
			__( 'The lines of a finalized document cannot be changed. They are what the stock ledger was written from.', 'plugixa-inventory' ),
			array(
				'status' => 409,
				'entity' => $entity,
			)
		);
	}
}
