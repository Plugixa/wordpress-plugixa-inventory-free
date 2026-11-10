<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An import is ONE thing that happened, however many documents it took to record it.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS. Finalizing a stock document raises an alert, and that is right: somebody
 * correcting stock by hand is an event other people may want to notice. An import posts one
 * document per warehouse and another every five hundred lines, so a large catalogue load finalizes
 * a dozen of them in a few seconds — and each one is grouped by its own document id, so each one is
 * its own bell row. Twelve rows all saying "Adjustment ADJ-2026-0043 has been finalized" tell the
 * reader nothing they cannot see on the import screen, and they bury the day's real notifications
 * underneath them.
 *
 * WHAT IS NOT LOST BY SILENCING THEM. The person who ran the import is looking at a screen that
 * reports exactly what it did, in far more detail than a notification could. Everyone else can find
 * the documents in the Adjustments list, and every ledger row the run produced carries "import #N"
 * in its reason. The alert exists to surface a manual correction; opening balances arriving with a
 * catalogue are not that.
 *
 * WHY IT IS SCOPED TO THE POSTING AND NOT THE WHOLE RUN. The filter is added immediately before the
 * document work and removed in a `finally`, so nothing else in the request loses its notifications —
 * not the rest of the batch, and not a fatal half way through. Low stock is unaffected either way:
 * it is raised by a daily job rather than by finalizing, so it cannot be swallowed here.
 *
 * THE MODULE THAT OWNS ALERTS OWNS THE SEAM. This asks a question the Notifications module answers;
 * it does not reach into that module's tables, and it names no alert type — a caller that knows it
 * is performing one action is the only thing that CAN say so, which is the argument written on the
 * filter itself.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final class Quiet {

	/**
	 * Run something without it ringing the bell once per document.
	 *
	 * @template T
	 *
	 * @param callable(): T $work What to do quietly.
	 *
	 * @return T Whatever the work returned.
	 */
	public static function post( callable $work ): mixed {
		/*
		 * A NAMED VARIABLE RATHER THAN AN INLINE CLOSURE, because `remove_filter()` matches on the
		 * callable's identity: a second `static fn() => false` is a different object and would not
		 * remove the first, leaving the site silent for the rest of the request.
		 */
		$silence = static fn(): bool => false;

		add_filter( 'plugixa_inventory_should_notify', $silence );

		try {
			return $work();
		} finally {
			remove_filter( 'plugixa_inventory_should_notify', $silence );
		}
	}
}
