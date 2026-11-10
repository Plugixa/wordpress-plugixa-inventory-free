<?php

/**
 * Plugin Name: Plugixa Inventory
 * Plugin URI: https://www.plugixa.com/plugins/plugixa-inventory
 * Description: Modular inventory management for WordPress — items, warehouses, stock movements, an append-only stock ledger, CSV import and export, and a React admin application.
 * Version: 1.0.0
 * Author: Plugixa
 * Author URI: https://www.plugixa.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: plugixa-inventory
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.2
 *
 * @package   PlugixaInventory
 * @copyright 2026 Plugixa (https://plugixa.com)
 *
 * LICENCE IS GPL-2.0-OR-LATER, WHICH IS A DELIBERATE DIFFERENCE FROM PLUGIXA CRM. The CRM pins
 * itself to GPL-2.0-only because it bundles mpdf/mpdf, which upstream licenses that way, so the
 * combined work can only be conveyed under GPL-2.0 exactly. This plugin has no GPL-2.0-only
 * dependency: reports print from the browser, exactly as in the Joomla component they were ported
 * from, so there is no server-side PDF library and no constraint to inherit. If a PDF renderer is
 * ever vendored, this line has to be re-examined before it lands, not after.
 *
 * EVERY FEATURE THIS PLUGIN ADVERTISES IS IN THIS FILE'S OWN DIRECTORY TREE AND WORKS. What lives
 * under pro/ is ADDITIONAL code, not a locked copy of anything free. Freemius strips the
 * contain that code at all. Absent code cannot be "unlocked", which is the whole distinction
 * between an upsell and trialware, and here it is true by construction rather than by promise.
 *
 * WHICH IS WHY THE DESCRIPTION ABOVE NAMES ONLY WHAT THE FREE BUILD HAS. This header ships in BOTH
 * artifacts — Freemius subtracts paths, not lines of a docblock — so a Description listing
 * transfers, adjustments and FIFO/LIFO costing would be the free download advertising features it
 * does not contain. It used to list all three.
 *
 * Feature availability is decided by CODE PRESENCE and never by a licence check. There is
 * deliberately no License class anywhere in this plugin, and there must never be one — a sibling
 * Plugixa plugin was rejected from wordpress.org as trialware for exactly that.
 *
 * WHY TWO PATHS AND NOT ONE. The premium PHP and its compiled admin bundle both sit under pro/.
 * The premium admin REACT SOURCE cannot: Vite and tsc resolve node_modules by walking up from the
 * importing file, so source outside assets/backend/app/ cannot resolve react or @mui/*, and the
 * build fails. It therefore lives at assets/backend/app/src/pro/ and needs its own strip entry.
 * Removing either path leaks premium code into the free build.
 *
 */
declare (strict_types = 1);
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/*
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * ONE COPY AT A TIME. IF ANOTHER COPY OF THIS PLUGIN ALREADY LOADED, THIS ONE STEPS ASIDE.
 *
 * Buying the premium build installs it as a SECOND plugin beside the free one, and Freemius
 * deactivates the free copy when the premium one is activated — but only when that activation
 * happens inside wp-admin, because the SDK registers that hook behind `is_admin()`. Activate the
 * premium copy from WP-CLI, a hosting panel or a site-management dashboard and both copies stay
 * active. Both files then run on every request: six constants defined twice, six PHP warnings per
 * page load, and on a host that displays warnings those land inside REST responses and the app
 * cannot parse its own API. That was reproduced on Linux, not imagined.
 *
 * Whichever copy WordPress loads first owns the request — plugins load in `active_plugins` order —
 * and the other returns before defining anything. Nothing else in either copy runs, so there is
 * nothing to register twice, and an administrator is told which copy was ignored.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
if ( defined( 'PLUGIXA_INVENTORY_PATH' ) ) {
    $plugixa_inventory_ignored_copy = plugin_basename( __FILE__ );
    add_action( 'admin_notices', static function () use($plugixa_inventory_ignored_copy) : void {
        if ( !current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        printf( 
            /* translators: %s: the plugin file that was not loaded, e.g. "plugixa-inventory/plugixa-inventory.php". */
            esc_html__( 'Two copies of Plugixa Inventory are active. Only one can run, so %s was not loaded. Deactivate the copy you no longer need — your inventory data is shared and stays as it is.', 'plugixa-inventory' ),
            '<code>' . esc_html( $plugixa_inventory_ignored_copy ) . '</code>'
         );
        echo '</p></div>';
    } );
    return;
}
define( 'PLUGIXA_INVENTORY_VERSION', '1.0.0' );
define( 'PLUGIXA_INVENTORY_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLUGIXA_INVENTORY_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUGIXA_INVENTORY_BASENAME', plugin_basename( __FILE__ ) );
/*
 * FREEMIUS CREDENTIALS, real since the plugin was registered at freemius.com/dashboard.
 *
 * They are constants rather than literals inside the fs_dynamic_init() array for one reason: the
 * release script has to find the id, and a named constant at the top of the file is something a
 * regex can match without having to parse an array it might reformat. `bin/release.ps1` keys its
 * safety guard off this exact define — see below.
 *
 * NEITHER OF THESE IS A SECRET. The id and the PUBLIC key are published in the plugin's own
 * download; that is what "public_key" means. The secret key is never in this repository at all: it
 * lives in the wp-config.php of the machine doing the testing, alongside WP_FS__DEV_MODE.
 *
 * `wp_org_gatekeeper` IS a secret, and it deliberately did NOT move up here beside them — see the
 * note on it in plugixa_inventory_fs() for why the literal has to stay where Freemius put it.
 *
 * THE MOMENT THESE STOPPED READING '00000', `bin/release.ps1` STARTED PROTECTING dist/. It used to
 * refuse to write there at all, which was the wrong answer to a real hazard: dist/ is inside the
 * webroot, so a built ZIP is a URL, and the PREMIUM one carries the gatekeeper. Refusing did not
 * close that door -- a stale artifact at that path was still answering 200 on 2026-09-15 -- it only
 * declined to add to it, at the cost of the dist/ layout the rest of the family uses. The script now
 * writes an .htaccess denying the folder and verifies it, which a `curl` can check; and the FREE
 * artifact has the gatekeeper line stripped out of it before it is zipped (`Set-FreeVariant`), so
 * the secret is only ever in the premium ZIP, which goes to Freemius rather than to anyone.
 */
