<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Documents;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which statuses a document may move to from where.
 *
 * A draft/finalized lifecycle is a table rather than a pile of ifs, because the
 * interesting question about a state machine is always "can it get from here to there", and a table
 * answers that by being read. Nine more documents are about to want the same thing.
 *
 * WHAT THIS IS ACTUALLY FOR, because the shared logic on its own would not justify a class.
 *
 * The check itself is one `in_array()`. What was missing — in both modules, and in every module that
 * would have copied them — is that **nothing validated the table**. Write `'finalised'` where the table says `'finalized'` and that is a
 * destination and that transition becomes permanently impossible: no error, no failing test, no
 * exception. A button in the UI just quietly does nothing, and the status it was meant to reach
 * becomes unreachable for every record for ever. The typo is three characters and invisible in
 * review, because a status is a string and every string looks equally plausible.
 *
 * So the table is checked when it is built: every status named as a destination must also be a state
 * the machine knows. That turns an invisible dead end into a fatal error the first time the suite
 * runs — which is exactly when you want to hear about it.
 *
 * It also answers `next()`, which neither module could: "what can this record become from here" is
 * the question a detail page asks to decide which buttons to draw, and both modules were hard-coding
 * that answer in the client instead.
 *
 * WHAT IT DELIBERATELY DOES NOT OWN. Only legality. The refusal message, the guards (a document needs a line before it can be
 * finalized; a finalized document cannot be deleted), the timestamp stamping and the
 * `do_action` all stay in their modules. The wording is a registered, translated string belonging to
 * its module, the guards are that document's rules rather than any document's, and
 * each document type fires its own `plugixa_inventory_document_finalized` payload.
 */
final class StatusMachine {

	/**
	 * @param array<string, string[]> $transitions Status => the statuses reachable from it.
	 *
	 * @throws InvalidArgumentException If the table names a destination that is not a known status.
	 */
	public function __construct( private readonly array $transitions ) {
		$unknown = array();

		foreach ( $transitions as $from => $destinations ) {
			foreach ( $destinations as $to ) {
				if ( ! array_key_exists( $to, $transitions ) ) {
					$unknown[] = "{$from} -> {$to}";
				}
			}
		}

		if ( array() !== $unknown ) {
			// NOT escaped, deliberately. $unknown is built from the KEYS of a transition table
			// that is a hard-coded array literal in a module's service — never request data — and
			// esc_html() turns the "sent -> accepted" arrows into "sent -&gt; accepted", which
			// makes the one message whose entire job is to be readable by a developer worse.
			// The readable form is deliberate and currently unguarded: no test asserts it, so an
			// well-meaning esc_html() here would not fail anything.
			// A disable/enable PAIR, not phpcs:ignore: the sniff reports against the implode()
			// line inside the throw, which the single-line form cannot reach.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a hard-coded array literal from a module's service, never request data; esc_html() would turn the arrows this message exists to show into &gt;. See the note above.
			throw new InvalidArgumentException(
				'Transition table names statuses that do not exist: ' . implode( ', ', $unknown )
				. '. A destination that is not also a key is unreachable, and nothing at runtime would say so.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * May a record move from one status to another?
	 *
	 * An unknown `$from` answers false rather than throwing: a row can hold a status this machine
	 * has never heard of — a value written by an older version, or by hand — and the safe reading of
	 * "I do not know what this is" is to refuse to move it, not to crash the page displaying it.
	 *
	 * @param string $from Current status.
	 * @param string $to   Requested status.
	 */
	public function allows( string $from, string $to ): bool {
		return in_array( $to, $this->transitions[ $from ] ?? array(), true );
	}

	/**
	 * Everything a record in this status may become. What a detail page draws buttons from.
	 *
	 * @param string $from Current status.
	 *
	 * @return string[]
	 */
	public function next( string $from ): array {
		return $this->transitions[ $from ] ?? array();
	}

	/**
	 * Is this the end of the road? A finalized document goes nowhere -- until reversal ships.
	 *
	 * @param string $from Current status.
	 */
	public function terminal( string $from ): bool {
		return array() === $this->next( $from );
	}

	/**
	 * Every status this machine knows.
	 *
	 * @return string[]
	 */
	public function statuses(): array {
		return array_keys( $this->transitions );
	}
}
