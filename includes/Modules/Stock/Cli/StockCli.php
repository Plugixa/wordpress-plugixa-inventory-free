<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Cli;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Modules\Stock\Services\StockEngine;
use WP_CLI;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp plugixa-inventory stock …` — the reconcile, from a terminal.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A NEW PATTERN FOR THE PLUGIXA FAMILY, and it is here for two specific reasons rather than for
 * completeness. `grep -rl "WP_CLI"` across the CRM this architecture came from returns nothing.
 *
 *  1. A REBUILD OVER 50,000 ITEMS WILL OUTLIVE ANY REST TIMEOUT. The REST route is right for a
 *     single item and for the daily report; it is the wrong shape for the whole-site run somebody
 *     does after a migration, and giving that run a browser tab to die in is not a design.
 *  2. `--repair-balances` REWRITES AN AUDIT COLUMN. It should not be one `fetch()` away from a
 *     screen, and requiring shell access is a meaningful gate that no capability check replicates.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * IT HOLDS NO LOGIC OF ITS OWN — it parses flags, calls `StockEngine`, and prints a table. Every
 * rule about what may be repaired lives on the engine, so the command and the REST route cannot
 * drift into disagreeing about what `--apply` means.
 */
final class StockCli {

	public function __construct( private readonly StockEngine $engine ) {}

	/**
	 * Hook the command up. Called only when WP-CLI is loaded.
	 *
	 * @param Container $container The plugin container.
	 */
	public static function register( Container $container ): void {
		WP_CLI::add_command( 'plugixa-inventory stock', new self( $container->get( StockEngine::class ) ) );
	}

	/**
	 * Check every denormalised quantity against the stock ledger.
	 *
	 * ## OPTIONS
	 *
	 * [--item=<id>]
	 * : Restrict the check to one item.
	 *
	 * [--apply]
	 * : Correct item_stock from the ledger. Without this the command only reports.
	 *
	 * [--repair-balances]
	 * : Additionally correct the cached items.stock_quantity totals.
	 *
	 * [--format=<format>]
	 * : Render the findings as table, json, csv or yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugixa-inventory stock reconcile
	 *     wp plugixa-inventory stock reconcile --item=44
	 *     wp plugixa-inventory stock reconcile --apply --repair-balances
	 *
	 * @param string[]              $args       Positional arguments. None.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function reconcile( array $args, array $assoc_args ): void {
		unset( $args );

		$item   = isset( $assoc_args['item'] ) ? (int) $assoc_args['item'] : 0;
		$apply  = isset( $assoc_args['apply'] );
		$repair = isset( $assoc_args['repair-balances'] );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		$report = $this->engine->rebuild( $item > 0 ? $item : null, $apply, $repair );

		if ( $report instanceof WP_Error ) {
			WP_CLI::error( $report->get_error_message() );
		}

		WP_CLI::log(
			sprintf(
				'Checked %d item/warehouse pairs%s.',
				(int) $report['pairs_checked'],
				$item > 0 ? ' for item ' . $item : ''
			)
		);

		$this->section( 'Stock vs ledger', $report['stock_diffs'], $format );
		$this->section( 'Cached totals vs stock', $report['cache_diffs'], $format );

		if ( $report['layers_active'] ) {
			$this->section( 'Cost layers vs stock', $report['layer_diffs'], $format );

			/*
			 * SAID OUT LOUD EVERY RUN, not only when layers drift. Somebody running this after a
			 * bad night needs to know that the one set of numbers it did not offer to fix is the
			 * one they cannot get back by running it again.
			 */
			WP_CLI::log( 'Cost layers are reported only. They record which layer paid for which removal, and that path cannot be recomputed.' );
		}

		if ( array() !== $report['orphan_batches'] ) {
			WP_CLI::warning(
				sprintf(
					'%d batch(es) were in flight when a request died: %s',
					count( $report['orphan_batches'] ),
					implode( ', ', array_keys( $report['orphan_batches'] ) )
				)
			);
		}

		$found = count( $report['stock_diffs'] ) + count( $report['cache_diffs'] ) + count( $report['layer_diffs'] );

		if ( 0 === $found ) {
			WP_CLI::success( 'No drift. Every denormalised quantity follows from the ledger.' );

			return;
		}

		/*
		 * A DRY RUN THAT FOUND SOMETHING IS NOT AN ERROR. It did exactly what it was asked to do,
		 * and exiting non-zero would make a nightly `wp plugixa-inventory stock reconcile` in cron
		 * page somebody every morning for a report that is working. The findings are the output.
		 */
		WP_CLI::success(
			sprintf(
				'%d finding(s).%s',
				$found,
				$apply ? '' : ' Nothing was changed — re-run with --apply to correct item_stock.'
			)
		);
	}

	/**
	 * Open cost layers for stock that predates the costing method.
	 *
	 * Only does anything under fifo or lifo costing. Reconstructs each pair's layers from the
	 * ledger's own receipts, so both the ages and the costs are the real ones.
	 *
	 * ## OPTIONS
	 *
	 * [--item=<id>]
	 * : Restrict the seeding to one item.
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugixa-inventory stock seed-layers
	 *
	 * THE `@subcommand` LINE IS LOAD-BEARING. WP-CLI names a subcommand after the METHOD, so
	 * without it this registers as `seed_layers` and the example three lines above — which is also
	 * what the REST route is called (`POST /stock/seed-layers`) and what the docs say — is a
	 * command that does not exist. WP-CLI answers it with "Did you mean 'seed_layers'?".
	 *
	 * @subcommand seed-layers
	 *
	 * @param string[]              $args       Positional arguments. None.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function seed_layers( array $args, array $assoc_args ): void {
		unset( $args );

		$item = isset( $assoc_args['item'] ) ? (int) $assoc_args['item'] : 0;

		/*
		 * RUNS UNTIL THERE IS NOTHING LEFT, one bounded run at a time. Each run holds the stock lock
		 * only for its own batch, so finalizes on the site interleave between runs rather than
		 * waiting for the whole catalogue. It stops early if a run makes no progress, rather than
		 * spinning on pairs that cannot be opened.
		 */
		$layers = 0;
		$pairs  = 0;
		$units  = 0.0;
		$more   = false;

		for ( $run = 0; $run < 1000; $run++ ) {
			$report = $this->engine->seed_costing_layers( $item > 0 ? $item : null );

			if ( $report instanceof WP_Error ) {
				WP_CLI::error( $report->get_error_message() );
			}

			$layers += (int) $report['layers'];
			$pairs  += (int) $report['seeded'];
			$units  += (float) $report['quantity'];
			$more    = 1 === (int) $report['remaining'];

			if ( ! $more || (int) $report['layers'] < 1 ) {
				break;
			}

			WP_CLI::log( sprintf( 'Run %d: opened %d layer(s) across %d pair(s); continuing.', $run + 1, (int) $report['layers'], (int) $report['seeded'] ) );
		}

		if ( 0 === $pairs ) {
			WP_CLI::success( 'Nothing to seed: every item holding stock that is costed by layers already has them.' );

			return;
		}

		WP_CLI::success( sprintf( 'Opened %d layer(s) across %d pair(s), covering %s units.', $layers, $pairs, (string) round( $units, 4 ) ) );

		if ( $more ) {
			WP_CLI::warning( 'Some pairs could not be opened. Run `wp plugixa-inventory stock reconcile` to see them.' );
		}
	}

	/**
	 * Print one set of findings, or say there were none.
	 *
	 * @param string                           $title  What was compared.
	 * @param array<int, array<string, mixed>> $rows   The findings.
	 * @param string                           $format WP-CLI output format.
	 */
	private function section( string $title, array $rows, string $format ): void {
		if ( array() === $rows ) {
			WP_CLI::log( sprintf( '%s: clean.', $title ) );

			return;
		}

		WP_CLI::log( sprintf( '%s: %d finding(s).', $title, count( $rows ) ) );

		WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows[0] ) );
	}
}