define( 'PLUGIXA_INVENTORY_FS_ID', '39070' );
define( 'PLUGIXA_INVENTORY_FS_KEY', 'pk_f00412fc20a19974f9d06ad61e42c' );
/**
 * Core Composer autoloader: the Freemius SDK plus the PSR-4 root
 * `PlugixaInventory\` => includes/.
 */
$plugixa_inventory_autoload = PLUGIXA_INVENTORY_PATH . 'vendor/autoload.php';
if ( !file_exists( $plugixa_inventory_autoload ) ) {
    add_action( 'admin_notices', static function () : void {
        echo '<div class="notice notice-error"><p>';
        esc_html_e( 'Plugixa Inventory: dependencies are missing. Run "composer install" in the plugin directory.', 'plugixa-inventory' );
        echo '</p></div>';
    } );
    return;
}
require_once $plugixa_inventory_autoload;
/*
 * THE OVERLAY CLASS LOADERS, AND WHY THEY ARE NOT LINES IN composer.json.
 *
 * `"PlugixaInventory\\Pro\\": "pro/includes/"` in the PSR-4 block looks equivalent and is not,
 * because this project builds with `optimize-autoloader`. That turns every PSR-4 root into a
 * CLASSMAP at `composer dump-autoload` time, and the premium classes are on disk when the release
 * is built -- so the map that ships would name them. Two consequences, both bad, and neither
 * obvious from the composer.json line:
 *
 *   1. The free download's vendor/composer/autoload_classmap.php would list premium class names
 *      and pro/ paths. The free build is supposed to contain no premium identifier at all; a
 *      reviewer greps, and finds the vocabulary of features that are not there.
 *   2. Composer's ClassLoader::findFile() returns a classmap hit WITHOUT checking the file exists
 *      (see vendor/composer/ClassLoader.php), and loadClass() then does a bare `include`. Once
 *      Freemius strips pro/, every class_exists() on a premium class would emit a warning against
 *      a path that is gone.
 *
 * A prefix loader has neither problem: it is guarded by is_readable(), so a missing tree is simply
 * a class that does not exist -- which is the whole overlay model stated in eight lines.
 *
 * TWO PREFIXES, TWO TREES, ONE MECHANISM, AND THEY ANSWER DIFFERENT QUESTIONS.
 *
 *   PlugixaInventory\Pro\ -> pro/includes/   "has this site paid"    stripped from the free ZIP
 *   PlugixaInventory\Dev\ -> dev/includes/   "is this a working copy"  in NEITHER ZIP
 *
 * `dev/` holds tooling for building and testing this plugin -- today the demo-data installer,
 * which writes forty items and two months of movements through the real services. It is not a
 * premium feature withheld from free; it is a thing no customer of either build should be able to
 * point at a live inventory. bin/release.ps1 packages by allow-list and `dev` is not on it.
 *
 * Both namespaces are named here rather than in includes/, deliberately: CoreIndependenceTest
 * forbids a file under includes/ from naming code that may not be on disk, because there that is a
 * fatal error rather than a design smell. Registering a loader is the one thing that has to know
 * the name.
 */
