<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Filters;

use DateTimeZone;
use PlugixaInventory\Core\Database\Schema;
use PlugixaInventory\Core\Entities\RawInput;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TURNS `?amount__between=1000,5000` INTO A WHERE FRAGMENT, OR INTO A 400.
 *
 * The security property is the one `Modules\Reports\Sources` states and is worth restating,
 * because this class is the second place in the plugin where a request composes SQL:
 *
 *     the client sends a KEY           "amount"           and an OPERATOR KEY "between"
 *     both are LOOKED UP               => "t.amount"      => "BETWEEN %s AND %s"
 *     the FRAGMENTS go into the SQL    -- both written by a developer, in this repository
 *     an unknown key is a 400          -- not a query
 *
 * There is no code path here that takes a string off a request and puts it in a statement. Only
 * values are bound, because `prepare()` cannot parameterise a column name and never will.
 *
 * A PARAM IS A FILTER ONLY IF IT CARRIES AN OPERATOR SUFFIX. `?status=open` is NOT one — that is
 * `CasesController`'s own narrowing param, and has been since Cases shipped. Reading a bare field
 * name as a filter would have applied the same condition twice on every list that already narrows
 * itself, which is harmless for `eq` and quietly wrong the moment either side changes. Requiring
 * `__<operator>` gives the two vocabularies separate namespaces by construction rather than by a
 * list of exceptions somebody has to maintain.
 */
final class FilterCompiler {

	public function __construct( private readonly FilterCatalogue $catalogue ) {}

