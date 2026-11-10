<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Dashboard screen's strings.
 *
 * Mirrors the t() calls in assets/backend/app/src/modules/dashboard/. A key used there and missing
 * here falls back to its English default on every install in every locale, silently — which is
 * exactly what had happened to the greeting and the empty states before this file existed.
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'dashboard'            => __( 'Dashboard', 'plugixa-inventory' ),
			'good_morning'         => __( 'Good morning', 'plugixa-inventory' ),
			'good_afternoon'       => __( 'Good afternoon', 'plugixa-inventory' ),
			'good_evening'         => __( 'Good evening', 'plugixa-inventory' ),

			'total_items'          => __( 'Items', 'plugixa-inventory' ),
			'stock_value'          => __( 'Stock value', 'plugixa-inventory' ),
			'movements_30d'        => __( 'Movements, 30 days', 'plugixa-inventory' ),
			'units_moved'          => __( 'Units moved', 'plugixa-inventory' ),

			'stock_by_type'        => __( 'Stock by type', 'plugixa-inventory' ),
			'movement_trend'       => __( 'Movement', 'plugixa-inventory' ),
			'top_movers'           => __( 'Top movers', 'plugixa-inventory' ),
			'recent_activity'      => __( 'Recent activity', 'plugixa-inventory' ),

			'stock_in'             => __( 'In', 'plugixa-inventory' ),
			'stock_out'            => __( 'Out', 'plugixa-inventory' ),
			'movements'            => __( 'Movements', 'plugixa-inventory' ),
			'view_all'             => __( 'View all', 'plugixa-inventory' ),

			'no_recent_activity'   => __( 'Nothing has moved yet.', 'plugixa-inventory' ),
			'no_movers'            => __( 'No stock moved this week.', 'plugixa-inventory' ),
			'no_low_stock'         => __( 'Nothing is below its reorder level.', 'plugixa-inventory' ),
			'no_stock_value'       => __( 'No stock on hand yet.', 'plugixa-inventory' ),

			/* translators: %s: a percentage change, already formatted, e.g. "18%". */
			'up_on_previous'       => __( 'Up %s on the previous period', 'plugixa-inventory' ),
			/* translators: %s: a percentage change, already formatted, e.g. "18%". */
			'down_on_previous'     => __( 'Down %s on the previous period', 'plugixa-inventory' ),
			'flat_on_previous'     => __( 'Unchanged on the previous period', 'plugixa-inventory' ),

			'dashboard_load_error' => __( 'The dashboard could not be loaded.', 'plugixa-inventory' ),

			// The App Manager card.
			'app_dashboard'      => __( 'Dashboard', 'plugixa-inventory' ),
			'app_dashboard_desc' => __( 'Stock, value, low stock and recent activity at a glance.', 'plugixa-inventory' ),
		);
	}
}
