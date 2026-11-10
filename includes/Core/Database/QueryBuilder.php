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
 * A small, prepared-statement-only SELECT builder for inventory list queries.
 *
 * Every value goes through $wpdb->prepare; identifiers (columns, tables, directions)
 * are never interpolated from request data without passing an allow-list first —
 * that is the caller's job, and Repository does it.
 *
 * WHAT IS INTERPOLATED, AND WHAT IS BOUND.
 *
 * The table name and ORDER BY's column are BOUND, as `%i` identifier placeholders, alongside
 * the values. Nothing reaches the statement as text except fragments an author typed: the
 * SELECT list, JOIN clauses, the `%s`/`%d` half of where()/join()/having(), GROUP BY expressions,
 * the ASC/DESC keyword (normalised in order_by(), and a keyword rather than an identifier, so
 * `%i` would wrongly backtick it), and a grouped query's ORDER BY alias — which is not merely
 * escaped but resolved against the aliases that same statement declares. See order_by_aggregate().
 *
 * That is as far as binding goes, and the reason is structural rather than an oversight: this
 * class assembles one statement out of fragments contributed by ~40 repositories and by the
 * `plugixa_inventory_query_clauses` filter, so there is no point at which a whole literal statement
 * exists to hand to prepare(). Hand-writing literal SQL per repository would buy that at the
 * cost of the single choke point below — which is where record-level access control lives, so
 * it would be a worse trade than it looks.
 *
 * The reason this class exists rather than raw SQL in each repository: it is the one
 * place that fires `plugixa_inventory_query_clauses`, which is how the premium Roles
 * module scopes every list query in the inventory to the records a user may see. Without a
 * single choke point, record-level ACL would mean editing every repository.
 *
 * It fires a SECOND hook, `plugixa_inventory_search_fragments`, and search() says why that one could
 * not be folded into the first: clauses are AND-ed, and a search has to be OR-ed.
 */
final class QueryBuilder {

	/** @var string[] */
	private array $select = array( 't.*' );

	/** @var string[] */
	private array $joins = array();

	/**
	 * The JOINs' values, kept apart from WHERE's for the same reason having_params is.
	 *
	 * A JOIN renders BEFORE the WHERE clause, so its placeholders come first in the statement and
	 * its values have to come first in the bound array. Merging them into $params would put a
	 * JOIN's value behind the caller's WHERE values and bind each into the other's hole —
	 * silently, with SQL that still reads correctly. See join().
	 *
	 * @var array<int, mixed>
	 */
	private array $join_params = array();

	/** @var string[] */
	private array $where = array();

	/** @var array<int, mixed> */
	private array $params = array();

	/** @var string[] */
	private array $group_by = array();

	/** @var string[] */
	private array $having = array();

	/**
	 * HAVING's values, kept apart from WHERE's. See having().
	 *
	 * @var array<int, mixed>
	 */
	private array $having_params = array();

	/**
	 * Values for placeholders inside a GROUP BY expression.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════════
	 * THESE BIND TWICE, AND THAT IS NOT A BUG. A grouped expression is written into the statement in
	 * two places — aliased in the SELECT list and bare in the GROUP BY — because `GROUP BY expr AS
	 * alias` is not SQL. Two appearances of `%d` are two placeholders, so the same values are merged
	 * into the parameter list twice, at the two positions those appearances occupy: the SELECT list
	 * is the FIRST thing in the statement, ahead of even the table, and the GROUP BY comes after
	 * WHERE.
	 *
	 * The bucket exists at all so that grouping a UTC column into the site's days can BIND its
	 * offset instead of concatenating it. The offset is an integer from `wp_timezone()` and could
	 * not be reached by a request, but "it is only an int" is the argument every interpolated query
	 * makes, and this class is the one place in the plugin that must not make it.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @var array<int, mixed>
	 */
	private array $group_by_params = array();

	/**
	 * Which of the grouped query's own aliases orders the result, and which way.
	 *
	 * Empty means unordered, which is what every aggregate was before this existed. See
	 * order_by_aggregate().
	 */
	private string $aggregate_order     = '';
	private string $aggregate_order_dir = 'DESC';

	private string $order_by = 't.id';
	private string $order    = 'DESC';
	private int $limit       = 50;
	private int $offset      = 0;

