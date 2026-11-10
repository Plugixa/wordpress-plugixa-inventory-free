<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\App;

use PlugixaInventory\Core\Admin\Strings;
use PlugixaInventory\Core\Security\Capabilities;
use PlugixaInventory\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The configuration the React application is handed on the page, so it needs no request to start.
 *
 * Moved out of `Core\Admin\Assets` when the bundle stopped being admin-only. The filter names did
 * NOT change with it, and that is deliberate rather than lazy: `plugixa_inventory_admin_i18n_strings` has
 * thirty listeners across the modules and `plugixa_inventory_admin_config` is how Import/Export gets its
 * format list to the client. Renaming either for tidiness would empty the client's string table and
 * the Export menu, silently, on every screen at once. The word "admin" in them is now a historical
 * spelling, not a claim about where they fire.
 */
final class Config {

	private const HANDLE = 'plugixa-inventory-config';

	/**
	 * Print the configuration inline, ahead of the bundle.
	 *
	 * WHY THE CONTEXT IS A REQUIRED ARGUMENT AND NOT A DEFAULT. The app renders one menu item whose
	 * correct destination depends on how the user got here — "Exit to WordPress" means the dashboard
	 * from wp-admin and the site's front page from an inventory page. A default would be right for one caller
	 * and quietly wrong for the other, and "quietly wrong" for a navigation link means a staff member
	 * on a front-end inventory being thrown into wp-admin by a button that promised to take them out of it.
	 *
	 * @param string               $context Where the app is mounted: 'admin' or 'page'.
	 * @param array<string, mixed> $extra   Additional keys for this mount point.
	 */
	public static function inject( string $context, array $extra = array() ): void {
		$config = array(
			'apiUrl'      => esc_url_raw( rest_url( 'plugixa-inventory/v1/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'adminUrl'    => esc_url_raw( admin_url() ),

			/*
			 * `homeUrl` and `context` arrived with `[plugixa_inventory_app]`. `adminUrl` stayed: it is still
			 * how the app links to `profile.php`, which is in wp-admin wherever the inventory itself is
			 * mounted, and three Vitest stubs are typed against its presence.
			 */
			'homeUrl'     => esc_url_raw( home_url( '/' ) ),
			'context'     => $context,

			'permissions' => Capabilities::map(),
			'version'     => PLUGIXA_INVENTORY_VERSION,

			/*
			 * `hasPro` used to be here — `is_dir( pro/ )`, presence of the premium overlay rather than
			 * a licence state. There is no overlay now and no directory to test, and a client flag
			 * meaning "some paid thing is installed" would be a worse question than the ones the
			 * client can already answer: what it actually wants to know is whether a PARTICULAR
			 * capability is present, and every registry on the page answers that precisely.
			 *
			 * A module that needs the client to know something before its first request adds it
			 * through `plugixa_inventory_admin_config` below.
			 */
			'i18n'        => self::translations(),

			/*
			 * Who is looking, so a form can default an assignee to them rather than making you pick
			 * yourself, and so the header can say who is signed in.
			 *
			 * THE EMAIL IS THE VIEWER'S OWN, printed on a screen only they can open. There is no
			 * avatar URL on purpose: `get_avatar_url()` is a Gravatar request, a third-party service
			 * the readme would have to disclose, so the header draws an initial instead.
			 */
			'currentUser' => array(
				'id'    => get_current_user_id(),
				'name'  => wp_get_current_user()->display_name,
				'email' => wp_get_current_user()->user_email,
			),

			/*
			 * WordPress's own answer to "is this the live site" — 'production' unless the site
			 * defines WP_ENVIRONMENT_TYPE. The header shows a pill for anything else, so somebody
			 * adjusting stock on a copy can tell it is a copy.
			 */
			'env'         => wp_get_environment_type(),

			// Carries its own nonce, which is why it is built here rather than by the client.
			'logoutUrl'   => esc_url_raw( wp_logout_url() ),

			/*
			 * THE FORMATTING SETTINGS, SENT UP FRONT RATHER THAN FETCHED, and this is not a
			 * convenience.
			 *
			 * Every quantity and every price in the app is rendered through them — the on-hand
			 * column, the price column, the valuation totals, every line of every document. Those
			 * are rendered inside a DataGrid `renderCell`, which is an ordinary function call
			 * during render and cannot use a hook, so the values have to be readable
			 * SYNCHRONOUSLY from anywhere. A `useSettings()` query cannot be.
			 *
			 * Fetching them would also mean the first paint of every list shows numbers formatted
			 * with the defaults and then reflows into the user's own separators. On a grid of fifty
			 * rows that is a visible flicker on every navigation.
			 *
			 * The settings SCREEN still uses the REST route, because it needs the choice lists too
			 * and needs them fresh. This is the read-only copy for rendering.
			 */
			'settings'    => self::formatting(),

			// Not this plugin's to set, and the app needs it to render a ledger timestamp. See the
			// Settings class docblock for why there is no timezone setting of our own.
			'timezone'    => wp_timezone_string(),
		);

		$config = array_merge( $config, $extra );

		/**
		 * Filter the configuration handed to the admin application.
		 *
		 * The i18n strings have had a filter since Module 1; the rest of the config did not, so a
		 * module with something the client needs BEFORE its first request had nowhere to put it — a
		 * screen that must decide whether to register at all cannot ask the database to find out.
		 *
		 * Keep what goes through here to facts the client cannot obtain otherwise. It is printed
		 * inline on the page, so anything added is visible to anyone who can view the inventory screen —
		 * and since `[plugixa_inventory_app]` that page may have a public URL, so "anyone who can view the
		 * screen" is now enforced by the shortcode's capability gate rather than by wp-admin's door.
		 *
		 * @param array<string, mixed> $config Configuration for the admin app.
		 */
		$config = (array) apply_filters( 'plugixa_inventory_admin_config', $config );

		/*
		 * A src-less handle carrying an inline script, and it has to be one: `wp_add_inline_script()`
		 * cannot attach to a script MODULE, which is what the bundle is enqueued as.
		 */
		wp_register_script( self::HANDLE, '', array(), PLUGIXA_INVENTORY_VERSION, false );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script(
			self::HANDLE,
			'window.PlugixaInventoryConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Strings the React app renders.
	 *
	 * Core lists only the strings it owns — the shell, the dashboard, the settings screen.
	 * Every module adds its own through the filter below, which is also how the premium
	 * overlay adds its labels: the free bundle therefore ships none of them, and no premium
	 * feature name leaks into the free build.
	 *
	 * @return array<string, string>
	 */
	/**
	 * The subset of the settings the client formats numbers and dates with.
	 *
	 * A NAMED LIST RATHER THAN `Settings::all()`, deliberately. The option is one row that every
	 * module adds keys to, and shipping it wholesale would put whatever a module happens to store —
	 * a mailbox name, a schedule, one day an API token — into a JSON blob printed inline on every
	 * admin page load, readable by anything on the page. The client needs six values to render a
	 * number. It gets six values.
	 *
	 * The currency SYMBOL is not sent: `Intl.NumberFormat` in the browser already knows every ISO
	 * code, and a symbol table in PHP would be ~85 rows to maintain against a built-in that is
	 * better at it. The Joomla component this ports has exactly that table.
	 *
	 * @return array<string, mixed>
	 */
	private static function formatting(): array {
		$settings = Settings::all();

		/**
		 * Filter which settings keys are printed onto the page.
		 *
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 * FOR THE SETTING A SCREEN MUST HAVE BEFORE ITS FIRST PAINT, AND FOR NOTHING ELSE.
		 *
		 * The list above is Core's, and the reason for a list at all is in the docblock: this blob
		 * is printed inline on every page load and readable by anything on the page, so a module's
		 * mailbox name or schedule has no business in it. A module that genuinely cannot wait for
		 * its first request — because the value decides what the FIRST render looks like, and a
		 * fetched value would repaint under the user's cursor — adds its key here.
		 *
		 * `Items` does it for its two new-item defaults. Anything that can wait should wait: the
		 * settings screen fetches the whole option over REST anyway.
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 *
		 * @param string[] $keys Setting keys to print.
		 */
		$keys = (array) apply_filters(
			'plugixa_inventory_config_settings_keys',
			array(
				'currency',
				'currency_position',
				'currency_decimals',
				'decimal_places',
				'decimal_separator',
				'thousands_separator',
				'date_format',
				'records_per_page',
			)
		);

		$formatting = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$formatting[ $key ] = $settings[ $key ];
			}
		}

		return $formatting;
	}

	/**
	 * @return array<string, string>
	 */
	private static function translations(): array {
		$strings = Strings::all();

		/**
		 * Filter the strings handed to the admin application.
		 *
		 * @param array<string, string> $strings Key => translated label.
		 */
		return (array) apply_filters( 'plugixa_inventory_admin_i18n_strings', $strings );
	}
}