spl_autoload_register( static function ( string $plugixa_inventory_class ) : void {
    $plugixa_inventory_roots = array(
        'PlugixaInventory\\Pro\\' => 'pro/includes/',
        'PlugixaInventory\\Dev\\' => 'dev/includes/',
    );
    foreach ( $plugixa_inventory_roots as $prefix => $dir ) {
        if ( 0 !== strncmp( $prefix, $plugixa_inventory_class, strlen( $prefix ) ) ) {
            continue;
        }
        $relative = substr( $plugixa_inventory_class, strlen( $prefix ) );
        $file = PLUGIXA_INVENTORY_PATH . $dir . str_replace( '\\', '/', $relative ) . '.php';
        if ( is_readable( $file ) ) {
            require_once $file;
        }
        return;
    }
} );
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Freemius mandates this helper/global naming.
if ( !function_exists( 'plugixa_inventory_fs' ) ) {
    /**
     * Freemius SDK accessor.
     *
     * RETURNS NULL UNTIL THE PLUGIN IS REGISTERED WITH FREEMIUS, AND THAT IS NOT A FEATURE GATE.
     * It is a bootstrap guard, the same kind as the `vendor/autoload.php` check above: until
     * `composer install` has run AND the credentials below are real, the plugin still has to
     * activate so the rest of it can be built and tested. Nothing about which FEATURES exist is
     * decided here — that is `pro/` being on disk, and only that.
     *
     * THE PLACEHOLDER CHECK IS NOT BELT-AND-BRACES. `fs_dynamic_init()` with a made-up id does not
     * fail quietly: the SDK registers an install against it and starts talking to the Freemius API
     * on admin page loads, so a fake id produces real network calls and a broken opt-in screen.
     * Checking that the id has been filled in is what makes the scaffold safe to run.
     *
     * Both branches disappear the day the real credentials land — the guard removes itself by
     * being false. Do not "simplify" either one into an is_premium() test.
     *
     * @return Freemius|null
     */
    function plugixa_inventory_fs() {
        global $plugixa_inventory_fs;
        if ( !isset( $plugixa_inventory_fs ) ) {
            $plugixa_inventory_sdk = PLUGIXA_INVENTORY_PATH . 'vendor/freemius/wordpress-sdk/start.php';
            if ( !file_exists( $plugixa_inventory_sdk ) || '00000' === PLUGIXA_INVENTORY_FS_ID ) {
                return null;
            }
            require_once $plugixa_inventory_sdk;
            $plugixa_inventory_fs = fs_dynamic_init( array(
                'id'               => PLUGIXA_INVENTORY_FS_ID,
                'slug'             => 'plugixa-inventory',
                'type'             => 'plugin',
                'public_key'       => PLUGIXA_INVENTORY_FS_KEY,
                'is_premium'       => false,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'menu'             => array(
                    'slug'    => 'plugixa-inventory',
                    'support' => false,
                ),
                'is_live'          => true,
            ) );
            // No trial is offered. Suppresses the trial banner, the trial menu item and the trial
            // CTA, so the free build advertises only "Upgrade".
            $plugixa_inventory_fs->add_filter( 'show_trial', '__return_false' );
        }
        return $plugixa_inventory_fs;
    }

    plugixa_inventory_fs();
    do_action( 'plugixa_inventory_fs_loaded' );
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
register_activation_hook( __FILE__, array(\PlugixaInventory\Core\Activator::class, 'activate') );
register_deactivation_hook( __FILE__, array(\PlugixaInventory\Core\Deactivator::class, 'deactivate') );
/*
 * Freemius ships its own uninstall.php and forbids the plugin from bundling one, so cleanup is
 * registered through the SDK rather than as a root uninstall.php.
 *
 * "CLEANUP" MEANS "DECIDE", NOT "DROP". `Uninstaller::uninstall()` keeps the inventory unless an
 * administrator switched erasing on, and keeps it even then while another copy of the plugin is
 * installed. Read its docblock before changing either hook below.
 *
 * The else branch is the same bootstrap guard as the accessor, not a second code path worth
 * keeping: with no SDK on disk there is no `after_uninstall` to hook, and dropping the tables on
 * uninstall still has to work while the plugin is being built.
 */
$plugixa_inventory_sdk_instance = plugixa_inventory_fs();
if ( null !== $plugixa_inventory_sdk_instance ) {
    $plugixa_inventory_sdk_instance->add_action( 'after_uninstall', static function () : void {
        \PlugixaInventory\Core\Uninstaller::uninstall();
    } );
} else {
    register_uninstall_hook( __FILE__, array(\PlugixaInventory\Core\Uninstaller::class, 'uninstall') );
}
add_action( 'plugins_loaded', static function () : void {
    \PlugixaInventory\Core\Plugin::instance()->boot();
} );