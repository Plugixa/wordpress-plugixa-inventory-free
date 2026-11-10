<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\App;

use PlugixaInventory\Core\Admin\Hmr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The compiled React application, wherever it is being mounted.
 *
 * THIS CLASS EXISTS BECAUSE THE APP IS NO LONGER ADMIN-ONLY. Every method here was private on
 * `Core\Admin\Assets` and correct there for exactly as long as `admin.php?page=plugixa-inventory` was the
 * only place the bundle could appear. It is not any more: `[plugixa_inventory_app]` mounts the same bundle
 * on a public page for staff, and a second copy of the manifest reader is the kind of duplicate that
 * stays right for one release and then drifts — one caller learns about a new Vite output and the
 * other does not.
 *
 * WHAT DID *NOT* MOVE HERE IS THE POINT. `Core\Admin\Assets` keeps `KEEP_STYLES`, `DROP_STYLES`,
 * `strip_unused_admin_styles()`, `reset_admin_chrome()` and `mark_body()`, because every one of them
 * is an answer to a *wp-admin* stylesheet — `forms`, the colour scheme's focus ring, `common.css`'s
 * heading colour and its `[role="treeitem"]` rule. None of those exist on the front end, and that
 * file's own warning applies with full force: a rule with nothing to fight is not harmless, because
 * anything it says, MUI loses.
 *
 * So the split is: what the bundle IS lives here; what a particular PAGE has to survive lives with
 * that page.
 */
final class Bundle {

	public const HANDLE = 'plugixa-inventory-app';

	/**
	 * The element `main.tsx` renders into.
	 *
	 * It was a string literal in `Admin::render()`, and once a shortcode needed the same literal it
	 * became a fact with two homes. `main.tsx:32` looks this id up by name, so all three must agree
	 * and none of them is where you would think to look for the others.
	 */
	public const ROOT_ID = 'plugixa-inventory-root';

	/**
	 * The mount point as markup, for callers that must RETURN a string rather than print one —
	 * which is every shortcode, because echoing from a shortcode puts its output at the top of the
	 * page instead of where the tag is.
	 */
	public static function mount_html(): string {
		return '<div id="' . esc_attr( self::ROOT_ID ) . '"></div>';
	}

	/**
	 * Put the application on the page: the dev server if it is available, the build otherwise.
	 */
	public static function enqueue(): void {
		if ( self::hmr_available() ) {
			Hmr::enqueue();

			return;
		}

		self::enqueue_build();
	}

	/**
	 * Whether to load from the Vite dev server.
	 *
	 * THE FILE CHECK COMES FIRST IN THE `&&`, AND THAT ORDER IS CORRECTNESS, NOT STYLE.
	 * `bin/release.ps1` deletes `includes/Core/Admin/Hmr.php` from the staged plugin and asserts
	 * no ZIP contains it, so in a shipped build the class is not there to call. Short-circuiting
	 * on the file means production never reaches `Hmr::server_is_up()` — it costs a customer
	 * nothing, and cannot fatal on a missing class.
	 *
	 * Tested with file_exists rather than class_exists for the same reason: touching the class
	 * name would send the autoloader looking for a file that is no longer there.
	 *
	 * NO PORT AND NO HOST APPEAR IN THIS FILE. Bundle.php ships; Hmr.php does not. Keeping every
	 * dev-origin literal inside the file that gets stripped is what makes "the released plugin
	 * contains no reference to localhost" a property of the layout rather than a thing to
	 * remember.
	 *
	 * THE PATH IS SPELLED OUT RATHER THAN DERIVED FROM `__DIR__`. It used to sit in `Core/Admin/`,
	 * so `__DIR__ . '/Hmr.php'` pointed at the right file for free. From `Core/App/` it would point
	 * at a file that has never existed — and `bin/release.ps1` deletes
	 * `includes/Core/Admin/Hmr.php` by that exact path, so Hmr.php cannot simply move here to make
	 * `__DIR__` work again. The failure would be silent: HMR just stops, and you go debugging Vite.
	 *
	 * THIS USED TO READ `WP_DEBUG && file_exists(...)`. See Hmr.php's docblock for why that was the
	 * wrong seam; the short version is that it tied fast refresh to PHP notice output, and this
	 * plugin's own dev site runs with WP_DEBUG off, so the dev server was never once consulted.
	 *
	 * @return bool
	 */
	private static function hmr_available(): bool {
		if ( ! file_exists( PLUGIXA_INVENTORY_PATH . 'includes/Core/Admin/Hmr.php' ) ) {
			return false;
		}

		/*
		 * An explicit constant wins, in both directions.
		 *
		 * ITS REAL JOB IS THE TEST SUITE, not developer convenience. Once this method reads a
		 * socket, `Bundle::enqueue()` takes a different branch depending on whether somebody
		 * happens to have `npm run dev` running in another window — a flake with no visible cause,
		 * in a suite whose wp-tests-config already sets WP_DEBUG. tests/bootstrap.php pins it
		 * false. Pinning it true is also useful: it exercises the dev path without a live server.
		 */
		if ( defined( 'PLUGIXA_INVENTORY_HMR' ) ) {
			return (bool) PLUGIXA_INVENTORY_HMR;
		}

		return Hmr::server_is_up();
	}