	/**
	 * How soft-deleted rows are treated. Excluding them is the default so that a
	 * caller who forgets about the trash cannot accidentally leak deleted records.
	 *
	 * @var 'active'|'trashed'|'all'
	 */
	private string $trash_scope = 'active';

	/**
	 * Whether the implicit clauses have already been merged in.
	 */
	private bool $prepared = false;

	/**
	 * @param string $table       Fully-qualified table name.
	 * @param string $entity_type Entity slug, passed to the ACL filter.
	 * @param string $context     Query context, e.g. "list" or "count".
	 */
	public function __construct(
		private readonly string $table,
		private readonly string $entity_type,
		private readonly string $context = 'list'
	) {}

	/**
	 * @param string ...$columns Column expressions.
	 */
	public function select( string ...$columns ): self {
		$this->select = $columns;

		return $this;
	}

	/**
	 * Add a JOIN, optionally with values bound into its ON clause.
	 *
	 * WHY A PREDICATE WOULD EVER LIVE IN `ON` RATHER THAN IN `where()`. On an INNER JOIN the two are
	 * equivalent and `where()` is the clearer place. On a LEFT JOIN they are opposites: a condition
	 * in `ON` narrows what the join MATCHES and keeps the unmatched left rows with NULLs, while the
	 * same condition in `WHERE` is evaluated after the join and DELETES those rows — turning the
	 * LEFT back into an INNER without looking like it.
	 *
	 * That distinction is the whole turnover report. "Which items moved least in thirty days" has to
	 * count the items that did not move at all, and they are exactly the rows a WHERE on the ledger's
	 * date would drop. The report would come back looking plausible and listing the wrong items.
	 *
	 * @param string            $sql    SQL JOIN clause, with %s/%d/%f placeholders for any values.
	 * @param array<int, mixed> $params Values for those placeholders.
	 */
	public function join( string $sql, array $params = array() ): self {
		$this->joins[]      = $sql;
		$this->join_params  = array_merge( $this->join_params, $params );

		return $this;
	}

	/**
	 * Add a WHERE condition.
	 *
	 * @param string            $sql    Condition with %s/%d/%f placeholders.
	 * @param array<int, mixed> $params Values for the placeholders.
	 */
	public function where( string $sql, array $params = array() ): self {
		$this->where[]  = $sql;
		$this->params   = array_merge( $this->params, $params );

		return $this;
	}

	/**
	 * Narrow to rows matching a search term — across the caller's own columns AND whatever a
	 * module contributes.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════════
	 * ONE PARENTHESISED OR GROUP, AND THAT IS THE WHOLE REASON THIS IS A SECOND HOOK.
	 *
	 * A record matches a search if its NAME matches OR one of its searchable custom fields does.
	 * `plugixa_inventory_query_clauses` cannot express that: every clause contributed there is AND-ed
	 * onto the statement, so a custom-field clause added that way would REMOVE the records that
	 * matched by name — the exact opposite of a search. The two halves have to be joined by the
	 * one piece of code that owns both, and the only code that owns the native columns is the
	 * caller that names them. So the seam has to be here.
	 *
	 * Anyone tempted to move this onto `plugixa_inventory_query_clauses` "for consistency": that is the
	 * answer, and it has not changed.
	 * ════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * A NO-OP ON AN EMPTY TERM, and on a term with nothing at all to match against. That is the
	 * behaviour `RestController::paged_list()` already had — it skipped the clause when the caller
	 * passed no columns — kept so that a list with nothing searchable stays a list rather than
	 * becoming empty.
	 *
	 * CALL IT BEFORE count(). The pager counts the same builder the page reads, so a search added
	 * after the count leaves the grid and its pager disagreeing about how many rows there are.
	 *
	 * @param string   $term    The raw term. esc_like()d here — callers must NOT pre-wrap it.
	 * @param string[] $columns Author-written column expressions, e.g. array( 't.full_name' ).
	 */
	public function search( string $term, array $columns = array() ): self {
		global $wpdb;

		$term = trim( $term );

		if ( '' === $term ) {
			return $this;
		}

		$like   = '%' . $wpdb->esc_like( $term ) . '%';
		$sql    = array();
		$params = array();

		foreach ( $columns as $column ) {
			$sql[]    = $column . ' LIKE %s';
			$params[] = $like;
		}

		/**
		 * Extra OR fragments for a search over this entity.
		 *
		 * Each entry is `array{ sql: string, params: array<int, mixed> }`. `sql` is a complete
		 * boolean expression written by an author — never a string off the wire — and its
		 * placeholders must line up, IN ORDER, with `params`. They are appended in lockstep here;
		 * a fragment that contributes one without the other does not error, it binds its
		 * neighbour's value and returns a plausible, wrong list.
		 *
		 * The term arrives RAW: a consumer that needs a prefix match needs the unwrapped string,
		 * and one that wrapped it itself would double-escape.
		 *
		 * @param array<int, array{sql: string, params: array<int, mixed>}> $fragments Accumulated.
		 * @param string                                                    $entity_type Entity slug.
		 * @param string                                                    $term        The raw term.
		 * @param string                                                    $context     list/count/search.
		 */
		$fragments = (array) apply_filters(
			'plugixa_inventory_search_fragments',
			array(),
			$this->entity_type,
			$term,
			$this->context
		);

		foreach ( $fragments as $fragment ) {
			$piece = (string) ( $fragment['sql'] ?? '' );

			if ( '' === $piece ) {
				continue;
			}

			$sql[]  = $piece;
			$params = array_merge( $params, array_values( (array) ( $fragment['params'] ?? array() ) ) );
		}

		if ( array() === $sql ) {
			return $this;
		}

		return $this->where( '(' . implode( ' OR ', $sql ) . ')', $params );
	}

