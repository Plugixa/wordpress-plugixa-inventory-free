<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Services;

use PlugixaInventory\Core\Database\QueryBuilder;
use PlugixaInventory\Core\Database\Schema as Db;
use PlugixaInventory\Core\WallClock;
use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use PlugixaInventory\Modules\Stock\Repositories\ItemStockRepository;
use PlugixaInventory\Modules\Stock\Repositories\TransactionRepository;
use PlugixaInventory\Modules\Stock\Services\Costing\CostingStrategy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every aggregate question this plugin can answer about stock.
 *
 * WHY THIS MODULE RESOLVES OTHER MODULES' REPOSITORIES, which looks like a violation and is not.
 *
 * The architecture's rule is "reach another module through its SERVICE, never its repository", and
 * it is about WRITES: a repository has no validation, so writing through one skips every rule the
 * service enforces. Reads are the opposite case — `Repository::query()` is the sanctioned read path,
 * which is exactly why `RestController::paged_list()` takes a `Repository` rather than a service.
 *
 * AND USING IT IS THE WHOLE POINT HERE. Every query below goes through `QueryBuilder`, which fires
 * `plugixa_inventory_query_clauses` — the choke point where the premium Roles module narrows what a
 * user may see. Hand-rolled `$wpdb` would be shorter and would silently opt every report out of
 * record-level access control. An aggregate is the perfect leak: it launders rows a user may not
 * read into a total they may, with nothing to trace it back to.
 *
 * REPORTS IS ALSO THE RIGHT PLACE FOR CROSS-TABLE WORK. It is the one module whose job is to read
 * across everything; nothing depends on it, and it depends on almost everything. Pushing valuation
 * into Items would mean Items learning that stock exists, which is precisely the coupling the
 * module map avoids.
 */
final class InventoryReports {

	/** How many rows a grouped report returns before it is a data export rather than a report. */
	private const MAX_GROUPS = 500;

	/**
	 * How far back the ageing walk reads receipts.
	 *
	 * Higher than MAX_GROUPS because these are not report rows — they are the working material the
	 * attribution consumes, and running out of them mid-walk leaves stock unattributed. Bounded all
	 * the same: `QueryBuilder::aggregate()` caps at 5000 regardless, and this states the intent at
	 * the call site rather than discovering the ceiling by hitting it.
	 */
	private const MAX_RECEIPT_DAYS = 5000;

	/**
	 * The movement types the trend chart draws as their own series, in fixed order.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * FIXED, AND NOT CHOSEN BY VOLUME. It would be easy to chart "the four busiest types in the
	 * window", and it is the one thing this must not do: changing the date range would then repaint
	 * the surviving series, so the blue line means `check_in` in one window and `add_stock` in the
	 * next. Colour follows the entity, never its rank.
	 *
	 * These four are `StockMovementService::TYPES` — the vocabulary the Stock Movements screen
	 * writes. They are repeated here rather than imported for the reason `cogs()` names its own
	 * types inline: Reports reads the ledger, and the ledger's `transaction_type` column is the
	 * contract, not any one module's constant. A fifth series, `other`, carries everything else the
	 * ledger holds — transfers and adjustments — so the series sum to the movement count rather
	 * than to a subset of it.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 */
	private const TREND_TYPES = array( 'add_stock', 'check_in', 'check_out', 'remove_stock' );

	/**
	 * The ageing report's buckets, in order: label, and the inclusive upper bound in days.
	 *
	 * `null` is the open-ended last one. Declared as data rather than as a chain of `elseif`s
	 * because the chart, the CSV and the bucketing all have to agree on the same four labels, and
	 * three copies of a boundary is three chances for "61-90" to mean two different things.
	 *
	 * @var array<int, array{key: string, until: int|null}>
	 */
	private const AGE_BUCKETS = array(
		array( 'key' => '0-30', 'until' => 30 ),
		array( 'key' => '31-60', 'until' => 60 ),
		array( 'key' => '61-90', 'until' => 90 ),
		array( 'key' => '90+', 'until' => null ),
	);

	public function __construct(
		private readonly ItemRepository $items,
		private readonly TransactionRepository $ledger,
		private readonly CostingStrategy $costing
	) {}

	/**
	 * What ONE UNIT of an item is taken to have cost, when nothing more precise is known.
	 *
	 * `cost_price` FIRST, `unit_price` SECOND — the same chain, and the same reasoning, as
	 * `LayerCosting::default_cost()`. They are different numbers with different jobs, and valuing
	 * stock at the selling price overstates inventory by the whole gross margin. Both columns are
	 * `decimal(18,4) NOT NULL default 0.0000` (`Items\Schema`), never NULL, so zero is what "no cost
	 * recorded" looks like and NULLIF is the probe for it.
	 *
	 * THE FALLBACK IS WHAT MAKES THE UPGRADE SAFE. A site that only ever filled in Sale price sees
	 * exactly the figure it saw before; the number moves only where a cost was actually recorded,
	 * which is to say only where it was wrong.
	 *
	 * PER UNIT RATHER THAN PER ROW, so the ageing report can use the identical expression. Valuation
	 * multiplies it by `s.quantity`; `bucket_ages()` needs the unit figure to apportion stock across
	 * receipt dates. Sharing the constant is what stops the two screens drifting apart — the
	 * docblock on `bucket_ages()` explains why they must not.
	 */
	private const UNIT_COST = 'COALESCE( NULLIF( t.cost_price, 0 ), t.unit_price )';

	/**
	 * The SQL that values stock, as an expression over the joined tables.
	 *
	 * ONE DEFINITION, USED BY EVERY VALUATION REPORT. "What is stock worth" has to give the same
	 * answer on the dashboard, in the by-category report and in the by-warehouse report, or the
	 * three disagree and nobody can tell which is right.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * IT DOES NOT ASK WHETHER LAYERS ARE ACTIVE, AND THAT IS THE POINT.
	 *
	 * This method used to branch on `costing->active()` and hard-code a different formula either
	 * side — layers-then-unit-price for one, unit-price alone for the other. Two bases behind one
	 * label, chosen by a premium check written into free code, which is how the free and premium
	 * builds came to disagree about what "stock value" meant. On a FIFO site it put both of them in
	 * the same total: consumables at cost, assets at retail, no label to tell them apart.
	 *
	 * The basis is defined here, once, and handed to the strategy. A strategy may sharpen it with
	 * costs it actually holds and cannot replace it, so buying the premium build makes the number
	 * more precise without changing what it means.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 */
	private function value_expression(): string {
		/*
		 * THE COALESCE INSIDE `valuation_value()` GOES INSIDE THIS SUM, NOT AROUND IT, AND THE
		 * DIFFERENCE IS SILENT.
		 *
		 * `COALESCE( lv.layer_value, SUM( … ) )` reads naturally and is wrong: `lv.layer_value` is
		 * a per-row column inside a grouped query, so MySQL picks one arbitrary row's value for the
		 * whole group. A category of forty items would be valued at whichever of them the optimiser
		 * happened to reach first — a number that is plausible, stable between runs, and nowhere
		 * near right.
		 *
		 * Summing per row instead asks the question at the grain both the basis and the layer sum
		 * are defined at — one (item, warehouse) pair — and then adds them up. Hence SUM() is
		 * applied HERE, to whatever the strategy returned, and never inside the strategy.
		 */
		return 'SUM( ' . $this->costing->valuation_value( 's.quantity * ' . self::UNIT_COST ) . ' )';
	}

	/**
	 * The join from items to their stock rows.
	 *
	 * A LEFT JOIN, NOT AN INNER ONE, and the difference is a report that lies. An item with no stock
	 * row has never been received; INNER would drop it, and "we hold 40 items" would silently mean
	 * "40 items we have at some point received". The category totals would still add up, which is
	 * what makes it hard to notice.
	 */
	private function stock_join(): string {
		return 'LEFT JOIN ' . Db::table( 'item_stock' ) . ' s ON s.item_id = t.id';
	}

