<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Modules;

use PlugixaInventory\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How a module tells the App Manager what it is.
 *
 * A MODULE DESCRIBES ITSELF; CORE NEVER NAMES ONE. `CoreIndependenceTest` forbids any file under
 * `includes/Core` from matching `PlugixaInventory\Modules\`, with an empty exemption list and a
 * docblock saying there are none on purpose — so the catalogue of apps cannot be a table in Core.
 * It is collected the same way the i18n catalogues are: every module hands over its own entry.
 *
 * THIS IS A METHOD RATHER THAN A FILTER, AND THAT IS THE WHOLE REASON THE INTERFACE EXISTS. A
 * module contributes its strings, its entity descriptor and its settings defaults from
 * `registerHooks()` — but a DISABLED module never runs `registerHooks()`, and it is precisely the
 * disabled ones that have to appear in the grid so somebody can switch them back on. A filter
 * added in a method that does not run describes nothing. `ModuleRegistry::discover()` instantiates
 * every module on disk whatever its flag, so an instance method is always callable.
 *
 * @see AppState        for how `core`, `deps` and the stored flag resolve into one answer.
 * @see ModuleRegistry  for which of its four loops a disabled module is skipped in.
 */
interface AppInterface {

	/**
	 * The static description of this app: what it is, where it files, and whether it may be off.
	 *
	 * Called on the BOOT PATH, for every module on disk, on every request — `ModuleRegistry` needs
	 * `core` and `deps` before it can decide whether to run `registerHooks()`. So this must be an
	 * array literal and nothing else. No queries, no options, no `__()` on a key (the label is a
	 * key resolved later, see below).
	 *
	 * `label_key` and `desc_key` name entries in the module's own `Strings::all()`, resolved on the
	 * client with `t( key, fallback )`. They are keys rather than translated text because this runs
	 * before the i18n map is assembled, and because a card for a DISABLED module still has to draw
	 * a name — its catalogue was never merged, so the client falls back to the English it carries.
	 *
	 * `cron` names the hooks to `wp_clear_scheduled_hook()` when the app is switched off. Skipping
	 * `registerHooks()` stops new scheduling, but an event already written into the `cron` option
	 * survives, so the App Manager clears whatever this array lists — which is how it manages cron
	 * for a module it is not allowed to name.
	 *
	 * @return array{
	 *     category: string,
	 *     core: bool,
	 *     deps: string[],
	 *     default: bool,
	 *     label_key: string,
	 *     desc_key: string,
	 *     icon: string,
	 *     cron: string[]
	 * }
	 */
	public function app(): array;

	/**
	 * Live figures for the card: "6 transfers · 2 drafts".
	 *
	 * NEVER CALLED ON THE BOOT PATH. This issues COUNT queries, and `GET /apps` is the only caller
	 * — one screen an administrator opens occasionally, not `plugins_loaded` on every request of
	 * every page. A test asserts it stays that way, because this is exactly the kind of method
	 * somebody later "helpfully" folds into the descriptor.
	 *
	 * WHOLE PHRASES, NOT A NUMBER AND A NOUN THE CLIENT GLUES TOGETHER. "6 transfers" is one
	 * translatable unit: `_n()` has to see the count to pick the right plural form, and plenty of
	 * languages do not put the number in front. Returning `{ count: 6, label: 'transfers' }` would
	 * hand the client two pieces it can only reassemble in English.
	 *
	 * It is translated in PHP for the same reason. `bin/generate-strings.php` is a textual
	 * extractor that only sees `t( 'literal', 'literal' )`, so a label built on the client from a
	 * data table could never be translated at all — the constraint `shell/navGroups.ts` and
	 * `ReportsPage.tsx` work around with a literal `switch`. PHP has `_n()` and no such limit.
	 *
	 * Return nothing when there is nothing worth saying. A card with no figures is the normal case
	 * for a module that owns no table, and a row of zeroes tells nobody anything.
	 *
	 * @param Container $container The container, as every other method on a module takes it.
	 *
	 * @return string[] Ready to render, joined with a separator by the client.
	 */
	public function usage( Container $container ): array;

	/**
	 * This card's own name and description, translated.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * IT EXISTS BECAUSE A DISABLED MODULE CANNOT SPEAK FOR ITSELF, and the App Manager is the one
	 * screen that has to describe modules that are not running.
	 *
	 * A module contributes its strings from `registerHooks()`, which a disabled module does not
	 * run — that is precisely how its cron and its routes are stopped. So the first version of
	 * this screen switched Transfers off and redrew its card as the word "transfers" with no
	 * description: the label key resolved to nothing, and the fallback was the module id. The card
	 * that explains what you just turned off was the one thing that stopped explaining.
	 *
	 * So the App Manager merges these into the i18n map for EVERY module on disk, enabled or not.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * NOT ON THE BOOT PATH, and here that is a correctness rule rather than a performance one:
	 * `__()` before `init` makes WordPress load a textdomain too early and emit
	 * `_load_textdomain_just_in_time` — which is why `app()` returns KEYS and this returns TEXT.
	 *
	 * @return array{label: string, desc: string}
	 */
	public function labels(): array;

	/**
	 * Why this app cannot be toggled right now, already translated, or null when it can.
	 *
	 * A REASON, NOT A BOOLEAN. "Sample content is installed. Remove it first." tells somebody
	 * what to do; a greyed button with no explanation sends them to support. Returning a string
	 * makes the button refuse AND say why, in one value that cannot drift apart.
	 *
	 * Not called on the boot path — see `usage()`.
	 *
	 * @param Container $container The container.
	 */
	public function blocker( Container $container ): ?string;
}
