<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Http;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refusing a save that would silently overwrite somebody else's.
 *
 * THE FAILURE THIS PREVENTS IS INVISIBLE, WHICH IS WHY IT IS WORTH PREVENTING. Two people open the
 * same contact. One changes the phone number and saves. The other, still looking at the old version,
 * changes the job title and saves — and their form posts every field it was holding, including the
 * stale phone number. The first person's change is gone, nothing errored, and neither of them will
 * find out until somebody rings the wrong number. EspoCRM calls this optimistic concurrency control
 * and it is the last item on the shape-parity list for a reason: it is cheap and it is the only thing
 * standing between a busy team and quiet data loss.
 *
 * IT LIVES IN THE PERMISSION CALLBACK, WHICH IS ODD-LOOKING AND CORRECT. `can_edit()` already loads
 * the record to ask the record-level permission question, so it is the one place in the plugin that
 * has both the request and the current row in hand for EVERY entity, without a single service or
 * repository being touched. A REST permission callback returning a `WP_Error` is WordPress's own way
 * of refusing a request before it runs, and "somebody else changed this" is a refusal.
 *
 * ITS RESOLUTION IS ONE SECOND, WHICH IS A REAL LIMIT AND IS STATED RATHER THAN HIDDEN. `modified_at`
 * is a plain `datetime` column with no fractional part, so two saves that land inside the same second
 * are indistinguishable and the second one is allowed through. That is the collision this cannot
 * catch. It is accepted because the case it exists for is two people with the same record open for
 * minutes, not two requests racing inside a second — and because the alternative is a `datetime(3)`
 * migration across every table in the plugin, or a second version column to maintain, to close a
 * window that a human cannot hit on purpose.
 *
 * IT IS OPT-IN PER REQUEST, AND THAT IS A DELIBERATE LIMIT. A payload with no `modified_at` is not
 * checked. Enforcing it would break every existing API consumer, the CSV import, the merge and the
 * mass-action layer — none of which have read a version to send — and would turn a data-integrity
 * feature into an outage. The client sends it, so the screens people actually use are covered; a
 * script that wants the protection opts in by sending what it read.
 */
final class Concurrency {

	/**
	 * The field a client echoes back to say which version it was looking at.
	 *
	 * `modified_at` rather than a separate version number: every table already has it, it already
	 * changes on every write, and it is already in every payload the client received. A version
	 * column would be a second thing to migrate, maintain and forget to increment.
	 */
	public const FIELD = 'modified_at';

	/**
	 * Check a request against the record it means to change.
	 *
	 * @param WP_REST_Request           $request The request.
	 * @param array<string, mixed>|null $record  The record as it stands now.
	 *
	 * @return true|WP_Error True to proceed; a 409 to refuse.
	 */
	public static function check( WP_REST_Request $request, ?array $record ): true|WP_Error {
		// Nothing to compare against: a create, a route with no single record, or an entity whose
		// controller does not implement record_for(). None of those can conflict.
		if ( ! is_array( $record ) || ! array_key_exists( self::FIELD, $record ) ) {
			return true;
		}

		$body = (array) $request->get_json_params();

		$expected = trim( (string) ( $body[ self::FIELD ] ?? '' ) );

		if ( '' === $expected ) {
			return true;
		}

		$current = trim( (string) ( $record[ self::FIELD ] ?? '' ) );

		/*
		 * COMPARED AS TIMESTAMPS, NOT AS STRINGS.
		 *
		 * The value makes a round trip through JSON and back, and a client that reformats it — or a
		 * site whose MySQL returns `2026-07-31T09:14:22` where PHP wrote `2026-07-31 09:14:22` —
		 * would produce a mismatch on two spellings of the same instant. That is a conflict prompt
		 * for a conflict that does not exist, which teaches people to ignore the prompt.
		 */
		$expected_at = strtotime( $expected );
		$current_at  = strtotime( $current );

		if ( false === $expected_at || false === $current_at || $expected_at === $current_at ) {
			return true;
		}

		return new WP_Error(
			'plugixa_inventory_conflict',
			__( 'Somebody else changed this record while you were editing it. Reload to see their version before saving yours.', 'plugixa-inventory' ),
			array(
				'status'   => 409,
				'expected' => $expected,
				'current'  => $current,
			)
		);
	}
}