	/**
	 * Every filter in a request, as `array{ key, operator, values }`.
	 *
	 * Returns an empty list when the request carries none, which is the overwhelmingly common case
	 * and must cost nothing.
	 *
	 * @param array<string, mixed> $params      Request query params.
	 * @param string               $entity_type Registry slug.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function parse( array $params, string $entity_type ): array|WP_Error {
		$filters = array();

		foreach ( $params as $key => $raw ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$split = self::split( $key );

			if ( null === $split ) {
				continue;
			}

			list( $field, $operator ) = $split;

			$descriptor = $this->catalogue->field( $entity_type, $field );

			if ( null === $descriptor ) {
				// A KNOWN OPERATOR ON AN UNKNOWN FIELD IS A 400, NOT A SHRUG. Silently ignoring it
				// would answer a narrower question with a wider list, and the caller would have no
				// way to tell — which is how a typo becomes "the filter does not work sometimes".
				return new WP_Error(
					'plugixa_inventory_unknown_filter',
					/* translators: %s: the field asked for. */
					sprintf( __( '"%s" is not something this list can filter on.', 'plugixa-inventory' ), $field ),
					array( 'status' => 400 )
				);
			}

			$type = (string) $descriptor['type'];

			if ( ! Operators::allowed( $type, $operator ) ) {
				// Known globally, meaningless here — `contains` on a date, `between` on a dropdown.
				// Checked per type rather than per table, or the compiler would happily emit
				// `t.close_date LIKE '%x%'` and return nothing for a reason nobody could see.
				return new WP_Error(
					'plugixa_inventory_unknown_operator',
					sprintf(
						/* translators: 1: the operator asked for, 2: the field. */
						__( '"%1$s" is not a comparison that works on "%2$s".', 'plugixa-inventory' ),
						$operator,
						$field
					),
					array( 'status' => 400 )
				);
			}

			$values = self::values( $raw, $operator, isset( $descriptor['options'] ) ? (array) $descriptor['options'] : null );
			$arity  = (int) Operators::ALL[ $operator ]['args'];

			if ( $arity > 0 && count( $values ) < $arity ) {
				return new WP_Error(
					'plugixa_inventory_missing_value',
					/* translators: %s: the field. */
					sprintf( __( 'The filter on "%s" is missing a value.', 'plugixa-inventory' ), $field ),
					array( 'status' => 400 )
				);
			}

			$bad_date = self::unparseable_date( $type, $values, $arity );

			if ( null !== $bad_date ) {
				// A 400 FOR THE SAME REASON AN UNKNOWN FIELD IS ONE. Left alone, "nonsense" reaches
				// the statement as a bound and MySQL answers `Incorrect DATETIME value` — an empty
				// list, which is defensible, but written into the site's error log on every request
				// and a hard failure on a server configured to treat it as one. Converting it is
				// worse still: every date parser has an epoch fallback, and `>= 1970-01-01` returns
				// EVERY row, so a typo would silently widen the list instead of narrowing it.
				return new WP_Error(
					'plugixa_inventory_invalid_date',
					sprintf(
						/* translators: 1: the value sent, 2: the field. */
						__( '"%1$s" is not a date, so "%2$s" cannot be filtered by it.', 'plugixa-inventory' ),
						$bad_date,
						$field
					),
					array( 'status' => 400 )
				);
			}

			$filters[] = array(
				'key'        => $field,
				'operator'   => $operator,
				'values'     => $values,
				'descriptor' => $descriptor,
			);
		}

		return $filters;
	}

	/**
	 * Compile parsed filters into clauses for `plugixa_inventory_query_clauses`.
	 *
	 * WHERE AND PARAMS GO IN LOCKSTEP. `QueryBuilder` renders every `where` in array order and then
	 * merges every `param` in array order, so a fragment whose placeholders are pushed out of step
	 * with its values does not error — it binds the neighbouring filter's value and returns a
	 * plausible, wrong list. Nothing here may push one without the other.
	 *
	 * NOTHING IS EVER ADDED TO `join`. The hook's params are appended AFTER the caller's, while a
	 * join renders BEFORE the where clause, so a placeholder inside an injected join is bound from
	 * the wrong end of the array. `Tags\Module::tag_clause()` documents the same trap.
	 *
	 * @param array<int, array<string, mixed>> $filters     From parse().
	 * @param string                           $entity_type Registry slug.
	 *
	 * @return array{where: string[], params: array<int, mixed>}
	 */
	public function compile( array $filters, string $entity_type ): array {
		$clauses = array(
			'where'  => array(),
			'params' => array(),
		);

		foreach ( $filters as $filter ) {
			$descriptor = (array) $filter['descriptor'];

			$compiled = empty( $descriptor['custom'] )
				? self::column_clause( $filter )
				: self::custom_field_clause( $filter, $entity_type );

			$clauses['where'][] = $compiled['sql'];
			$clauses['params']  = array_merge( $clauses['params'], $compiled['params'] );
		}

		return $clauses;
	}

	/**
	 * A filter on one of the entity's own columns.
	 *
	 * @param array<string, mixed> $filter One parsed filter.
	 *
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private static function column_clause( array $filter ): array {
		$descriptor = (array) $filter['descriptor'];
		$column     = (string) $descriptor['sql'];
		$operator   = (string) $filter['operator'];

		$predicate = self::predicate(
			$operator,
			(array) $filter['values'],
			(string) $descriptor['type'],
			(string) ( $descriptor['stored'] ?? 'local' )
		);

		return array(
			'sql'    => $column . ' ' . $predicate['sql'],
			'params' => $predicate['params'],
		);
	}

	/**
	 * A filter on a custom field.
	 *
	 * A SEMI-JOIN, NEVER A JOIN, for the two reasons `Tags\Module::tag_clause()` records: a join
	 * fans out and `SELECT t.*` has no DISTINCT, so a record would come back twice while
	 * `COUNT(t.id)` counted it once — a duplicate row and a pager that disagrees about the total.
	 * A subquery cannot fan out at all.
	 *
	 * `empty` AND `not_empty` INVERT, AND THIS IS THE SUBTLE ONE. A custom field with no row at all
	 * is the normal unanswered state — `CustomFieldValueRepository::put()` deletes rather than
	 * soft-deletes when a field is cleared, so "cleared" means "gone", not "NULL". Compiling
	 * `empty` as `v.value_text IS NULL` inside the subquery would therefore match only records that
	 * somehow hold a row with a null value, which is almost none of them: the filter would return
	 * an empty list and look like it worked. It has to be asked from the outside — "records with no
	 * answering row" — which is `NOT IN`.
	 *
	 * @param array<string, mixed> $filter      One parsed filter.
	 * @param string               $entity_type Registry slug.
	 *
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private static function custom_field_clause( array $filter, string $entity_type ): array {
		$descriptor = (array) $filter['descriptor'];
		$operator   = (string) $filter['operator'];

		/*
		 * THE TABLE AND THE COLUMN ARE BOUND WITH `%i`, NOT INTERPOLATED, even though both are
		 * literals the request cannot reach — the table comes from `Schema::table()` and the column
		 * from FieldType's own map. Interpolating them was safe and looked unsafe, and looking
		 * unsafe is the whole of what a reviewer can judge: a backtick-quoted `{$table}` inside a
		 * query string is exactly the shape wordpress.org rejects. `field_id` is an integer and was
		 * always bound.
		 */
		$table = Schema::table( 'custom_field_values' );

		/*
		 * THE ALIAS STAYS IN THE LITERAL AND ONLY THE BARE COLUMN IS BOUND. `%i` quotes whatever it
		 * is given as ONE identifier, so binding "v.value_text" would emit `v.value_text` in
		 * backticks -- a column of that literal name, which does not exist, and the filter would
		 * fail rather than silently mismatch. The query therefore reads `AND v.%i`, which renders
		 * as v.`value_text`: qualification from the constant text, quoting from the binding.
		 */
		$column = (string) $descriptor['column'];

		if ( 'empty' === $operator || 'not_empty' === $operator ) {
			$predicate = array(
				'sql'    => 'IS NOT NULL',
				'params' => array(),
			);
		} else {
			$predicate = self::predicate( $operator, (array) $filter['values'], (string) $descriptor['type'] );
		}

		/*
		 * TWO WHOLE LITERALS RATHER THAN AN INTERPOLATED `IN`/`NOT IN`. A set-membership DIRECTION
		 * is not a value and cannot be a placeholder, so the only way to keep the text constant is
		 * to write both texts out. The duplication is the point: each branch is a string a reader —
		 * or a scanner — can check on its own.
		 */
		$sql = 'empty' === $operator
			? 't.id NOT IN (
				SELECT v.entity_id FROM %i v
				WHERE v.entity_type = %s AND v.field_id = %d AND v.deleted_at IS NULL
				  AND v.%i ' . $predicate['sql'] . '
			)'
			: 't.id IN (
				SELECT v.entity_id FROM %i v
				WHERE v.entity_type = %s AND v.field_id = %d AND v.deleted_at IS NULL
				  AND v.%i ' . $predicate['sql'] . '
			)';

		return array(
			'sql'    => $sql,
			// Bound in the order the placeholders appear: table, entity type, field, column, then
			// whatever the comparison itself needs.
			'params' => array_merge(
				array( $table, $entity_type, (int) $descriptor['field_id'], $column ),
				$predicate['params']
			),
		);
	}

	/**
	 * The comparison half of a clause — everything after the column.
	 *
	 * @param string             $operator Operator key, already known and allowed for the type.
	 * @param array<int, string> $values   Values off the wire.
	 * @param string             $type     Filter type.
	 * @param string             $stored   How the column holds its datetimes: 'utc' or 'local'.
	 *
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private static function predicate( string $operator, array $values, string $type, string $stored = 'local' ): array {
		$spec = Operators::ALL[ $operator ];

		if ( -1 === $spec['args'] ) {
			// IN. One placeholder per value; no values is a clause that can never match, which is
			// the honest reading of "in nothing" and better than dropping the filter and quietly
			// widening the list.
			if ( array() === $values ) {
				return array(
					'sql'    => 'IN (NULL)',
					'params' => array(),
				);
			}

			return array(
				'sql'    => 'IN (' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')',
				'params' => $values,
			);
		}

		$arity  = (int) $spec['args'];
		$values = array_slice( $values, 0, max( 0, $arity ) );

		if ( isset( $spec['wrap'] ) ) {
			// esc_like FIRST, so a literal % someone typed stays a literal %. `paged_list()` does
			// the same to the search term, and a filter that treated wildcards differently from
			// the search box on the same toolbar would be a surprise nobody could explain.
			$values[0] = sprintf( (string) $spec['wrap'], $GLOBALS['wpdb']->esc_like( (string) $values[0] ) );
		}

		if ( 'date' === $type ) {
			return self::date_predicate( $spec, $operator, $values, 'utc' === $stored );
		}

		return array(
			'sql'    => (string) $spec['sql'],
			'params' => $values,
		);
	}

	/**
	 * The first bound on a date filter that is not a date, or null when they all are.
	 *
	 * ONLY THE VALUES THE OPERATOR WILL ACTUALLY USE ARE CHECKED. `?created_at__empty=1` carries a
	 * "1" that is never bound to anything — `empty` compiles to `IS NULL` and takes no arguments —
	 * and refusing the request over it would break a filter that works.
	 *
	 * @param string             $type     Filter type.
	 * @param array<int, string> $values   Values off the wire.
	 * @param int                $arity    How many the operator consumes; -1 for a list.
	 */
	private static function unparseable_date( string $type, array $values, int $arity ): ?string {
		if ( 'date' !== $type ) {
			return null;
		}

		foreach ( array_slice( $values, 0, max( 0, $arity ) ) as $value ) {
			if ( false === date_create_immutable( (string) $value ) ) {
				return (string) $value;
			}
		}

		return null;
	}

	/**
	 * A comparison against a date column: the user's whole day, in the column's own timezone.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * A DATE PICKER SENDS A DAY AND THE COLUMN HOLDS AN INSTANT, so every bound here is a day that
	 * has to be turned into the right instant. Three separate ways of getting that wrong live in
	 * this one method:
	 *
	 * 1. THE UPPER BOUND IS A DAY WIDE. `2026-03-31` reads to MySQL as `2026-03-31 00:00:00`, so
	 *    `created_at <= '2026-03-31'` excludes everything that happened during the 31st. The user
	 *    asks for up to and including the end of the month and is shown one fewer day than they
	 *    picked, with no error and nothing on screen to suggest the range was not honoured.
	 *
	 * 2. `eq` ON A DATETIME COLUMN MATCHES ALMOST NOTHING. "Created on the 31st" compiled to
	 *    `= '2026-03-31 00:00:00'`, which is true only for a record written in the first second of
	 *    the day. So the operator behind the chevron returned an empty list and looked like a
	 *    filter that simply found nothing. It is a day, and it compiles to one.
	 *
	 * 3. THE DAY IS THE USER'S, THE COLUMN MAY BE UTC. Audit columns are written with
	 *    `current_time( 'mysql', true )`; a picker sends the day the user is living in. On a UTC+2
	 *    site an unconverted bound moves every range two hours late — the first two hours of their
	 *    day missing from their own list, two hours of the next day silently in it. `stored` on the
	 *    descriptor says which kind of column this is; see `Repository::common_filters()`.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * ONLY UPPER BOUNDS ARE WIDENED. `gte` and `lt` at midnight already mean what they say, and
	 * `gt`/`lt` are chevron escapes whose reading is a judgement rather than a defect.
	 *
	 * @param array<string, mixed> $spec     The operator's SQL and arity.
	 * @param string               $operator Operator key.
	 * @param array<int, string>   $values   Bound values, in the site's timezone.
	 * @param bool                 $utc      Whether the column stores UTC.
	 *
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private static function date_predicate( array $spec, string $operator, array $values, bool $utc ): array {
		if ( 'eq' === $operator && isset( $values[0] ) ) {
			$day = (string) $values[0];

			return array(
				'sql'    => 'BETWEEN %s AND %s',
				'params' => array( self::bound( $day, false, $utc ), self::bound( $day, true, $utc ) ),
			);
		}

		// Which value, if any, is the end of a range rather than its start.
		$upper = array(
			'lte'     => 0,
			'between' => 1,
		);

		foreach ( $values as $index => $value ) {
			$values[ $index ] = self::bound(
				(string) $value,
				isset( $upper[ $operator ] ) && $upper[ $operator ] === $index,
				$utc
			);
		}

		return array(
			'sql'    => (string) $spec['sql'],
			'params' => $values,
		);
	}

	/**
	 * One bound: a bare day given its time, then moved into the column's timezone.
	 *
	 * ANYTHING THAT IS NOT A DATE IS RETURNED UNTOUCHED. `parse()` already refused the request with
	 * a 400 before it could get here, so this branch is a belt on top of a brace — but it is the
	 * belt that matters, because the failure it prevents is silent. Every convenient date helper
	 * (`get_gmt_from_date()` among them) falls back to the epoch on input it cannot read, which
	 * would turn a typo into `>= 1970-01-01` and return EVERY row. A filter that silently widens is
	 * worse than one that silently narrows: nobody looks twice at a list with too much in it.
	 *
	 * @param string $value      A date or datetime from the request.
	 * @param bool   $end_of_day Whether a bare day means its last second rather than its first.
	 * @param bool   $utc        Whether to convert out of the site's timezone.
	 */
	private static function bound( string $value, bool $end_of_day, bool $utc ): string {
		if ( 10 === strlen( $value ) ) {
			$value .= $end_of_day ? ' 23:59:59' : ' 00:00:00';
		}

		if ( ! $utc ) {
			return $value;
		}

		$at = date_create_immutable( $value, wp_timezone() );

		return false === $at ? $value : $at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Split `amount__between` into its field and its operator, or null if it is not a filter param.
	 *
	 * Split on the LAST `__`, because a field key may legitimately contain one — `cf_last__seen` is
	 * a valid custom field key, and splitting on the first would look for a field called `cf_last`
	 * and an operator called `seen`. The suffix must be a real operator or this is not a filter at
	 * all, which is what keeps `per_page` and `orderby` out of here.
	 *
	 * @param string $key Request param name.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function split( string $key ): ?array {
		$at = strrpos( $key, '__' );

		if ( false === $at || 0 === $at ) {
			return null;
		}

		$operator = substr( $key, $at + 2 );

		if ( ! Operators::known( $operator ) ) {
			return null;
		}

		return array( substr( $key, 0, $at ), $operator );
	}

	/**
	 * The values a param carries.
	 *
	 * ONLY `in` AND `between` SPLIT ON COMMAS, and that is what makes comma-separation safe rather
	 * than merely convenient. Every other operator binds exactly one value, so a comma inside a
	 * `contains` search is just a comma. And the two that do split take ids, enum keys, numbers and
	 * dates — none of which can contain one.
	 *
	 * A FIELD THAT OFFERS OPTIONS IS MATCHED AGAINST THEM FIRST, {@see RawInput::offered()}. The
	 * drawer sends an option exactly as the catalogue listed it, and a custom select's options are
	 * free text: sanitizing "<5 kg" into "&lt;5 kg" made the filter match no item, even though items
	 * hold "<5 kg". Enum keys come back unchanged either way. A value that is not offered keeps the
	 * plain-text treatment and simply matches nothing, as it always has.
	 *
	 * @param mixed         $raw      Raw param value.
	 * @param string        $operator Operator key.
	 * @param string[]|null $options  What the field offers, when it offers a fixed list.
	 *
	 * @return array<int, string>
	 */
	private static function values( mixed $raw, string $operator, ?array $options = null ): array {
		if ( is_array( $raw ) ) {
			$values = $raw;
		} elseif ( in_array( $operator, array( 'in', 'between' ), true ) ) {
			$values = explode( ',', (string) $raw );
		} else {
			$values = array( $raw );
		}

		$values = array_map(
			static fn( mixed $value ): string => ( null === $options ? null : RawInput::offered( $value, $options ) )
				?? sanitize_text_field( (string) $value ),
			$values
		);

		// An empty string is the absence of a value, not a value of "". Dropping them is what lets
		// a half-filled range degrade gracefully rather than searching for the empty string.
		return array_values( array_filter( $values, static fn( string $value ): bool => '' !== $value ) );
	}
}
