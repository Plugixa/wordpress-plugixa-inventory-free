<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This module's own words: the App Manager card, and nothing else.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE BUTTON'S THREE LABELS ARE IN `Core\Admin\Strings`, NOT HERE, AND THAT IS THE PROJECT'S RULE
 * RATHER THAN AN OVERSIGHT.
 *
 * `export_csv`, `exporting` and `export_failed` are read by `shared/ExportButton.tsx` — shared
 * chrome, rendered from `EntityList` on every list screen in the app. A module catalogue is loaded
 * through that module's own filter, so a key filed here would exist only while this module is
 * switched ON: turn the app off and the button... would not be drawn at all, which is the
 * behaviour `EntityList` now has. But `ChooseStep` and `ErrorReport` in the IMPORT module also
 * reach into that file for its `save()` helper, and a catalogue that comes and goes with a
 * different module is exactly the fragility `bin/generate-strings.php` files shared keys under
 * Core to avoid.
 *
 * They were in the REPORTS catalogue until this module existed, which was worse in the same
 * direction: every free list would have drawn a button labelled with its own raw key.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'app_export'      => __( 'Export', 'plugixa-inventory' ),
			'app_export_desc' => __( 'The Export button on every list, which writes what you are looking at to a CSV file.', 'plugixa-inventory' ),
		);
	}
}