	/**
	 * An items query with everything a VALUATION needs joined on.
	 *
	 * THE LAYER JOIN COMES FROM THE COSTING STRATEGY AND IS ADDED SECOND, which is not arbitrary:
	 * it joins to `s`, the `item_stock` alias, because layer value is per (item, warehouse) exactly
	 * as stock is. A join cannot reference an alias that has not been introduced yet.
	 *
	 * Reports that count or age stock use `stock_join()` on its own. Only the ones that put a
	 * currency figure on the screen need this, and giving them a named helper is what stops the
	 * next valuation report from being written without the join and quietly falling back to unit
	 * price on a FIFO site.
	 */
	private function valuation_query(): QueryBuilder {
		$query = $this->items->query( 'stats' )->join( $this->stock_join() );

		$layers = $this->costing->valuation_join();

		if ( null !== $layers ) {
			$query->join( $layers );
		}

		return $query;
	}

	/**
	 * What stock is worth, grouped by category.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function value_by_category(): array {
		$rows = $this->valuation_query()
			->join( 'LEFT JOIN ' . Db::table( 'categories' ) . ' c ON c.id = t.category_id' )
			->where( "t.status = 'published'", array() )
			->group_by( array( 'category_id' => 't.category_id', 'category_name' => 'c.name' ) )
			->aggregate(
				array(
					'item_count'     => 'COUNT( DISTINCT t.id )',
					'total_quantity' => 'COALESCE( SUM( s.quantity ), 0 )',
					'total_value'    => 'COALESCE( ' . $this->value_expression() . ', 0 )',
				),
				self::MAX_GROUPS
			);

		return $this->cast_groups( $rows, array( 'category_id' ) );
	}

	/**
	 * What stock is worth, grouped by the warehouse holding it.
	 *
	 * THE REPORT THE COMPONENT THIS PORTS CANNOT PRODUCE. There an item has one home warehouse and
	 * one quantity, so "stock by warehouse" is really "stock by the warehouse somebody typed on the
	 * item" — and a transfer never changes it. Here the grouping is on `item_stock.warehouse_id`,
	 * which is where the stock actually is.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function value_by_warehouse(): array {
		$rows = $this->valuation_query()
			->join( 'LEFT JOIN ' . Db::table( 'warehouses' ) . ' w ON w.id = s.warehouse_id' )
			->where( "t.status = 'published'", array() )
			// Rows with no stock row have no warehouse to attribute to. Unlike the category report,
			// dropping them here is correct: "what is in each warehouse" is a question about stock
			// that exists.
			->where( 's.warehouse_id IS NOT NULL', array() )
			->group_by( array( 'warehouse_id' => 's.warehouse_id', 'warehouse_name' => 'w.name' ) )
			->aggregate(
				array(
					'item_count'     => 'COUNT( DISTINCT t.id )',
					'total_quantity' => 'COALESCE( SUM( s.quantity ), 0 )',
					'total_value'    => 'COALESCE( ' . $this->value_expression() . ', 0 )',
				),
				self::MAX_GROUPS
			);

		return $this->cast_groups( $rows, array( 'warehouse_id' ) );
	}

	/**
	 * What stock is worth, grouped by what kind of thing it is.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function value_by_type(): array {
		$rows = $this->valuation_query()
			->where( "t.status = 'published'", array() )
			->group_by( array( 'item_type' => 't.item_type' ) )
			->aggregate(
				array(
					'item_count'     => 'COUNT( DISTINCT t.id )',
					'total_quantity' => 'COALESCE( SUM( s.quantity ), 0 )',
					'total_value'    => 'COALESCE( ' . $this->value_expression() . ', 0 )',
					'avg_unit_price' => 'COALESCE( AVG( t.unit_price ), 0 )',
				),
				self::MAX_GROUPS
			);

		return $this->cast_groups( $rows, array() );
	}

	/**
	 * The headline numbers: what is here, what it is worth, what is short.
	 *
	 * @return array<string, mixed>
	 */
	public function totals(): array {
		$by_type = $this->value_by_type();

		$items = 0;
		$value = 0.0;

		foreach ( $by_type as $row ) {
			$items += (int) $row['item_count'];
			$value += (float) $row['total_value'];
		}

		return array(
			'total_items'     => $items,
			'total_value'     => round( $value, 2 ),
			'low_stock_count' => $this->low_stock_count(),
			'costing_method'  => $this->costing->active() ? 'layers' : 'standard',
		);
	}

	/**
	 * How many items are at or below their reorder level.
	 *
	 * `reorder_level > 0` is part of the definition, not an optimisation: zero means "no level set",
	 * and without it every item that has never been received counts as low.
	 */
	public function low_stock_count(): int {
		return $this->items->query( 'count' )
			->where( "t.status = 'published'", array() )
			->where( 't.reorder_level > 0', array() )
			->where( 't.stock_quantity <= t.reorder_level', array() )
			->count();
	}

	/**
	 * Movement per day and type, for the trend chart.
	 *
	 * DATE( occurred_at ) RATHER THAN A RANGE PER DAY, because a report that spans a month should be
	 * one query rather than thirty. The zero-filling of missing days happens in PHP — a day with no
	 * movement has no row, and a chart that simply skips it draws a straight line between the days
	 * either side, which reads as steady activity rather than none.
	 *
	 * `occurred_at` AND NOT `created_at`, and the difference is weeks rather than hours. Every
	 * windowed query in this file used to group by when the ROW WAS WRITTEN, so a month of trading
	 * entered in one afternoon drew as a single spike on that afternoon, and the ageing report put
	 * seven-week-old stock in the "0-30 days" bucket. See `StockLine::$occurred_at`.
	 *
	 * @param int $days How far back to look.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function movement_by_day( int $days = 30, ?string $to = null ): array {
		/*
		 * THE DAYS ON THIS AXIS ARE THE READER'S DAYS, NOT UTC'S.
		 *
		 * `t.occurred_at` is UTC, so `DATE( t.occurred_at )` cut each bucket at UTC midnight — 9am in
		 * Tokyo, 1am in Paris. On any site that is not UTC the chart attributed a slice of every
		 * day's movements to the day before, every day, and the dev site being UTC is why nothing
		 * ever looked wrong. {@see \PlugixaInventory\Core\WallClock::utc_offset()} for why the shift
		 * is a bound integer of seconds rather than CONVERT_TZ.
		 *
		 * THE SQL BUCKETS AND THE PHP AXIS BELOW MUST USE THE SAME CLOCK. They did before — both
		 * UTC, both wrong together, which at least produced a continuous chart. Shifting only one of
		 * them would leave the labels naming days the buckets do not contain, and the symptom would
		 * be missing bars rather than misplaced ones. Both read $offset.
		 */
		$days   = max( 1, $days );
		$offset = WallClock::utc_offset();

		/*
		 * The window starts at LOCAL midnight, expressed as the UTC instant the column holds.
		 * `time() + $offset` is the site's wall clock as a timestamp; flooring that to a day with
		 * gmdate() gives local midnight in local terms; subtracting the offset again turns it back
		 * into the instant. Doing it in one step — "N days ago, minus the offset" — would land at
		 * whatever time of day the request happened to run, and the first bucket would be a partial
		 * day whose height depended on when the page was opened.
		 */
		$local_now = self::local_end( $to );
		$since     = self::window_start( $days, $to );
		$until     = self::window_until( $to );

		$query = $this->ledger->query( 'stats' )
			->where( 't.occurred_at >= %s', array( $since ) );

		if ( null !== $until ) {
			$query->where( 't.occurred_at < %s', array( $until ) );
		}