	/**
	 * @param string   $column    Column, already validated against an allow-list.
	 * @param string   $direction ASC or DESC.
	 */
	public function order_by( string $column, string $direction = 'DESC' ): self {
		$this->order_by = $column;
		$this->order    = 'ASC' === strtoupper( $direction ) ? 'ASC' : 'DESC';

		return $this;
	}

	/**
	 * @param int $limit  Rows to return.
	 * @param int $offset Rows to skip.
	 */
	public function paginate( int $limit, int $offset ): self {
		$this->limit  = max( 1, min( 200, $limit ) );
		$this->offset = max( 0, $offset );

		return $this;
	}

	/**
	 * Include soft-deleted rows alongside live ones.
	 */
	public function with_trashed(): self {
		$this->trash_scope = 'all';

		return $this;
	}

	/**
	 * Return only soft-deleted rows — the Trash view.
	 */
	public function only_trashed(): self {
		$this->trash_scope = 'trashed';

		return $this;
	}

	/**
	 * Fetch the rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get(): array {
		global $wpdb;

		$this->prepare();

		$sql = sprintf(
			'SELECT %s FROM %%i t %s %s ORDER BY %s %s LIMIT %%d OFFSET %%d',
			implode( ', ', $this->select ),
			implode( ' ', $this->joins ),
			$this->where_sql(),
			$this->order_by_sql(),
			$this->order
		);

		// Placeholder order is the statement's order: the table in FROM, then the JOINs' values,
		// then WHERE's, then ORDER BY's column, then the page. Get this wrong and prepare()
		// binds the right values into the wrong holes — silently, with SQL that still
		// reads correctly. See aggregate() for the same rule with HAVING in the middle.
		$params = array_merge(
			array( $this->table ),
			$this->join_params,
			$this->params,
			$this->order_by_params(),
			array( $this->limit, $this->offset )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is assembled only from author-written literals and %s/%d/%i placeholders; every value and identifier is bound below. See the class docblock.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Group the rows.
	 *
	 * A grouped column must be SELECTed too, or the rows come back as a list of numbers with
	 * nothing to say what each is a total OF. It is aliased in the SELECT and bare in the GROUP BY,
	 * because `GROUP BY expr AS alias` is not SQL — a detail that costs an afternoon if you learn it
	 * from MySQL's error message rather than from here.
	 *
	 * @param array<string|int, string> $columns alias => expression. An integer key means "no alias",
	 *                                           and MySQL names the column after the expression —
	 *                                           fine for `t.status`, useless for `DATE_FORMAT(…)`.
	 *                                           IDENTIFIERS — see aggregate().
	 * @param array<int, mixed>         $params  Values for placeholders in those expressions.
	 *                                           BOUND TWICE, ONCE PER APPEARANCE — see below.
	 */
	public function group_by( array $columns, array $params = array() ): self {
		$this->group_by        = $columns;
		$this->group_by_params = $params;

		return $this;
	}