	/**
	 * Enqueue the compiled bundle, resolving the hashed entry through Vite's manifest.
	 */
	private static function enqueue_build(): void {
		$dir = self::build_dir();
		$url = self::build_url();

		$entry = self::manifest_entry( $dir );
		if ( null === $entry ) {
			return;
		}

		/*
		 * NO VERSION QUERY ON THE MODULE. THE FILENAME HASH IS THE CACHE-BUSTER.
		 *
		 * The entry is `js/main-<hash>.js` (see vite.config.js), and a `?ver=` on top of that would be
		 * worse than redundant — it would be fatal. A code-split chunk imports the entry back by its
		 * RELATIVE path, with no query string, so `main-<hash>.js` and `main-<hash>.js?ver=123` are two
		 * different URLs to the browser, and it loads the module twice: two module graphs, two copies of
		 * React, every side effect run twice. React then throws #321 the first time the lazily-loaded
		 * calendar calls a hook, and the entire admin white-screens.
		 *
		 * The content hash already changes whenever the bundle does, which is the whole job `?ver` was
		 * doing. The stylesheet below keeps its mtime version: it is not a module and nothing imports it.
		 */
		/*
		 * NO DEPENDENCIES. There used to be a list here — 22 `@plugixa/<name>` Script Module ids,
		 * registered from `src/runtime/*` chunks and declared as dynamic deps so WordPress would put
		 * them in the page's import map for separately-built add-on bundles to resolve against. The
		 * add-ons are part of this plugin now, so the map's only remaining consumer was this bundle
		 * importing from itself. See vite.config.js for what that mechanism was for, in case it is
		 * ever needed again.
		 */
		wp_enqueue_script_module(
			self::HANDLE,
			PLUGIXA_INVENTORY_URL . $dir . $entry['file'],
			array(),
			null
		);

		// cssCodeSplit is off, so the stylesheet has a stable name and is not in the manifest.
		if ( file_exists( PLUGIXA_INVENTORY_PATH . $dir . 'css/main.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$url . 'css/main.css',
				array(),
				self::asset_version( PLUGIXA_INVENTORY_PATH . $dir . 'css/main.css' )
			);
		}
	}

	/**
	 * The built application's asset URLs — the script module, and the stylesheet if there is one.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THIS EXISTS SO THE SERVICE WORKER PRECACHES WHAT THE PAGE ACTUALLY LOADS.
	 *
	 * `pro/Modules/Pwa` needs the same two URLs `enqueue_build()` above puts on the page, and the
	 * only honest way to get them is to ask the thing that resolves them. The alternative — a
	 * second copy of "read `.vite/manifest.json`, take `src/main.tsx`, prefer `pro/` if it is
	 * there" — is three rules that have to keep agreeing with these, in a file that is stripped from
	 * the free build and therefore never runs in the build most people install.
	 *
	 * When they disagree the failure is invisible and permanent: the worker caches a bundle nobody
	 * loads, the app works perfectly online, and offline it shows a blank screen.
	 *
	 * RETURNS AN EMPTY ARRAY WHEN THERE IS NO BUILD, rather than guessing at a filename. A caller
	 * with nothing to cache should cache nothing.
	 *
	 * @return array<int, string> Absolute URLs.
	 */
	public static function urls(): array {
		$dir   = self::build_dir();
		$entry = self::manifest_entry( $dir );

		if ( null === $entry ) {
			return array();
		}

		$urls = array( PLUGIXA_INVENTORY_URL . $dir . $entry['file'] );

		if ( file_exists( PLUGIXA_INVENTORY_PATH . $dir . 'css/main.css' ) ) {
			$urls[] = PLUGIXA_INVENTORY_URL . $dir . 'css/main.css';
		}

		return $urls;
	}

