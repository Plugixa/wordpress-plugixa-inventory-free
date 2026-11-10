<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Lookup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What only the scanner says.
 *
 * A SHORT CATALOGUE ON PURPOSE. Everything the scan RESULTS say — names, SKUs, quantities — belongs
 * to Items, and this module deliberately does not restate any of it. What is left is only the
 * language of the act of scanning, which no other module has a reason to own.
 *
 * `php bin/generate-strings.php` checks that every key the React source renders is registered
 * somewhere and that the English matches the fallback at the call site.
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			// The header's scanner button. It opens the search palette, whose own words are the
			// shell's (Core\Admin\Strings); these are the ones only a scan says.
			'scan'          => __( 'Scan or search', 'plugixa-inventory' ),
			/*
			 * "No item HAS that code" rather than "not found": the scan searched barcode, SKU and
			 * serial, so this is a statement about the catalogue, not about the search.
			 */
			'scan_no_match'    => __( 'No item has that code.', 'plugixa-inventory' ),

			// The App Manager card.
			'app_lookup'      => __( 'Scanner and quick search', 'plugixa-inventory' ),
			'app_lookup_desc' => __( 'Quick search and barcode or SKU lookup from anywhere in the app.', 'plugixa-inventory' ),
		);
	}
}
