<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turn a variable-length list of ids into fixed-length batches, so `IN (…)` can be a LITERAL.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS CLASS EXISTS AT ALL.
 *
 * wordpress.org's review applies one rule to every query: **the string handed to `$wpdb->prepare()`
 * must be a constant literal** — not a variable holding one, and nothing interpolated into it. The
 * usual WordPress idiom for an `IN` list breaks that rule by construction: it builds a run of `%d`
 * placeholders with `array_fill()` and `implode()`, drops that variable into the middle of the
 * query string, and passes the ids alongside.
 *
 * Every id there is bound and the interpolated fragment is placeholders rather than data, so the
 * code is not vulnerable — but a reviewer's scanner cannot know that, and this exact shape is what
 * a sibling plugin in this family was rejected for on the second round of review. The rule is about
 * what can be PROVEN by looking, so the answer has to be a query whose text never varies.
 *
 * (The old idiom is described here rather than quoted, deliberately: a scanner reading this file
 * cannot tell an example in a docblock from a query, and one that was quoted here was duly
 * reported as a finding.)
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY NOT `FIND_IN_SET`, WHICH IS THE FAMILY'S DOCUMENTED FIX.
 *
 * `FIND_IN_SET( id, %s )` with a comma-joined string is one bound parameter and a fixed literal, so
 * it satisfies the rule too, and it is what plugixa-faq and plugixa-chauffeur use. It is rejected
 * here for one reason: it is a FUNCTION CALL ON THE COLUMN, so MySQL cannot use the index and every
 * lookup becomes a full table scan. In those plugins the tables are small. Here the same idiom sits
 * on `wp_users` (a membership site has six figures of them), on `items`, and on `item_stock` —
 * tables this plugin exists to let grow, read on the hot path of every list screen that resolves a
 * parent name. Trading an index for a review rule is not a trade worth making when a literal query
 * that KEEPS the index is available.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * HOW THE PADDING IS SAFE, WHICH IS THE ONLY SUBTLE PART.
 *
 * A batch shorter than CHUNK is padded by repeating an id already in it. `IN` is set membership:
 * `IN (7,7,7)` and `IN (7)` match exactly the same rows, and repeating an id cannot introduce one.
 * The alternative — padding with a sentinel such as 0 or -1 — would be a value the query claims to
 * be looking for, and on a table whose ids are not guaranteed positive that is a real row. Padding
 * with a member of the set cannot be wrong on any data.
 *
 * The caller therefore always binds exactly CHUNK values, always writes exactly CHUNK `%d`
 * placeholders as literal text, and merges the results of each batch itself.
 *
 * THIS RETURNS VALUES, NEVER SQL. A helper that hands back a query fragment reopens the same hole
 * from a different direction — the fragment is a variable by the time it reaches `prepare()` — and
 * the review guidance for this family names that shape specifically.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final class IdList {

	/**
	 * Ids per batch, and therefore the number of `%d` placeholders every caller must write out.
	 *
	 * The number is a straight trade: larger means fewer round trips and a longer literal, smaller
	 * means the reverse. Twenty keeps a default page of 100 ids to five queries while leaving the
	 * placeholder run short enough to read in one line of source — which matters, because an
	 * unreadable run of placeholders is exactly where a miscount would hide.
	 *
	 * CHANGING IT IS NOT A ONE-LINE CHANGE. Every call site's literal has to change with it, and
	 * `IdListTest` asserts the two agree by counting the placeholders in each shipped query.
	 */
	public const CHUNK = 20;

	/*
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * EVERY CALLER WRITES ITS BATCH OUT AS CHUNK SEPARATE ARGUMENTS. DO NOT COLLAPSE THEM.
	 *
	 * Indexing a batch entry by entry is more verbose than unpacking it, and the verbosity is the
	 * entire point: `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` counts arguments
	 * SYNTACTICALLY. A variadic spread is one argument as far as it can see, so it reported
	 * "found 2, expected 21" on code that passes exactly 21 — at all seventeen call sites, on every
	 * run, for as long as they were written that way.
	 *
	 * Those warnings stood for a while on the argument that the sniff could not be satisfied without
	 * changing the query shape. That was wrong, and worth recording as wrong: passing an assembled
	 * array does not help, and the fill-and-join idiom is the shape this class exists to avoid, but
	 * writing the arguments out individually satisfies the sniff exactly and changes NOTHING else —
	 * same literal query text, same bound values, same index usage, no suppressions anywhere. PHPCS
	 * and Plugin Check both read zero on the whole plugin afterwards.
	 *
	 * (Written in prose, without an example: a scanner cannot tell a docblock from a statement, and
	 * an earlier draft of this note that spelled the call out was itself reported as a finding.)
	 *
	 * SO THE RULE IS: if a call site here ever grows a `...` again, the warning comes back with it.
	 * Reach for a suppression at that point and you have traded a mechanical, checkable shape for
	 * exactly the kind of blanket ignore wordpress.org objects to.
	 *
	 * `SqlLiteralTest` counts the placeholders in the shipped queries against this constant, gating
	 * on the FILE rather than the placeholder type — the two string call sites bind `%s`, and an
	 * earlier `%d`-only check silently exempted them.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 */

	/**
	 * Split ids into padded batches of exactly {@see self::CHUNK}.
	 *
	 * @param array<int|string> $ids Ids to look up. Duplicates and non-numeric entries are dropped.
	 *
	 * @return list<list<int>> Batches, each exactly CHUNK long. Empty when there is nothing to ask
	 *                         for — callers must treat that as "run no query", not "match nothing",
	 *                         because a query padded from an empty set has nothing to pad WITH.
	 */
	public static function chunks( array $ids ): array {
		$clean = array();

		foreach ( $ids as $id ) {
			if ( ! is_numeric( $id ) ) {
				continue;
			}

			$clean[ (int) $id ] = (int) $id;
		}

		if ( array() === $clean ) {
			return array();
		}

		$batches = array();

		foreach ( array_chunk( array_values( $clean ), self::CHUNK ) as $batch ) {
			$pad = $batch[0];

			while ( count( $batch ) < self::CHUNK ) {
				$batch[] = $pad;
			}

			$batches[] = $batch;
		}

		return $batches;
	}
}
