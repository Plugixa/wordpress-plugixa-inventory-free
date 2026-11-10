<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\WarehouseDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The warehouse overview screen's strings.
 *
 * HAND-MAINTAINED, AND MACHINE-CHECKED. `php bin/generate-strings.php` reads every `t()` call in
 * the React source and refuses three things: a key the app renders that is not registered here, a
 * key whose English differs from the fallback written at the call site, and a key registered twice
 * in two catalogues with two different texts. `--add` appends whatever is missing.
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'warehouse_overview'           => __( 'Warehouses', 'plugixa-inventory' ),
			'search_warehouses'            => __( 'Search warehouses…', 'plugixa-inventory' ),
			'wh_inventory_value'           => __( 'Inventory value', 'plugixa-inventory' ),
			'wh_activity_7d'               => __( 'Activity (7 days)', 'plugixa-inventory' ),
			'wh_transfers_in'              => __( 'Transfers in', 'plugixa-inventory' ),
			'wh_transfers_out'             => __( 'Transfers out', 'plugixa-inventory' ),
			'wh_transfers_pending'         => __( 'Pending transfers', 'plugixa-inventory' ),
			'wh_pending'                   => __( 'pending', 'plugixa-inventory' ),
			'wh_tab_overview'              => __( 'Overview', 'plugixa-inventory' ),
			'wh_tab_inventory'             => __( 'Inventory', 'plugixa-inventory' ),
			'wh_tab_activity'              => __( 'Activity', 'plugixa-inventory' ),
			'wh_top_low_stock'             => __( 'Lowest stock here', 'plugixa-inventory' ),
			'wh_no_low_stock'              => __( 'Nothing here is below its reorder level.', 'plugixa-inventory' ),
			'wh_no_stock'                  => __( 'This warehouse holds no stock yet.', 'plugixa-inventory' ),
			'wh_no_activity'               => __( 'Nothing has moved here yet.', 'plugixa-inventory' ),
			'wh_none'                      => __( 'No warehouses yet', 'plugixa-inventory' ),
			'wh_none_hint'                 => __( 'A warehouse is a place stock is kept. Add one under Configuration.', 'plugixa-inventory' ),
			'wh_no_match'                  => __( 'No warehouse matches that.', 'plugixa-inventory' ),
			'edit_warehouse'               => __( 'Edit warehouse', 'plugixa-inventory' ),
			'wh_open'                      => __( 'Open', 'plugixa-inventory' ),
			'wh_adjustments_here'          => __( 'Adjustments', 'plugixa-inventory' ),

			// The App Manager card.
			'app_warehouse_dashboard'      => __( 'Warehouse overview', 'plugixa-inventory' ),
			'app_warehouse_dashboard_desc' => __( 'The operational view of each warehouse: what it holds, what is short, and what has moved.', 'plugixa-inventory' ),
		);
	}
}
