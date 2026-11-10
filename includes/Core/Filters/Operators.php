<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Filters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * THE COMPARISON VOCABULARY. KEYS COME OFF THE WIRE; THE SQL NEVER DOES.
 *
 * This table used to live in `Modules\Reports\Sources`, which is where it was born and where its
 * security rationale is written out in full. It moved here unchanged the day list filters needed
 * the same vocabulary, because Core cannot depend on a module and duplicating an operator table is
 * how two halves of a product start disagreeing about what "contains" means. `Sources::OPERATORS`
 * is now an alias for `self::ALL`, so `ReportEngine` reads exactly what it always read.
 *
 * `args` is how many values the operator binds — 0 for IS NULL, 2 for BETWEEN, -1 for a list.
 * Getting that wrong is how you end up with a placeholder that has no value, and `prepare()` fails
 * closed rather than guessing, which is the correct end of the two.
 */
final class Operators {

	/**
	 * Every operator the product knows how to compile.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const ALL = array(
		'eq'        => array( 'sql' => '= %s', 'args' => 1 ),
		'ne'        => array( 'sql' => '<> %s', 'args' => 1 ),
		'gt'        => array( 'sql' => '> %s', 'args' => 1 ),
		'gte'       => array( 'sql' => '>= %s', 'args' => 1 ),
		'lt'        => array( 'sql' => '< %s', 'args' => 1 ),
		'lte'       => array( 'sql' => '<= %s', 'args' => 1 ),
		'between'   => array( 'sql' => 'BETWEEN %s AND %s', 'args' => 2 ),
		'in'        => array( 'sql' => 'IN', 'args' => -1 ),
		'contains'  => array( 'sql' => 'LIKE %s', 'args' => 1, 'wrap' => '%%%s%%' ),
		'empty'     => array( 'sql' => 'IS NULL', 'args' => 0 ),
		'not_empty' => array( 'sql' => 'IS NOT NULL', 'args' => 0 ),
	);

	/**
	 * The filter types a field descriptor may declare.
	 *
	 * Deliberately the same short vocabulary `Reports\Sources` uses, plus `entity` and `bool`,
	 * because a list filters on things a report does not aggregate over.
	 */
	public const TYPES = array( 'text', 'select', 'user', 'entity', 'number', 'date', 'bool' );

	/**
	 * WHAT A COLLAPSED FILTER ROW USES — the operator most people will ever apply.
	 *
	 * A filter row renders the natural control for its type and hides the operator behind a
	 * chevron, so this table is not a default in the "reasonable starting point" sense. It is the
	 * behaviour, for the overwhelming majority of filters anyone sets. The dropdown in BY_TYPE is
	 * the escape hatch.
	 *
	 * `contains` for text, because someone typing "acme" into a filter box means "find Acme Corp",
	 * not "find a record whose name is exactly acme". `in` for the pickers, because a multi-select
	 * reads as "any of these" without being told. `between` for numbers and dates, because it
	 * renders as two boxes and the client sends `gte`/`lte` when only one is filled — which is what
	 * keeps "created after March" a single-box interaction with no operator change.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULT_BY_TYPE = array(
		'text'   => 'contains',
		'select' => 'in',
		'user'   => 'in',
		'entity' => 'in',
		'number' => 'between',
		'date'   => 'between',
		'bool'   => 'eq',
	);

	/**
	 * What the chevron reveals, per type.
	 *
	 * Kept short on purpose. EspoCRM offers twelve operators for a varchar
	 * (`startsWith endsWith like notLike anyOf noneOf …`) and the result is a dropdown you read
	 * past rather than choose from. Every entry here has to earn a place someone will scan.
	 *
	 * There is no `not_in` ("none of"), and that is a decision rather than an omission: SQL's
	 * `NOT IN` excludes NULL rows, so "stage is none of (Lost)" would silently drop every record
	 * with no stage set — which is never what the question meant. Adding it properly means
	 * emitting `NOT IN (…) OR IS NULL`, which is a compiler special case, not a table row.
	 *
	 * @var array<string, string[]>
	 */
	public const BY_TYPE = array(
		'text'   => array( 'contains', 'eq', 'ne', 'empty', 'not_empty' ),
		'select' => array( 'in', 'ne', 'empty', 'not_empty' ),
		'user'   => array( 'in', 'ne', 'empty', 'not_empty' ),
		'entity' => array( 'in', 'ne', 'empty', 'not_empty' ),
		'number' => array( 'between', 'gte', 'gt', 'lte', 'lt', 'eq', 'ne', 'empty', 'not_empty' ),
		'date'   => array( 'between', 'gte', 'gt', 'lte', 'lt', 'eq', 'empty', 'not_empty' ),
		'bool'   => array( 'eq' ),
	);

	/**
	 * Whether an operator key is one this product compiles.
	 *
	 * @param string $operator Key off the wire.
	 */
	public static function known( string $operator ): bool {
		return isset( self::ALL[ $operator ] );
	}

	/**
	 * The operators a field of this type may use. Unknown type => nothing is allowed.
	 *
	 * @param string $type Filter type.
	 *
	 * @return string[]
	 */
	public static function allowed_for( string $type ): array {
		return self::BY_TYPE[ $type ] ?? array();
	}

	/**
	 * The operator a collapsed row for this type opens on.
	 *
	 * @param string $type Filter type.
	 */
	public static function default_for( string $type ): string {
		return self::DEFAULT_BY_TYPE[ $type ] ?? 'eq';
	}

	/**
	 * Whether this type may use this operator.
	 *
	 * Checked per FIELD, not just globally: `contains` is a real operator and is meaningless on a
	 * date, and a compiler that only asked `known()` would happily emit `t.close_date LIKE '%x%'`.
	 *
	 * @param string $type     Filter type.
	 * @param string $operator Operator key.
	 */
	public static function allowed( string $type, string $operator ): bool {
		return in_array( $operator, self::allowed_for( $type ), true );
	}
}