		/*
		 * The per-type counts are built here rather than written out four times, so adding a type
		 * to TREND_TYPES cannot leave the SQL and the zero-fill below disagreeing about which
		 * series exist. Every value interpolated is a literal from that constant — no request data
		 * reaches this string.
		 */
		$aggregates = array(
			// ABS on each side rather than SUM of the signed column: a day with +100 and
			// -100 is a busy day, and a signed sum would report it as nothing happening.
			'stock_in'  => 'COALESCE( SUM( CASE WHEN t.quantity_change > 0 THEN t.quantity_change ELSE 0 END ), 0 )',
			'stock_out' => 'COALESCE( SUM( CASE WHEN t.quantity_change < 0 THEN -t.quantity_change ELSE 0 END ), 0 )',
			'movements' => 'COUNT( * )',
		);

		foreach ( self::TREND_TYPES as $type ) {
			$aggregates[ $type ] = sprintf(
				"COALESCE( SUM( CASE WHEN t.transaction_type = '%s' THEN 1 ELSE 0 END ), 0 )",
				$type
			);
		}

		/*
		 * THE RESIDUAL IS COUNTED, NOT DROPPED. Transfers and adjustments are ledger rows too, and
		 * a chart whose series stop short of `movements` would disagree with the total printed
		 * directly beneath it — which reads as a bug in whichever number the eye lands on second.
		 */
		$aggregates['other'] = sprintf(
			"COALESCE( SUM( CASE WHEN t.transaction_type NOT IN ( '%s' ) THEN 1 ELSE 0 END ), 0 )",
			implode( "', '", self::TREND_TYPES )
		);

		$rows = $query
			->group_by( array( 'day' => 'DATE( t.occurred_at + INTERVAL %d SECOND )' ), array( $offset ) )
			->aggregate( $aggregates, self::MAX_GROUPS );

		/** The shape a day with no ledger rows at all still has to have. */
		$empty = array( 'stock_in' => 0.0, 'stock_out' => 0.0, 'movements' => 0 );

		foreach ( array_merge( self::TREND_TYPES, array( 'other' ) ) as $type ) {
			$empty[ $type ] = 0;
		}

		$byDay = array();

		foreach ( $rows as $row ) {
			$day = array(
				'stock_in'  => (float) $row['stock_in'],
				'stock_out' => (float) $row['stock_out'],
				'movements' => (int) $row['movements'],
			);

			foreach ( array_merge( self::TREND_TYPES, array( 'other' ) ) as $type ) {
				$day[ $type ] = (int) ( $row[ $type ] ?? 0 );
			}

			$byDay[ (string) $row['day'] ] = $day;
		}

		$series = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			// The site's day, matching the buckets above. `gmdate` on a shifted timestamp rather
			// than `date`, so the answer does not also depend on PHP's own timezone setting.
			$day = gmdate( 'Y-m-d', $local_now - ( $i * DAY_IN_SECONDS ) );