	/**
	 * Cache-busting version for a built asset.
	 *
	 * The file's own mtime, not the plugin version. The version only moves on release,
	 * so a rebuilt bundle keeps the same URL and the browser goes on serving the old one
	 * — which costs a confusing debugging session every single time. The mtime also
	 * covers the case the version misses in production: a corrective rebuild shipped
	 * without a version bump.
	 *
	 * Deliberately not gated on WP_DEBUG. Development sites do not reliably have it on
	 * (this plugin's own does not), so gating it there would leave the stale-bundle trap
	 * exactly where it hurts.
	 *
	 * @param string $path Absolute path to the built file.
	 */
	private static function asset_version( string $path ): string {
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;

		return false !== $mtime ? (string) $mtime : PLUGIXA_INVENTORY_VERSION;
	}

	/**
	 * Relative path to the compiled bundle: the premium one when it is on disk, otherwise the free one.
	 *
	 * THE RUNTIME HALF OF THE FREE/PREMIUM SPLIT, AND ALL OF IT. There is no licence call here and no
	 * flag to read. Vite writes two bundles to two directories — `PLUGIXA_TARGET=free` to
	 * `assets/backend/build`, the default to `pro/assets/backend/build` — and Freemius strips `pro/`
	 * when it generates the free download. So a premium install finds a premium bundle, a free
	 * install cannot, and neither had to be told which it is.
	 *
	 * WHY A DIRECTORY CHECK RATHER THAN A CONSTANT. The two builds differ only in whether the
	 * premium modules are reachable from the entry's module graph; both are named `src/main.tsx` and
	 * both write the same manifest key, so nothing downstream of here changes. Making the choice
	 * anywhere else would mean a second place that has to agree about what is installed.
	 *
	 * This check was removed in 3.2.0, when the paid modules left for sibling plugins and the
	 * premium directory genuinely could not exist — at which point a check for it was worse than
	 * none, because it invited the reader to believe there was a premium build to find. The premium
	 * tree is back (`docs/INTEGRATIONS-ROADMAP.md` §2), so the check is back with it.
	 */
	/**
	 * The built entry's manifest record, or null when there is no usable build.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * ONE READER, BECAUSE THERE USED TO BE TWO AND THEY BOTH HAD THE SAME BUG.
	 *
	 * `enqueue_build()` and `urls()` each carried their own copy of "read the manifest, take
	 * `src/main.tsx`, give up quietly if it is not there" — the exact duplication `urls()`'s own
	 * docblock warns about for the service worker. When the manifest stopped being packaged, both
	 * copies started returning nothing, separately.
	 *
	 * TWO PATHS, AND THE ORDER MATTERS. Vite now writes `manifest.json` at the root of the build
	 * directory (see vite.config.js for why it must not be hidden). The legacy `.vite/manifest.json`
	 * is still accepted, because a checkout that pulls this change with a build directory produced
	 * by the OLD config would otherwise render a blank admin screen — the same silent failure this
	 * whole change exists to remove, reintroduced by the fix for it.
	 *
	 * Not a fallback that hides anything: a shipped ZIP cannot contain the dot path at all, so the
	 * second branch only ever fires on a developer's stale tree.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $dir Build directory, relative to the plugin root, with a trailing slash.
	 *
	 * @return array{file: string}|null
	 */
	private static function manifest_entry( string $dir ): ?array {
		$candidates = array(
			PLUGIXA_INVENTORY_PATH . $dir . 'manifest.json',
			PLUGIXA_INVENTORY_PATH . $dir . '.vite/manifest.json',
		);

		foreach ( $candidates as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$manifest = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reads Vite's manifest from this plugin's own build directory. WP_Filesystem is for user-writable paths and would ask the host for FTP credentials to read a file the plugin shipped.
			$entry    = is_array( $manifest ) ? ( $manifest['src/main.tsx'] ?? null ) : null;

			if ( is_array( $entry ) && ! empty( $entry['file'] ) ) {
				return array( 'file' => (string) $entry['file'] );
			}
		}

		return null;
	}

	private static function build_dir(): string {
		return is_dir( PLUGIXA_INVENTORY_PATH . 'pro/assets/backend/build' )
			? 'pro/assets/backend/build/'
			: 'assets/backend/build/';
	}

	private static function build_url(): string {
		return PLUGIXA_INVENTORY_URL . self::build_dir();
	}
}
