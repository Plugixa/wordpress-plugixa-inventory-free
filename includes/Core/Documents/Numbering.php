<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a document's reference number looks like.
 *
 * `ADJ-00001`, `TR-00042`. The prefix is the caller's; the format is this class's.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO, and it is most of the reason it is so small.
 *
 * It does **not** wrap the `apply_filters()` call. Each document module keeps its own
 * `apply_filters( 'plugixa_inventory_adjustment_number', ... )` with its own docblock, for three reasons:
 *
 * 1. A filter name assembled from a variable — `apply_filters( $filter, … )` — is invisible to
 *    every tool that reads hooks out of source, including WordPress's own hook parser. A site
 *    integrator greps for the hook they want to use, and a hook that cannot be grepped may as well
 *    not be documented.
 * 2. A hook's `@param` documentation belongs immediately above the call that fires it. Moved here,
 *    it would have to describe every document type at once, in the abstract.
 * 3. Each module owns its seam. That is the architecture, not a preference.
 *
 * It also does **not** hold a map of prefixes. `'SM-'`, `'TR-'`, `'ADJ-'` in a Core file would put
 * premium vocabulary into the free build, which is exactly what the free/premium seam exists to
 * prevent. The caller passes its own.
 *
 * So what is left is the one thing that would silently drift across three modules: the width.
 * Two documents padded to five digits and one padded to four is the kind of difference nobody
 * notices until a customer sorts a spreadsheet by reference and the order is wrong.
 */
final class Numbering {

	/**
	 * Digits the sequence is padded to.
	 *
	 * FOUR, NOT THE THREE THE JOOMLA COMPONENT'S OWN DATA SHOWS. Its demo references read
	 * `ADJ-2026-005`, which is the same shape one digit narrower — and a reference that changes
	 * width sorts wrongly as text for ever afterwards. Three digits does that at the 1,000th
	 * document of a year, which is a normal year for a warehouse; four does it at the 10,000th,
	 * which is not.
	 */
	public const WIDTH = 4;

	/**
	 * A document reference: prefix, year, sequence. `ADJ-2026-0005`.
	 *
	 * THE YEAR IS PART OF IT, matching the component this replaces. It is not decoration: references
	 * are what people quote to each other, and one that says which year it belongs to answers a
	 * question that would otherwise need the document open.
	 *
	 * THE SEQUENCE IS THE ROW ID, NOT A PER-YEAR COUNTER, and that is the one place this departs
	 * from what the format suggests. A counter has to be derived from something — the highest
	 * reference this year, or a count of rows — and both are wrong under concurrency (two documents
	 * finalized in the same second read the same maximum and take the same number) and wrong after a
	 * delete (a count-based counter reissues a number a purged document already used). The id is
	 * unique by construction and costs nothing.
	 *
	 * The visible cost is that numbering is not gapless and does not restart each January. Neither
	 * is a property this plugin ever promised, and a gapless sequence is a legal requirement only
	 * for invoices — which this is not.
	 *
	 * @param string $prefix The document's prefix, including any separator — `'ADJ-'`, `'TR-'`.
	 * @param int    $id     The document id.
	 * @param string $year   The year to stamp. Defaults to the site's current year.
	 */
	public static function reference( string $prefix, int $id, string $year = '' ): string {
		if ( '' === $year ) {
			// The SITE's year, not the server's: a document created at 23:30 on New Year's Eve
			// belongs to the year the business was in, not the one UTC was in.
			$year = current_time( 'Y' );
		}

		return $prefix . $year . '-' . str_pad( (string) $id, self::WIDTH, '0', STR_PAD_LEFT );
	}
}