			$series[] = array_merge( array( 'day' => $day ), $byDay[ $day ] ?? $empty );
		}

		return $series;
	}

	/**
	 * The items that moved most in a window.
	 *
	 * `total_movement` is the sum of the ABSOLUTE change and `net_change` is the signed one, and
	 * both are needed: an item received 500 and issued 500 has a net of zero and is the busiest
	 * thing in the warehouse.
	 *
	 * @param int $days  Window.
	 * @param int $limit How many.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function top_movers( int $days = 7, int $limit = 5, ?string $to = null ): array {
		$since = self::window_start( max( 1, $days ), $to );
		$until = self::window_until( $to );

		$query = $this->ledger->query( 'stats' )
			->join( 'LEFT JOIN ' . Db::table( 'items' ) . ' i ON i.id = t.item_id' )
			->where( 't.occurred_at >= %s', array( $since ) );

		if ( null !== $until ) {
			$query->where( 't.occurred_at < %s', array( $until ) );
		}

		$rows = $query
			->group_by( array( 'item_id' => 't.item_id', 'item_name' => 'i.name' ) )
			->order_by_aggregate( 'total_movement', 'DESC' )
			->aggregate(
				array(
					'total_movement' => 'SUM( ABS( t.quantity_change ) )',
					'net_change'     => 'SUM( t.quantity_change )',
					'movements'      => 'COUNT( * )',
				),
				// Ordered and cut in SQL, so the limit is the limit rather than a slice of a
				// 500-row page taken after the fact. That distinction does not matter for "top 5"
				// — the busiest item is in any 500 — but it is the whole answer for the worst
				// performers, so both reports get it the same way.
				max( 1, $limit )
			);

		return array_map(
			static fn( array $row ): array => array(
				'item_id'        => (int) $row['item_id'],
				'item_name'      => (string) $row['item_name'],
				'total_movement' => (float) $row['total_movement'],
				'net_change'     => (float) $row['net_change'],
				'movements'      => (int) $row['movements'],
				'direction'      => (float) $row['net_change'] >= 0 ? 'up' : 'down',
			),
			$rows
		);
	}

	/**
	 * What stock leaving cost, per day and type.
	 *
	 * ONLY ROWS WITH A COST, and only the types that represent stock actually consumed. A transfer
	 * carries a cost on its outward half so the ledger reads sensibly, but transferring stock
	 * between two of your own warehouses is not a cost of goods sold — counting it would inflate
	 * COGS by every internal movement.
	 *
	 * @param int $days Window.
	 *
	 * @return array<string, mixed>
	 */
	public function cogs( int $days = 30, ?string $to = null ): array {
		// The site's days, for the reason movement_by_day() sets out at length.
		$offset = WallClock::utc_offset();
		$since  = self::window_start( max( 1, $days ), $to );
		$until  = self::window_until( $to );

		$query = $this->ledger->query( 'stats' )
			->where( 't.occurred_at >= %s', array( $since ) );

		if ( null !== $until ) {
			$query->where( 't.occurred_at < %s', array( $until ) );
		}

		$rows = $query
			->where( 't.quantity_change < 0', array() )
			->where( "t.transaction_type NOT IN ( 'transfer_out', 'transfer_in' )", array() )
			->where( 't.total_cost > 0', array() )
			->group_by(
				array(
					'day'              => 'DATE( t.occurred_at + INTERVAL %d SECOND )',
					'transaction_type' => 't.transaction_type',
				),
				array( $offset )
			)
			->aggregate(
				array(
					'movements'  => 'COUNT( * )',
					'units'      => 'SUM( ABS( t.quantity_change ) )',
					'total_cogs' => 'SUM( t.total_cost )',
				),
				self::MAX_GROUPS
			);

		$by_date = array();
		$units   = 0.0;
		$cost    = 0.0;
		$count   = 0;

		foreach ( $rows as $row ) {
			$by_date[] = array(
				'day'              => (string) $row['day'],
				'transaction_type' => (string) $row['transaction_type'],
				'movements'        => (int) $row['movements'],
				'units'            => (float) $row['units'],
				'total_cogs'       => round( (float) $row['total_cogs'], 2 ),
			);

			$units += (float) $row['units'];
			$cost  += (float) $row['total_cogs'];
			$count += (int) $row['movements'];
		}

		return array(
			'by_date' => $by_date,
			'totals'  => array(
				'movements'         => $count,
				'units'             => round( $units, 4 ),
				'total_cogs'        => round( $cost, 2 ),
				// Guarded: a window with no consumption divides by zero, and "cost per unit of
				// nothing" is not a number worth inventing.
				'avg_cost_per_unit' => $units > 0 ? round( $cost / $units, 4 ) : 0.0,
			),
		);
	}

	/**
	 * This period against the one before it.
	 *
	 * THE COMPARISON IS THE REPORT. "412 movements" is a number; "412, up 18% on the previous
	 * thirty days" is information. Both windows are the same length so the comparison is fair.
	 *
	 * @param int $days Window length.
	 *
	 * @return array<string, mixed>
	 */
	public function kpi_trends( int $days = 30, ?string $to = null ): array {
		$now      = $this->window_totals( $days, 0, $to );
		/*
		 * THE COMPARISON WINDOW SHIFTS WITH THE CHOSEN ONE. "Against the previous period" has to
		 * mean the 30 days before the 30 being shown -- not the 30 before today. Comparing a range
		 * in August against last month would put a number on the tile that is arithmetically
		 * correct and answers a question nobody asked.
		 */
		$previous = $this->window_totals( $days, $days, $to );

		$end = self::local_end( $to );

		return array(
			'period'   => array(
				'days' => $days,
				'from' => gmdate( 'Y-m-d', $end - ( $days * DAY_IN_SECONDS ) ),
				'to'   => gmdate( 'Y-m-d', $end ),
			),
			'current'  => $now,
			'previous' => $previous,
			'changes'  => array(
				'movements'   => $this->percent_change( (float) $previous['movements'], (float) $now['movements'] ),
				'units_moved' => $this->percent_change( $previous['units_moved'], $now['units_moved'] ),
				'units_in'    => $this->percent_change( $previous['units_in'], $now['units_in'] ),
				'units_out'   => $this->percent_change( $previous['units_out'], $now['units_out'] ),
			),
		);
	}

	/**
	 * Everything at or below its reorder level, shortest first, with the names a buyer needs.
	 *
	 * WHY THIS EXISTS ALONGSIDE `ItemRepository::low_stock()`, which answers nearly the same
	 * question. That one feeds the dashboard widget and the `/items/low-stock` route: bare item
	 * rows, ten of them, rendered next to a link. This one is a REPORT — it carries the category
	 * and the warehouse, the shortfall, and the count of everything beyond the page, because the
	 * person reading it is deciding what to order rather than glancing at a card.
	 *
	 * Duplicating the three WHERE clauses is the deliberate half of that. They encode a definition
	 * — `reorder_level > 0` means "a level was actually set" — and the two call sites are free to
	 * diverge on presentation while agreeing on it. `low_stock_count()` below shares the same three.
	 *
	 * @param int $limit How many rows to return. QueryBuilder caps pages at 200.
	 *
	 * @return array<string, mixed>
	 */
	public function low_stock_summary( int $limit = 100 ): array {
		$rows = $this->items->query( 'report' )
			->select(
				't.id',
				't.name',
				't.sku',
				't.item_type',
				't.stock_quantity',
				't.reorder_level',
				't.unit_price',
				'c.name AS category_name',
				'w.name AS warehouse_name'
			)
			->join( 'LEFT JOIN ' . Db::table( 'categories' ) . ' c ON c.id = t.category_id' )
			->join( 'LEFT JOIN ' . Db::table( 'warehouses' ) . ' w ON w.id = t.default_warehouse_id' )
			->where( "t.status = 'published'", array() )
			->where( 't.reorder_level > 0', array() )
			->where( 't.stock_quantity <= t.reorder_level', array() )
			->order_by( 't.stock_quantity', 'ASC' )
			->paginate( max( 1, $limit ), 0 )
			->get();

		$items = array_map(
			static function ( array $row ): array {
				$on_hand = (float) $row['stock_quantity'];
				$level   = (float) $row['reorder_level'];

				return array(
					'id'             => (int) $row['id'],
					'name'           => (string) $row['name'],
					'sku'            => (string) ( $row['sku'] ?? '' ),
					'item_type'      => (string) $row['item_type'],
					'category_name'  => $row['category_name'] ?? null,
					'warehouse_name' => $row['warehouse_name'] ?? null,
					'stock_quantity' => round( $on_hand, 4 ),
					'reorder_level'  => round( $level, 4 ),
					// How much to buy to get back to the line, which is the number the report is
					// actually for. Floored at zero: the `<=` in the WHERE means an item sitting
					// exactly ON its level is listed, and "order -0" is not a shortfall.
					'shortfall'      => round( max( 0.0, $level - $on_hand ), 4 ),
					'unit_price'     => round( (float) $row['unit_price'], 4 ),
					// Out of stock is a different urgency from low, and the screen colours it so.
					'out_of_stock'   => $on_hand <= 0,
				);
			},
			$rows
		);

		return array(
			'items' => $items,
			// The page is capped; the count is not. A report that lists 100 of 340 shortages and
			// says nothing about the other 240 reads as a complete list.
			'total' => $this->low_stock_count(),
		);
	}

	/**
	 * The items behind one category's total.
	 *
	 * IT MUST ADD UP TO THE ROW IT WAS OPENED FROM, and that is the only reason this is an
	 * `aggregate()` over the stock join rather than the obvious `t.stock_quantity * t.unit_price`
	 * per item. `items.stock_quantity` is a CACHE that `set_cached_stock()` maintains; `item_stock`
	 * is the truth. They agree until they do not, and the day they drift, a drill-down built on the
	 * cache shows eleven items whose values sum to something other than the category total the user
	 * clicked — with both numbers looking equally authoritative and nothing on screen to say which
	 * to believe. Deriving both from the same expression makes that failure impossible rather than
	 * unlikely.
	 *
	 * @param int $category_id The category drilled into.
	 * @param int $limit       Items to return.
	 *
	 * @return array<string, mixed>
	 */
	public function category_drill_down( int $category_id, int $limit = self::MAX_GROUPS ): array {
		$rows = $this->valuation_query()
			->join( 'LEFT JOIN ' . Db::table( 'categories' ) . ' c ON c.id = t.category_id' )
			->where( "t.status = 'published'", array() )
			->where( 't.category_id = %d', array( $category_id ) )
			->group_by(
				array(
					'item_id'       => 't.id',
					'item_name'     => 't.name',
					'sku'           => 't.sku',
					'item_type'     => 't.item_type',
					'category_name' => 'c.name',
				)
			)
			->order_by_aggregate( 'total_value', 'DESC' )
			->aggregate(
				array(
					'total_quantity' => 'COALESCE( SUM( s.quantity ), 0 )',
					// THE COST, NOT THE SALE PRICE, because this table's whole job is showing the
					// working behind `total_value`, and quantity × sale price stopped equalling it
					// the day valuation moved to cost. `UNIT_COST` rather than a raw `t.cost_price`
					// for the same reason: an item with no cost recorded would otherwise show 0.00
					// beside a non-zero total, which is the fallback made to look like a bug.
					'unit_cost'      => 'MAX( ' . self::UNIT_COST . ' )',
					'total_value'    => 'COALESCE( ' . $this->value_expression() . ', 0 )',
				),
				$limit
			);

		$items = $this->cast_groups( $rows, array( 'item_id' ) );

		return array(
			'category_id'   => $category_id,
			'category_name' => $items[0]['category_name'] ?? null,
			'items'         => $items,
			'total_value'   => round(
				array_sum( array_map( static fn( array $row ): float => (float) $row['total_value'], $items ) ),
				2
			),
		);
	}

	/**
	 * The items turning over fastest, or slowest.
	 *
	 * TURNOVER IS MOVEMENT OVER STOCK ON HAND, not movement alone — which is what separates this
	 * from `top_movers()` and is the reason both exist. The busiest item in the warehouse is
	 * usually just the one you hold most of; dividing by the holding asks the better question,
	 * "how hard is this stock working", and it is the one that finds the pallet of something that
	 * has not moved since March.
	 *
	 * THE DATE LIVES IN THE JOIN'S `ON`, NOT IN A `where()`, and on the `worst` branch that is the
	 * difference between a right and a wrong report. A LEFT JOIN filtered in WHERE is an INNER
	 * JOIN wearing a disguise: it drops every item with no transaction in the window — which is
	 * precisely the set of items a worst-performers report is looking for. See `QueryBuilder::join()`.
	 *
	 * `MAX( t.stock_quantity )` rather than the column bare: the ledger join fans a row out per
	 * transaction, so anything not aggregated is read off an arbitrary one of them. It is constant
	 * within the group (grouped by `t.id`), so MAX is just the way to say "this item's".
	 *
	 * @param string $type  best|worst.
	 * @param int    $days  Window.
	 * @param int    $limit How many.
	 *
	 * @return array<string, mixed>
	 */
	public function top_performers( string $type = 'best', int $days = 30, int $limit = 10, ?string $to = null ): array {
		$type  = 'worst' === $type ? 'worst' : 'best';
		$since = self::window_start( max( 1, $days ), $to );
		$until = self::window_until( $to );

		$movement = 'COALESCE( SUM( ABS( x.quantity_change ) ), 0 )';

		/*
		 * THE END OF THE WINDOW GOES IN THE `ON` CLAUSE, NOT IN A `where()`, and this is the exact
		 * trap `QueryBuilder::join()` documents: on a LEFT JOIN a predicate in WHERE is evaluated
		 * after the join and deletes the unmatched rows, turning it into an INNER without looking
		 * like it. This report's whole point is the items that did NOT move, so a closing bound in
		 * the wrong clause would silently drop exactly the rows "slowest moving" exists to find.
		 */
		$rows = $this->items->query( 'stats' )
			->join(
				null === $until
					? 'LEFT JOIN ' . Db::table( 'transactions' ) . ' x ON x.item_id = t.id AND x.occurred_at >= %s'
					: 'LEFT JOIN ' . Db::table( 'transactions' ) . ' x ON x.item_id = t.id AND x.occurred_at >= %s AND x.occurred_at < %s',
				null === $until ? array( $since ) : array( $since, $until )
			)
			->join( 'LEFT JOIN ' . Db::table( 'categories' ) . ' c ON c.id = t.category_id' )
			->where( "t.status = 'published'", array() )
			// Turnover of nothing is not slow, it is undefined. An item at zero stock would divide
			// to zero and sit at the top of the worst list for a reason that is not about turnover.
			->where( 't.stock_quantity > 0', array() )
			->group_by(
				array(
					'item_id'       => 't.id',
					'item_name'     => 't.name',
					'sku'           => 't.sku',
					'item_type'     => 't.item_type',
					'category_name' => 'c.name',
				)
			)
			->order_by_aggregate( 'turnover_ratio', 'worst' === $type ? 'ASC' : 'DESC' )
			->aggregate(
				array(
					'stock_quantity' => 'MAX( t.stock_quantity )',
					'unit_price'     => 'MAX( t.unit_price )',
					'total_movement' => $movement,
					// COUNT of the joined column, not COUNT(*): an item with no transaction in the
					// window still produces one row from the LEFT JOIN, and COUNT(*) would call
					// that one movement.
					'movements'      => 'COUNT( x.id )',
					'turnover_ratio' => 'CASE WHEN MAX( t.stock_quantity ) > 0 THEN ' . $movement . ' / MAX( t.stock_quantity ) ELSE 0 END',
				),
				max( 1, $limit )
			);

		return array(
			'type'  => $type,
			'days'  => $days,
			'items' => $this->cast_groups( $rows, array( 'item_id' ) ),
		);
	}

	/**
	 * How long the stock now on hand has been sitting there, in age buckets.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE COMPONENT THIS PORTS ANSWERS THIS OFF THE COST LAYERS, AND THERE ARE NO LAYERS YET.
	 *
	 * `inventory_layers` carries a `received_date` and a `quantity_remaining`, so ageing there is a
	 * read: bucket the layers, sum them. That table arrives in P4 with `LayerCosting`. Until then a
	 * site is on standard costing and holds no layers at all, so the report has to be derived or
	 * it has to not exist.
	 *
	 * IT CAN BE DERIVED, AND THE REASON IS THAT AGE IS NOT A COSTING QUESTION. Which cost you
	 * attach to a unit is a policy choice — FIFO, LIFO, average — but how long a unit has physically
	 * been in the warehouse is a fact the ledger already records. Receipts are in `transactions`
	 * with their dates. Attributing today's on-hand quantity back across those receipts, newest
	 * first, reconstructs exactly what a FIFO layer table would hold, because consuming oldest-first
	 * is what leaves the newest receipts standing.
	 *
	 * So this is not an approximation of the layer report. On a FIFO site it is the same answer by a
	 * slower route — and now that `inventory_layers` exists, the switch below takes the fast one.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * THE TWO BASES SHARE THE ATTRIBUTION WALK, and that is the point of doing it this way rather
	 * than writing a second report. `bucket_ages()` does not know whether the dated quantities it
	 * was handed were reconstructed from receipts or read off the layers; both arrive in the same
	 * shape, newest first, and both get the same treatment for stock the dates cannot account for.
	 * A separate layers-only report would have been a second place for the "what do we do with
	 * unattributed stock" decision to live, and the two would eventually disagree.
	 *
	 * @return array<string, mixed>
	 */
	public function aging(): array {
		$on_hand = $this->on_hand_by_location();
		$layers  = $this->costing->active();
		$dated   = $layers ? $this->with_unlayered_stock( $on_hand, $this->layers_by_day() ) : $this->receipts_by_day();

		return array_merge(
			array(
				/*
				 * WHERE THE NUMBERS CAME FROM, said out loud in the payload so the screen can say it
				 * too. "Derived from the ledger" and "read off the cost layers" are answers of
				 * different confidence, and a report that renders them identically invites somebody
				 * to act on the weaker one as though it were the stronger.
				 */
				'basis'          => $layers ? 'layers' : 'ledger',
				'total_quantity' => round(
					array_sum( array_map( static fn( array $row ): float => (float) $row['quantity'], $on_hand ) ),
					4
				),
			),
			$this->bucket_ages( $on_hand, $dated, $layers )
		);
	}

	/**
	 * Under layer costing, give stock that has no live layers its ledger dates.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * TWO KINDS OF STOCK REACH THE LAYER BRANCH WITH NO LAYERS, AND BOTH USED TO AGE AS "90+".
	 *
	 * Assets, which are never layered because an asset is lent rather than sold — so on a site
	 * costing by layers, EVERY asset sat in the oldest bucket for ever, as unattributed. And a pair
	 * whose opening balance has not been opened yet, which after a switch is every pair for as long
	 * as the background seed takes. Age is not a costing question (see `aging()`): the ledger knows
	 * when that stock arrived regardless of whether layers exist.
	 *
	 * `unit_cost` IS FORCED TO ZERO on the borrowed receipts, which makes `bucket_ages()` value
	 * them at the item's cost basis — exactly the fallback arm valuation uses for unlayered stock,
	 * so the ageing total still reconciles with the valuation total on the same site.
	 *
	 * The ledger is only read when some on-hand pair is actually missing from the layers.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param array<int, array<string, mixed>>                $on_hand One row per item per warehouse.
	 * @param array<string, array<int, array<string, mixed>>> $layered What the layers dated.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function with_unlayered_stock( array $on_hand, array $layered ): array {
		$missing = array();

		foreach ( $on_hand as $line ) {
			$key = (int) $line['item_id'] . ':' . (int) $line['warehouse_id'];

			if ( ! isset( $layered[ $key ] ) ) {
				$missing[ $key ] = true;
			}
		}

		if ( array() === $missing ) {
			return $layered;
		}

		foreach ( $this->receipts_by_day() as $key => $days ) {
			if ( ! isset( $missing[ $key ] ) ) {
				continue;
			}

			$layered[ $key ] = array_map(
				static fn( array $day ): array => array_merge( $day, array( 'unit_cost' => 0.0 ) ),
				$days
			);
		}

		return $layered;
	}

	/**
	 * What is physically on hand, one row per item per warehouse.
	 *
	 * Driven from `items` with the stock join rather than from `item_stock` directly, because
	 * `ItemStockRepository` is deliberately not a `Repository` and has no `query()` — so it has no
	 * way through the ACL choke point. Reaching it from the items side means the premium Roles
	 * module scopes this report by scoping items, which is where the rules are written anyway.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function on_hand_by_location(): array {
		$rows = $this->items->query( 'stats' )
			->join( $this->stock_join() )
			->join( 'LEFT JOIN ' . Db::table( 'warehouses' ) . ' w ON w.id = s.warehouse_id' )
			->where( "t.status = 'published'", array() )
			// Zero and negative rows carry no age. `item_stock` keeps a row once an item has ever
			// been in a warehouse, so most of them are zero on a mature site.
			->where( 's.quantity > 0', array() )
			->group_by(
				array(
					'item_id'        => 't.id',
					'item_name'      => 't.name',
					'sku'            => 't.sku',
					'item_type'      => 't.item_type',
					'warehouse_id'   => 's.warehouse_id',
					'warehouse_name' => 'w.name',
				)
			)
			->aggregate(
				array(
					// UNIQUE(item_id, warehouse_id) means this sums exactly one row. The SUM is
					// what makes it legal under ONLY_FULL_GROUP_BY, not an aggregation of anything.
					'quantity'  => 'SUM( s.quantity )',
					// THE SAME EXPRESSION VALUATION USES, so the ageing walk receives the answer
					// rather than re-deriving it. Selecting the two raw columns and applying the
					// fallback in PHP would work and would be one edit away from disagreeing with
					// the valuation screen — which is the failure `bucket_ages()` is written to
					// avoid. MAX() over a single grouped row, exactly as SUM() is above.
					'unit_cost' => 'MAX( ' . self::UNIT_COST . ' )',
				),
				self::MAX_GROUPS
			);

		return $this->cast_groups( $rows, array( 'item_id', 'warehouse_id' ) );
	}

	/**
	 * Every receipt, collapsed to one row per item, warehouse and day, newest first.
	 *
	 * COLLAPSED TO A DAY BECAUSE THE BUCKETS ARE DAYS. Ageing asks "0-30, 31-60, 61-90, 90+", so two
	 * receipts of the same item into the same warehouse on the same Tuesday are one fact for this
	 * report's purposes. It also keeps the row count survivable: a busy item receiving hourly
	 * produces one row a day here rather than twenty-four, which is the difference between this
	 * fitting in one query and needing a cursor.
	 *
	 * @param int $limit Receipt-days to consider.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Keyed "item_id:warehouse_id", newest first.
	 */
	private function receipts_by_day( int $limit = self::MAX_RECEIPT_DAYS ): array {
		$rows = $this->ledger->query( 'stats' )
			->where( 't.quantity_change > 0', array() )
			->group_by(
				array(
					'item_id'      => 't.item_id',
					'warehouse_id' => 't.warehouse_id',
					// The site's day; the ageing buckets are counted from it, so a receipt just
					// before local midnight must not be filed a day early. See WallClock::utc_offset().
					'received_on'  => 'DATE( t.occurred_at + INTERVAL %d SECOND )',
				),
				array( WallClock::utc_offset() )
			)
			// Newest first is what the attribution walk consumes, and it is also what makes the cap
			// above degrade sensibly: the rows that fall off the end are the OLDEST, and stock old
			// enough to be cut off is already in the last bucket.
			->order_by_aggregate( 'received_on', 'DESC' )
			->aggregate(
				array(
					'quantity'  => 'SUM( t.quantity_change )',
					// The weighted cost of that day's receipts. `cost_per_unit` on a single row
					// would be one arbitrary receipt's price when several share a day.
					'unit_cost' => 'CASE WHEN SUM( t.quantity_change ) > 0 THEN SUM( t.cost_per_unit * t.quantity_change ) / SUM( t.quantity_change ) ELSE 0 END',
				),
				$limit
			);

		$by_location = array();

		foreach ( $rows as $row ) {
			$key = (int) $row['item_id'] . ':' . (int) $row['warehouse_id'];

			$by_location[ $key ][] = array(
				'received_on' => (string) $row['received_on'],
				'quantity'    => (float) $row['quantity'],
				'unit_cost'   => (float) $row['unit_cost'],
				'age_days'    => self::days_since( (string) $row['received_on'] ),
			);
		}

		return $by_location;
	}

	/**
	 * The open cost layers, collapsed to one row per item, warehouse and day, newest first.
	 *
	 * THE SAME SHAPE `receipts_by_day()` RETURNS, deliberately and exactly — `quantity`,
	 * `unit_cost`, `age_days`, keyed "item:warehouse". `bucket_ages()` then works on either without
	 * knowing which it was given, which is what keeps one attribution walk instead of two.
	 *
	 * DRIVEN FROM `items` RATHER THAN STRAIGHT OFF `inventory_layers`, and this is not a detour.
	 * `LayerRepository` is deliberately not a `Repository` and has no `query()`, so it has no way
	 * through `plugixa_inventory_query_clauses` — the choke point where the premium Roles module
	 * narrows what a user may see. Reaching the layers from the items side means this report is
	 * scoped by scoping items, which is where those rules are written anyway. An aggregate is the
	 * perfect leak: it launders rows a user may not read into a total they may.
	 *
	 * COLLAPSED TO A DAY for the same reason the ledger version is: the buckets are days, so two
	 * layers received the same Tuesday are one fact here.
	 *
	 * @param int $limit Layer-days to consider.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Keyed "item_id:warehouse_id", newest first.
	 */
	private function layers_by_day( int $limit = self::MAX_RECEIPT_DAYS ): array {
		$rows = $this->items->query( 'stats' )
			->join(
				// INNER, not LEFT: an item with no open layers contributes no dated quantity, and a
				// LEFT join would hand the walk a row of NULLs to attribute stock against.
				'INNER JOIN ' . Db::table( 'inventory_layers' ) . ' l ON l.item_id = t.id AND l.quantity_remaining > %f AND l.retired_at IS NULL',
				array( ItemStockRepository::EPSILON )
			)
			->where( "t.status = 'published'", array() )
			->group_by(
				array(
					'item_id'      => 't.id',
					'warehouse_id' => 'l.warehouse_id',
					// `l.received_at` is UTC too (LayerRepository defaults it with
					// current_time( 'mysql', true )), so it needs the same shift as the ledger's
					// own dates — otherwise the two ageing bases disagree by a day near midnight,
					// and the FIFO and average-cost sites would bucket the same stock differently.
					'received_on'  => 'DATE( l.received_at + INTERVAL %d SECOND )',
				),
				array( WallClock::utc_offset() )
			)
			->order_by_aggregate( 'received_on', 'DESC' )
			->aggregate(
				array(
					'quantity'  => 'SUM( l.quantity_remaining )',
					// Weighted by what is LEFT, not by what arrived: two layers from the same day
					// that have been consumed unevenly no longer contribute equally to the cost of
					// the stock still standing.
					'unit_cost' => 'CASE WHEN SUM( l.quantity_remaining ) > 0 THEN SUM( l.cost_per_unit * l.quantity_remaining ) / SUM( l.quantity_remaining ) ELSE 0 END',
				),
				$limit
			);

		$by_location = array();

		foreach ( $rows as $row ) {
			$key = (int) $row['item_id'] . ':' . (int) $row['warehouse_id'];

			$by_location[ $key ][] = array(
				'received_on' => (string) $row['received_on'],
				'quantity'    => (float) $row['quantity'],
				'unit_cost'   => (float) $row['unit_cost'],
				'age_days'    => self::days_since( (string) $row['received_on'] ),
			);
		}

		return $by_location;
	}

	/**
	 * The UTC instant at which the site's day, N days ago, began.
	 *
	 * EVERY "LAST N DAYS" WINDOW IN THIS CLASS STARTS HERE, and they all have to, now that the
	 * per-day buckets are cut at local midnight. A window that still began at UTC midnight would
	 * include or exclude up to a whole offset's worth of movements that the chart drawn from the
	 * same call does not show — the totals and the bars would disagree, on the same screen, by an
	 * amount that changes with the site's timezone and never with anything the user did.
	 *
	 * Returned as `Y-m-d H:i:s` in UTC, because that is what the audit columns hold.
	 *
	 * @param int $days_back How many days before today the window opens.
	 */
	private static function window_start( int $days_back, ?string $to = null ): string {
		$offset = WallClock::utc_offset();
		$local  = gmdate( 'Y-m-d 00:00:00', self::local_end( $to ) - ( max( 0, $days_back ) * DAY_IN_SECONDS ) );

		return gmdate( 'Y-m-d H:i:s', (int) strtotime( $local . ' UTC' ) - $offset );
	}

	/**
	 * The site's clock at the end of the window, as a timestamp.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE WHOLE DATE-RANGE FEATURE IS THIS ONE FUNCTION AND THE `$to` THREADED INTO ITS CALLERS.
	 *
	 * Every windowed report in this class asked the same question — "the last N days, ending now" —
	 * which cannot express "August". Rather than give five methods a second, parallel notion of a
	 * window, the anchor moves: `$days` still says how wide, and `$to` says where it ends. "Last 30
	 * days" is `$to = null`; 1–31 August is `days = 30, to = '2026-08-31'`.
	 *
	 * NULL IS NOT THE SAME AS TODAY'S DATE, and that is deliberate. With no `$to` the window ends
	 * at THIS MOMENT and includes everything recorded so far today. Passing today's date instead
	 * would end it at tomorrow's local midnight, which is the same set of rows now and a different
	 * one the instant somebody books a movement — a report that changes its own definition when
	 * used slightly differently.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string|null $to `Y-m-d` in the site's timezone, or null for "now".
	 */
	private static function local_end( ?string $to ): int {
		$offset = WallClock::utc_offset();

		if ( null === $to || '' === $to || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			return time() + $offset;
		}

		return (int) strtotime( $to . ' 00:00:00 UTC' );
	}

	/**
	 * The UTC instant a closed window stops at, or null when it runs up to now.
	 *
	 * EXCLUSIVE, AND POINTING AT THE NEXT DAY'S MIDNIGHT, so that the end date the reader typed is
	 * INCLUDED. `<= '2026-08-31'` on a datetime column excludes everything after midnight on the
	 * 31st — twenty-three hours of a day the user asked for, missing, with no error to explain it.
	 *
	 * @param string|null $to `Y-m-d` in the site's timezone, or null.
	 */
	private static function window_until( ?string $to ): ?string {
		if ( null === $to || '' === $to || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			return null;
		}

		$offset = WallClock::utc_offset();
		$local  = gmdate( 'Y-m-d 00:00:00', self::local_end( $to ) + DAY_IN_SECONDS );

		return gmdate( 'Y-m-d H:i:s', (int) strtotime( $local . ' UTC' ) - $offset );
	}

	/**
	 * Whole days between a date and today, in the SITE'S timezone.
	 *
	 * Computed here rather than as `DATEDIFF()` in the query: the grouped statement would have to
	 * reference the bucket expression a second time to stay legal under ONLY_FULL_GROUP_BY, and one
	 * definition of "how old" beats two that have to be kept identical.
	 *
	 * BOTH ENDS MOVED TOGETHER, AND THAT MATTERS MORE THAN EITHER. `$date` now arrives as a
	 * site-local day, because the queries that produce it shift the column before taking `DATE()`.
	 * "Today" therefore has to be the site's today as well: comparing a local day against UTC's
	 * today would put every receipt in the wrong ageing bucket for the hours the two disagree —
	 * which, on a site far enough from UTC, is every receipt made in a third of the day. This used
	 * to be UTC on both sides, which was wrong in the same direction and so was self-consistent;
	 * changing one side alone would have been worse than leaving both.
	 *
	 * @param string $date A Y-m-d date, already in the site's timezone.
	 */
	private static function days_since( string $date ): int {
		$then = strtotime( $date . ' 00:00:00 UTC' );

		if ( false === $then ) {
			return 0;
		}

		$today = gmdate( 'Y-m-d', time() + WallClock::utc_offset() );

		return max( 0, (int) floor( ( strtotime( $today . ' 00:00:00 UTC' ) - $then ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Attribute each on-hand line back across its receipts, and total the ages into buckets.
	 *
	 * ─────────────────────────────────────────────────────────────────────────────────────────
	 * THE ONE PIECE OF JUDGEMENT IN THE AGEING REPORT.
	 *
	 * For one item in one warehouse you hold `quantity` units today, and you have the receipts
	 * that put them there, newest first. The walk is: take from the newest receipt first, because
	 * consuming oldest-first is what left it standing, and keep taking until the on-hand quantity
	 * is accounted for. Each amount taken lands in the bucket its receipt's `age_days` falls into.
	 *
	 * TWO CASES DECIDE WHAT THIS REPORT MEANS, and they are the reason this is its own method:
	 *
	 *   - RECEIPTS RUN OUT with quantity still unattributed. The ledger does not reach back far
	 *     enough (MAX_RECEIPT_DAYS), or stock predates the ledger entirely — an opening balance
	 *     somebody typed. THE REMAINDER GOES INTO THE LAST BUCKET, which is the decision this
	 *     method exists to record. Dropping it instead would make the buckets sum to less than the
	 *     stock on hand, and an ageing report that quietly disagrees with the valuation report is
	 *     worse than one that is approximate: two authoritative-looking totals and nothing on
	 *     screen to say which to believe. "Old, precise age unknown" is both true and the answer a
	 *     buyer would act on anyway.
	 *
	 *     It is still counted separately and returned as `unattributed`, so the screen can say so
	 *     out loud. Folding it in silently would be the same mistake one level down.
	 *
	 *   - RECEIPTS EXCEED the quantity on hand, which is the normal case: an item received a
	 *     hundred times and sold down to twelve. Only the newest receipts are still standing, so
	 *     the walk stops as soon as the on-hand quantity is covered and the rest are not this
	 *     report's business.
	 * ─────────────────────────────────────────────────────────────────────────────────────────
	 *
	 * WHAT A UNIT IS WORTH HERE IS WHATEVER THE VALUATION REPORT SAYS IT IS WORTH, which is the
	 * whole reason `$value_from_receipts` is a parameter rather than a decision made in the loop.
	 * Each receipt carries the cost actually paid that day, and using it would make this the more
	 * accurate report in isolation — and would put a different total on the ageing screen from the
	 * one on the valuation screen, for the same stock, on the same morning. Nobody reading them can
	 * tell which is right, and the more accurate number does not win that argument; it just makes
	 * both untrustworthy.
	 *
	 * So without layers this values at the item's cost, falling back to its sale price — and it does
	 * so by using the SAME `UNIT_COST` expression `value_expression()` is built from, resolved in
	 * SQL by `on_hand_by_location()` and arriving here as `unit_cost`. Not a matching rule
	 * reimplemented in PHP: the same one, read. Under layer costing both switch to the cost actually
	 * paid — valuation through `lv.layer_value`, this through the receipts — and they reconcile
	 * again, one level down. The receipts' costs are read either way; this only decides whether they
	 * are what gets multiplied.
	 *
	 * @param array<int, array<string, mixed>>                $on_hand             One row per item per warehouse.
	 * @param array<string, array<int, array<string, mixed>>> $receipts            Keyed "item_id:warehouse_id", newest first.
	 * @param bool                                            $value_from_receipts Value at cost paid rather than at unit price.
	 *
	 * @return array<string, mixed>
	 */
	private function bucket_ages( array $on_hand, array $receipts, bool $value_from_receipts = false ): array {
		$totals = array();

		foreach ( self::AGE_BUCKETS as $bucket ) {
			$totals[ $bucket['key'] ] = array(
				'bucket'   => $bucket['key'],
				'items'    => 0,
				'quantity' => 0.0,
				'value'    => 0.0,
			);
		}

		$oldest       = self::AGE_BUCKETS[ count( self::AGE_BUCKETS ) - 1 ]['key'];
		$unattributed = array(
			'quantity' => 0.0,
			'value'    => 0.0,
			'lines'    => 0,
		);

		foreach ( $on_hand as $line ) {
			$remaining = (float) $line['quantity'];

			if ( $remaining <= 0 ) {
				continue;
			}

			// Already resolved by `UNIT_COST` in SQL — cost price, or the sale price where no cost
			// was recorded. There is deliberately no second copy of that rule here.
			$fallback_cost = (float) ( $line['unit_cost'] ?? 0 );
			$key           = (int) $line['item_id'] . ':' . (int) $line['warehouse_id'];

			// Which buckets this ONE stock line touched. A line split across three receipt dates is
			// one item in each of three buckets, not three items in one — 'items' counts things you
			// could go and look at, so counting per receipt would inflate it by the receipt history.
			$touched = array();

			foreach ( $receipts[ $key ] ?? array() as $receipt ) {
				if ( $remaining <= 0 ) {
					break;
				}

				$taken     = min( $remaining, (float) $receipt['quantity'] );
				$remaining -= $taken;

				$cost = (float) $receipt['unit_cost'];

				// A receipt with no cost on it — an opening balance, or a document finalized before
				// costs were being captured — falls back to the item's price rather than valuing
				// that stock at nothing.
				$unit_value = $value_from_receipts && $cost > 0 ? $cost : $fallback_cost;

				$bucket = self::bucket_for( (int) $receipt['age_days'] );

				$totals[ $bucket ]['quantity'] += $taken;
				$totals[ $bucket ]['value']    += $taken * $unit_value;

				$touched[ $bucket ] = true;
			}

			/*
			 * Whatever the receipts could not account for. Into the oldest bucket, per the rule
			 * above, and tallied so the total can be reported rather than absorbed.
			 */
			if ( $remaining > 0 ) {
				$value = $remaining * $fallback_cost;

				$totals[ $oldest ]['quantity'] += $remaining;
				$totals[ $oldest ]['value']    += $value;

				$unattributed['quantity'] += $remaining;
				$unattributed['value']    += $value;
				++$unattributed['lines'];

				$touched[ $oldest ] = true;
			}

			foreach ( array_keys( $touched ) as $bucket ) {
				++$totals[ $bucket ]['items'];
			}
		}

		return array(
			'buckets'      => array_map(
				static function ( array $row ): array {
					$row['quantity'] = round( $row['quantity'], 4 );
					$row['value']    = round( $row['value'], 2 );

					return $row;
				},
				array_values( $totals )
			),
			'unattributed' => array(
				'quantity' => round( $unattributed['quantity'], 4 ),
				'value'    => round( $unattributed['value'], 2 ),
				'lines'    => $unattributed['lines'],
			),
		);
	}

	/**
	 * Which bucket an age in days belongs to.
	 *
	 * @param int $age_days Whole days since receipt.
	 */
	private static function bucket_for( int $age_days ): string {
		foreach ( self::AGE_BUCKETS as $bucket ) {
			if ( null === $bucket['until'] || $age_days <= $bucket['until'] ) {
				return $bucket['key'];
			}
		}

		return self::AGE_BUCKETS[ count( self::AGE_BUCKETS ) - 1 ]['key'];
	}

	/**
	 * @param int $days   Window length.
	 * @param int $offset How many days further back the window starts.
	 *
	 * @return array<string, mixed>
	 */
	private function window_totals( int $days, int $offset, ?string $end = null ): array {
		$from = self::window_start( $days + $offset, $end );

		/*
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 * THE CLOSING BOUND IS THE NEXT WINDOW'S OPENING ONE, EXCLUSIVE -- AND THAT IS A FIX.
		 *
		 * It used to be `gmdate( 'Y-m-d 23:59:59', strtotime( '-N days' ) )`, which put the day the
		 * current window OPENS at the end of the previous one as well: with a 30-day window, the
		 * day 30 days ago was counted in both, so "vs previous period" compared 30 days against 31
		 * and every quiet period looked slightly worse than it was. Deriving the end from
		 * `window_start()` makes the two windows meet exactly once, at a boundary that belongs to
		 * the later of them.
		 *
		 * It also had to change for the date range regardless: `strtotime( '-N days' )` is anchored
		 * to the server clock, so a window ending in August would have been compared against a
		 * period ending now.
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 */
		$to = 0 === $offset
			? gmdate(
				'Y-m-d H:i:s',
				(int) strtotime( gmdate( 'Y-m-d 00:00:00', self::local_end( $end ) + DAY_IN_SECONDS ) . ' UTC' )
					- WallClock::utc_offset()
			)
			: self::window_start( $offset, $end );

		$rows = $this->ledger->query( 'stats' )
			->where( 't.occurred_at >= %s', array( $from ) )
			// `<`, not `<=`: the bound is the next window's first instant and belongs to it.
			->where( 't.occurred_at < %s', array( $to ) )
			->aggregate(
				array(
					'movements'   => 'COUNT( * )',
					'units_moved' => 'COALESCE( SUM( ABS( t.quantity_change ) ), 0 )',
					'units_in'    => 'COALESCE( SUM( CASE WHEN t.quantity_change > 0 THEN t.quantity_change ELSE 0 END ), 0 )',
					'units_out'   => 'COALESCE( SUM( CASE WHEN t.quantity_change < 0 THEN -t.quantity_change ELSE 0 END ), 0 )',
				),
				1
			);

		$row = $rows[0] ?? array();

		return array(
			'movements'   => (int) ( $row['movements'] ?? 0 ),
			'units_moved' => (float) ( $row['units_moved'] ?? 0 ),
			'units_in'    => (float) ( $row['units_in'] ?? 0 ),
			'units_out'   => (float) ( $row['units_out'] ?? 0 ),
		);
	}

	/**
	 * The percentage change from one figure to another.
	 *
	 * FROM ZERO IS 100%, NOT INFINITY. Going from no movements to forty is a real change and the
	 * screen has to render something; a division by zero would render `Infinity` or `NaN`, and 0%
	 * would claim nothing happened.
	 *
	 * @param float $before The earlier figure.
	 * @param float $after  The later one.
	 */
	private function percent_change( float $before, float $after ): float {
		if ( 0.0 === $before ) {
			return 0.0 === $after ? 0.0 : 100.0;
		}

		return round( ( ( $after - $before ) / $before ) * 100, 1 );
	}

	/**
	 * Cast the identifier columns a grouped query hands back as strings.
	 *
	 * @param array<int, array<string, mixed>> $rows    The rows.
	 * @param string[]                         $ids     Columns that are ids.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function cast_groups( array $rows, array $ids ): array {
		return array_map(
			static function ( array $row ) use ( $ids ): array {
				foreach ( $ids as $column ) {
					if ( array_key_exists( $column, $row ) ) {
						$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
					}
				}

				foreach ( array( 'item_count', 'movements' ) as $column ) {
					if ( isset( $row[ $column ] ) ) {
						$row[ $column ] = (int) $row[ $column ];
					}
				}

				foreach (
					array(
						'total_quantity',
						'total_value',
						'avg_unit_price',
						'unit_price',
						// A column missing from this list comes back from MySQL as the STRING
						// "4.2500" and ships to a TypeScript interface that declares `number`.
						// formatMoney() coerces it and the screen looks right, so the omission
						// is invisible until something does arithmetic on it.
						'unit_cost',
						'stock_quantity',
						'total_movement',
						'turnover_ratio',
					) as $column
				) {
					if ( isset( $row[ $column ] ) ) {
						$row[ $column ] = round( (float) $row[ $column ], 4 );
					}
				}

				return $row;
			},
			$rows
		);
	}
}