	/**
	 * Filter on an aggregate, after grouping.
	 *
	 * Kept apart from where() because the two render in different halves of the statement and
	 * their placeholders must line up with their values in that order. Merging them into one
	 * array would put a HAVING value in front of a WHERE placeholder, and `prepare()` would bind
	 * the wrong value into the wrong hole — silently, with no error and no wrong-looking SQL.
	 *
	 * @param string            $sql    Condition with %s/%d/%f placeholders.
	 * @param array<int, mixed> $params Values for the placeholders.
	 */
	public function having( string $sql, array $params = array() ): self {
		$this->having[]       = $sql;
		$this->having_params  = array_merge( $this->having_params, $params );

		return $this;
	}

	/**
	 * Order a grouped result by one of the aliases that same query defines.
	 *
	 * WHY THIS IS SEPARATE FROM order_by(), which already exists and would look like the obvious
	 * place. That one binds its column as `%i` identifier segments split on the dot, because every
	 * caller passes a real column: `t.name`, `t.stock_quantity`. An aggregate has no column to
	 * name — the thing worth ordering by is `SUM( ABS( t.quantity_change ) )`, which is not an
	 * identifier and cannot be bound as one. Reusing order_by() would either backtick an
	 * expression into a column MySQL cannot find, or split `turnover_ratio` on a dot it does not
	 * contain and work by accident until an alias grew one.
	 *
	 * WHY IT IS SAFE TO INTERPOLATE WHERE order_by() BINDS. The alias is sanitised to
	 * `[a-z0-9_]` by safe_alias() and then checked, in aggregate(), against the aliases THIS
	 * query declared in its own expressions and group_by. So it is not merely escaped — it is
	 * resolved against an allow-list the caller built one line earlier out of literals they typed.
	 * An alias that is not in that list is dropped and the result comes back unordered, which is
	 * the pre-existing behaviour rather than a new failure mode.
	 *
	 * IT ALSO MAKES A CLASS OF REPORT CORRECT THAT WAS NOT. Ordering the rows in PHP after the
	 * fact only works when every row is in hand, and aggregate() caps at $limit; "the ten items
	 * with the LOWEST turnover" sorted client-side is the lowest ten of an arbitrary 500, which
	 * is a different question with a plausible-looking answer.
	 *
	 * @param string $alias     An alias from this query's own aggregate()/group_by() list.
	 * @param string $direction ASC or DESC.
	 */
	public function order_by_aggregate( string $alias, string $direction = 'DESC' ): self {
		$this->aggregate_order     = self::safe_alias( $alias );
		$this->aggregate_order_dir = 'ASC' === strtoupper( $direction ) ? 'ASC' : 'DESC';

		return $this;
	}

