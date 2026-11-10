<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\AppManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The App Manager screen's strings.
 *
 * HAND-MAINTAINED, AND MACHINE-CHECKED. `php bin/generate-strings.php` reads every `t()` call in
 * the React source and refuses three things: a key the app renders that is not registered here, a
 * key whose English differs from the fallback written at the call site, and a key registered twice
 * in two catalogues with two different texts. `--add` appends whatever is missing.
 *
 * The `app_*` pairs for the OTHER modules are not here — each module registers its own card's
 * label and description in its own catalogue, for the same reason it registers its own screen's
 * strings there. The two below are the ones this module owns: its own card, and the card for
 * `Core\Settings`, which is a screen rather than a module and has no catalogue of its own.
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'app_manager'              => __( 'App manager', 'plugixa-inventory' ),
			'app_manager_intro'        => __( 'Switching an app off hides it everywhere, stops its endpoints answering and stops its background work — and keeps all of its data, so you can switch it back on and pick up where you left off.', 'plugixa-inventory' ),
			'app_manager_core_heading' => __( 'Core · always on', 'plugixa-inventory' ),

			'app_all'                  => __( 'All apps', 'plugixa-inventory' ),
			'app_cat_operations'       => __( 'Operations', 'plugixa-inventory' ),
			'app_cat_insight'          => __( 'Insight', 'plugixa-inventory' ),
			'app_cat_configuration'    => __( 'Configuration', 'plugixa-inventory' ),
			'app_cat_tools'            => __( 'Tools', 'plugixa-inventory' ),

			'app_always_on'            => __( 'Always on', 'plugixa-inventory' ),
			'app_active'               => __( 'Active', 'plugixa-inventory' ),
			'app_inactive'             => __( 'Inactive', 'plugixa-inventory' ),
			'app_activate'             => __( 'Activate', 'plugixa-inventory' ),
			'app_deactivate'           => __( 'Deactivate', 'plugixa-inventory' ),
			'app_needs'                => __( 'Needs', 'plugixa-inventory' ),
			'app_off_via_dependency'   => __( 'Off because something it needs is off.', 'plugixa-inventory' ),
			'app_toggle_failed'        => __( 'That could not be changed', 'plugixa-inventory' ),
			'app_none_in_category'     => __( 'Nothing here yet.', 'plugixa-inventory' ),
			'app_manager_data_note'    => __( 'Nothing here deletes anything. An app you switch off keeps its records and its settings, and finds them again when you switch it back on.', 'plugixa-inventory' ),

			// This module's own card, and the card for the settings screen.
			'app_app_manager'          => __( 'App manager', 'plugixa-inventory' ),
			'app_app_manager_desc'     => __( 'Turn features on and off. Anything switched off keeps its data.', 'plugixa-inventory' ),
			'app_settings'             => __( 'Settings', 'plugixa-inventory' ),
			'app_settings_desc'        => __( 'Global configuration: formatting, currency, appearance and each app\'s own options.', 'plugixa-inventory' ),
		);
	}
}