	/**
	 * Run an aggregate query and return the grouped rows.
	 *
	 * WHY THIS IS ON QueryBuilder AND NOT IN A REPORT REPOSITORY.
	 *
	 * Every aggregate in this plugin could just as easily be hand-written $wpdb — the dashboard
	 * totals, the stock valuation, the low-stock count. That was survivable while free's authorisation was
	 * all-or-nothing. It stops being survivable the moment the premium Roles module ships, because raw SQL
	 * does not pass through apply_acl_clauses(), and an aggregate is the perfect leak: it
	 * launders rows a user may not read into a total they may. They would not see a record they
	 * are forbidden; they would see a NUMBER that includes it, with nothing to trace it to and
	 * nothing about it looking wrong.
	 *
	 * Putting aggregation here means a report CANNOT be written that skips the ACL, because there
	 * is no other way to write one. That is a structural property, not a rule someone has to
	 * remember. It also applies the trash scope, so a report never silently counts deleted rows.
	 *
	 * IDENTIFIERS ARE THE CALLER'S PROBLEM, AND THEY ARE NOT NEGOTIABLE.
	 *
	 * $expressions, $this->group_by and the $sql half of having() are interpolated into the
	 * statement — they have to be, because `prepare()` binds VALUES and cannot parameterise a
	 * column name, a table name or SUM(). So every one of them must be a literal the code author
	 * wrote, resolved from an allow-list, and never a string that came off the wire. The alias is
	 * checked here because it is the one identifier this class can check cheaply; everything else
	 * is on the caller, exactly as it already is for select(), join() and order_by().
	 *
	 * The premium Reports module never lets a request name a column: a request names a KEY, the
	 * key is looked up in a table of literals, and an unknown key is a 400 rather than a query.
	 *
	 * @param array<string, string> $expressions alias => SQL aggregate, e.g. ['total' => 'SUM(t.amount_base)'].
	 * @param int                   $limit       Groups to return. Capped, because a GROUP BY on a
	 *                                           high-cardinality column can return a row per record.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function aggregate( array $expressions, int $limit = 500 ): array {
		global $wpdb;

		if ( array() === $expressions ) {
			return array();
		}

		$this->prepare();

		$select = array();

		// The aliases this statement actually defines. order_by_aggregate() is resolved against
		// this and nothing else, so an alias can only order the result if the caller declared it
		// here or in group_by() out of literals they wrote.
		$declared = array();

		foreach ( $expressions as $alias => $expression ) {
			$safe = self::safe_alias( (string) $alias );

			if ( '' === $safe ) {
				continue;
			}

			$declared[] = $safe;
			$select[]   = $expression . ' AS `' . $safe . '`';
		}

		// The grouped columns are SELECTed with their alias and GROUPed BY without it. `GROUP BY
		// expr AS alias` is not SQL, and MySQL's complaint about it is not a helpful one.
		$group_select = array();
		$group_expr   = array();

		foreach ( $this->group_by as $alias => $expression ) {
			$group_expr[] = $expression;
			$safe         = is_string( $alias ) ? self::safe_alias( $alias ) : '';

			$group_select[] = '' === $safe ? $expression : $expression . ' AS `' . $safe . '`';

			if ( '' !== $safe ) {
				$declared[] = $safe;
			}
		}

		// Unknown alias, no ORDER BY. Silently, and deliberately: the alternative is a fatal on a
		// report screen because somebody renamed an aggregate, and an unordered aggregate is what
		// every one of these returned before ordering existed.
		$order_sql = in_array( $this->aggregate_order, $declared, true )
			? 'ORDER BY `' . $this->aggregate_order . '` ' . $this->aggregate_order_dir
			: '';

		$sql = sprintf(
			'SELECT %s FROM %%i t %s %s %s %s %s LIMIT %%d',
			implode( ', ', array_merge( $group_select, $select ) ),
			implode( ' ', $this->joins ),
			$this->where_sql(),
			$group_expr ? 'GROUP BY ' . implode( ', ', $group_expr ) : '',
			$this->having ? 'HAVING ' . implode( ' AND ', $this->having ) : '',
			$order_sql
		);

		// Order is the whole point: the GROUPED expressions come FIRST because they are written into
		// the SELECT list ahead of the table, then the table in FROM, then the JOINs' values, then
		// WHERE's (the caller's, then the ACL's, appended by apply_acl_clauses), then the SAME
		// grouped values again for their second appearance in the GROUP BY, then HAVING's, then the
		// limit — matching the order the placeholders appear in the statement above. ORDER BY
		// contributes none: its alias is interpolated from the allow-list built above, not bound.
		$params = array_merge(
			$this->group_by_params,
			array( $this->table ),
			$this->join_params,
			$this->params,
			$this->group_by_params,
			$this->having_params,
			array( max( 1, min( 5000, $limit ) ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is assembled only from author-written literals and %s/%d/%i placeholders; every value and identifier is bound below. See the class docblock.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * An alias becomes an identifier in the result set. It is the one identifier this class can
	 * cheaply check, so it does; everything else is the caller's allow-list, exactly as it already
	 * is for select(), join() and order_by().
	 *
	 * @param string $alias Requested alias.
	 */
	private static function safe_alias( string $alias ): string {
		return (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $alias ) );
	}

	/**
	 * Count the rows the current conditions match, ignoring limit/offset.
	 */
	public function count(): int {
		global $wpdb;

		$this->prepare();

		$sql = sprintf(
			'SELECT COUNT(DISTINCT t.id) FROM %%i t %s %s',
			implode( ' ', $this->joins ),
			$this->where_sql()
		);

		// The table is always a bound identifier now, so there is no longer a case where the
		// statement goes to the database unprepared — the old `$params ? prepare : raw` fork
		// is gone, and with it the chance of taking the raw branch by accident.
		$params = array_merge( array( $this->table ), $this->join_params, $this->params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is assembled only from author-written literals and %s/%d/%i placeholders; every value and identifier is bound below. See the class docblock.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Apply the clauses that are added implicitly, exactly once.
	 *
	 * A list endpoint calls both get() and count() on the same builder to produce rows
	 * plus a total. Without this guard the ACL joins and the trash filter would be
	 * appended twice on the second call — duplicating a JOIN and silently changing the
	 * result — so preparation is idempotent.
	 */
	private function prepare(): void {
		if ( $this->prepared ) {
			return;
		}
		$this->prepared = true;

		$this->apply_trash_scope();
		$this->apply_acl_clauses();
	}

	/**
	 * Exclude soft-deleted rows unless the caller asked for them.
	 */
	private function apply_trash_scope(): void {
		if ( 'trashed' === $this->trash_scope ) {
			$this->where[] = 't.deleted_at IS NOT NULL';
		} elseif ( 'active' === $this->trash_scope ) {
			$this->where[] = 't.deleted_at IS NULL';
		}
	}

	/**
	 * Merge in JOIN/WHERE clauses contributed by hooks.
	 *
	 * This is the record-level access-control seam. The free build has no consumer,
	 * so the filter returns nothing and every user with the capability sees every
	 * record. The premium Roles module hooks it and adds an ownership or warehouse-scope join.
	 */
	private function apply_acl_clauses(): void {
		/**
		 * Filter the JOIN/WHERE clauses applied to an inventory list query.
		 *
		 * A narrowing listener needs to know WHICH TABLE it is narrowing, which is why the prefixed
		 * name is the fifth argument. Only four of this plugin's tables carry `assigned_user_id`;
		 * the rest record ownership as `created_by` alone, and a clause naming a column that is not
		 * there is not a narrower list, it is a fatal query on a screen that used to work. The
		 * entity slug cannot answer that -- the mapping from slug to table is a repository's
		 * private business -- so the builder says it outright rather than making listeners guess.
		 *
		 * @param array{join: string[], where: string[], params: array<int, mixed>} $clauses     Empty by default.
		 * @param string                                                            $entity_type Entity slug.
		 * @param string                                                            $context     Query context.
		 * @param int                                                               $user_id     Current user.
		 * @param string                                                            $table       The prefixed table.
		 */
		$clauses = apply_filters(
			'plugixa_inventory_query_clauses',
			array(
				'join'   => array(),
				'where'  => array(),
				'params' => array(),
			),
			$this->entity_type,
			$this->context,
			get_current_user_id(),
			$this->table
		);

		foreach ( (array) ( $clauses['join'] ?? array() ) as $join ) {
			$this->joins[] = (string) $join;
		}
		foreach ( (array) ( $clauses['where'] ?? array() ) as $where ) {
			$this->where[] = (string) $where;
		}
		$this->params = array_merge( $this->params, (array) ( $clauses['params'] ?? array() ) );
	}

	private function where_sql(): string {
		return $this->where ? 'WHERE ' . implode( ' AND ', $this->where ) : '';
	}

	/**
	 * ORDER BY's column as placeholders rather than as text.
	 *
	 * Every call site passes `t.<column>` — either a literal or `'t.' . safe_sort_column( … )`,
	 * which resolves against the repository's allow-list and falls back to `id`. So the value is
	 * always a qualified identifier, and a qualified identifier has to be bound a segment at a
	 * time: `%i` quotes what it is given whole, so binding `t.name` in one placeholder would ask
	 * MySQL for a column literally named "t.name" rather than for `name` on alias `t`.
	 *
	 * Splitting on the dot rather than hard-coding `%i.%i` keeps a bare, unqualified column
	 * working too, which is what a caller outside the `t.` convention would pass.
	 */
	private function order_by_sql(): string {
		return implode( '.', array_fill( 0, count( explode( '.', $this->order_by ) ), '%i' ) );
	}

	/**
	 * The values for order_by_sql()'s placeholders, in the same order.
	 *
	 * @return array<int, string>
	 */
	private function order_by_params(): array {
		return explode( '.', $this->order_by );
	}
}
