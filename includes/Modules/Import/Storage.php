<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the uploaded spreadsheets live, and why nobody can read them.
 *
 * WordPress's media library puts an upload at a public, guessable URL. A supplier price list at
 * /wp-content/uploads/2026/09/prices.csv is readable by anyone who types it — no cookie, no
 * capability, no log entry. So the file IS stored by `wp_handle_upload()`, with every check and
 * filter WordPress runs on an upload, but with its destination redirected for that one call into a
 * private directory — and it is never made into an attachment, so the media library never hears of
 * it. `wp_insert_attachment()` is what publishes a file; the uploader on its own does not.
 *
 * There are three independent barriers, and they are independent on purpose:
 *
 *  1. THE NAME IS UNGUESSABLE. A file is stored as 32 hex characters from `random_bytes(16)` with a
 *     `.bin` extension — never the name the user gave it, and never their extension. Somebody who
 *     knows the directory exists still cannot ask for a file, because there is nothing to guess:
 *     128 bits is 128 bits. This is the barrier that does not depend on the web server agreeing
 *     with us, and it is the only one nginx cannot ignore.
 *
 *  2. THE EXTENSION IS INERT. `evil.php` lands as `9f3c….bin`. Even if the directory were served,
 *     and even if the deny rules were gone, Apache has no handler for `.bin` and will not execute
 *     it. An upload endpoint that writes a `.php` file into a served directory is remote code
 *     execution, and it is the single most common way this feature is got wrong.
 *
 *  3. THE DIRECTORY IS DENIED. `.htaccess` for Apache, `web.config` for IIS, and an `index.php` so
 *     a mis-set server cannot list it. This is the barrier everyone thinks of first and it is the
 *     weakest of the three: nginx does not read `.htaccess` at all. Defence in depth, not the
 *     defence.
 *
 * THIS LIVES IN THE MODULE RATHER THAN IN CORE, and should move the day a second thing stores a
 * file. Nothing else in this plugin does — there is not one `$_FILES` anywhere else in the tree —
 * so putting it in Core would be Core promising a capability the plugin does not have, for one
 * caller. The precedent for the move is `Core\Http\RawResponse`, which belonged to the CSV export
 * until PDF printing made it the second caller and only then became shared.
 */
final class Storage {

	/** Under uploads, so it is outside the plugin folder and cannot be shipped in the ZIP. */
	private const DIR = 'plugixa-inventory-private';

	/** What a stored name looks like. Anything else is not ours and will not be opened. */
	private const DISK_NAME = '/^[a-f0-9]{32}\.bin$/';

	/**
	 * The private directory. May not exist yet — call ensure().
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::DIR;
	}

	/**
	 * Create the directory and its guard files. Idempotent.
	 */
	public static function ensure(): bool {
		$dir = self::dir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		foreach ( self::guards() as $file => $contents ) {
			$path = $dir . '/' . $file;

			/*
			 * Written only when missing or different — this runs on every version drift, and
			 * rewriting three files on every boot for no reason is its own small bug.
			 *
			 * The parent sniff name rather than the sub-code: WPCS 3.x renamed these and the old
			 * ignores silently stopped matching, which is worse than no ignore because it looks
			 * handled. `WP_Filesystem` is wrong here anyway — this writes the deny rules that
			 * PROTECT the directory, during a boot where asking for filesystem credentials is not
			 * possible.
			 */
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- see above.
			if ( ! file_exists( $path ) || file_get_contents( $path ) !== $contents ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- see above.
				file_put_contents( $path, $contents );
			}
		}

		return true;
	}

	/**
	 * The guard files.
	 *
	 * The Apache block covers 2.4 (`Require all denied`) and 2.2 (`Deny from all`) — they are not
	 * interchangeable, and an .htaccess with only one of them is either ignored or a 500 depending
	 * on which server it lands on.
	 *
	 * @return array<string, string>
	 */
	public static function guards(): array {
		return array(
			'.htaccess'  => "# Files here are private. They are read only by the import routines,\n"
				. "# which check who you are first. Nothing may read them directly.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",

			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration>\n\t<system.webServer>\n\t\t<authorization>\n"
				. "\t\t\t<deny users=\"*\" />\n"
				. "\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",

			'index.php'  => "<?php\n// Silence is golden.\n",
		);
	}

	/**
	 * A fresh name for a file on disk.
	 *
	 * NOT derived from the original name, its extension, its contents or its mime type. Anything
	 * derived is guessable by someone who knows the input, and the whole first barrier is that
	 * there is nothing to know.
	 */
	public static function new_disk_name(): string {
		return bin2hex( random_bytes( 16 ) ) . '.bin';
	}

	/**
	 * The absolute path of a stored file — or null if the name is not one of ours.
	 *
	 * THE TRAVERSAL GUARD. `disk_name` reaches this from a database row, and a database row is not
	 * a promise. If a value like `../../../wp-config.php` ever got into that column — through a bug
	 * here, or a compromised row — then a reader that trusted it would happily parse the site's
	 * database credentials and report them back as CSV column headings.
	 *
	 * So the name is matched against the exact shape this class writes, and nothing else is a file.
	 * A pattern check alone would be enough; the `realpath()` containment check is here anyway,
	 * because the cost of being wrong is the whole site.
	 *
	 * @param string $disk_name The stored name.
	 */
	public static function path( string $disk_name ): ?string {
		if ( 1 !== preg_match( self::DISK_NAME, $disk_name ) ) {
			return null;
		}

		$dir  = self::dir();
		$path = $dir . '/' . $disk_name;

		$real     = realpath( $path );
		$real_dir = realpath( $dir );

		if ( false === $real || false === $real_dir ) {
			return null;
		}

		// Belt and braces: the resolved path must still be inside the directory. A symlink planted
		// in the folder is the one thing the pattern check above cannot see.
		if ( ! str_starts_with( $real, $real_dir . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * Which of WordPress's upload handlers stores the file. Null means `wp_handle_upload()`.
	 *
	 * @var callable|null
	 */
	private static $handler = null;

	/**
	 * Swap the handler. TESTS ONLY — and it grants nothing.
	 *
	 * `wp_handle_upload()` refuses any file PHP did not itself receive as an upload in the current
	 * request. That is not an inconvenience, it is the point: without it, a caller who could
	 * influence `tmp_name` — through a bug anywhere upstream — could name a path of its own choosing,
	 * and we would obligingly store `wp-config.php` in the folder the importer then reads and echoes
	 * back as column headings. But a test is not an HTTP upload, so without a seam no test could
	 * reach `put()` at all.
	 *
	 * Tests pass `wp_handle_sideload`. It is the SAME core pipeline — the same type check, the same
	 * `upload_dir` redirect, the same name callback, and the same landing check afterwards — with one
	 * line different: a readable file is accepted where the default asks for a real upload. So a
	 * test through this seam covers everything in `put()` except that one line of core.
	 *
	 * It is not a hole. Anyone able to call this static method is already executing arbitrary PHP
	 * inside the process, and could write to the directory directly without asking us. What it must
	 * never become is an `apply_filters()`, which any plugin on the site could reach.
	 *
	 * `ImportIntakeTest::test_a_file_that_was_not_uploaded_is_refused()` asserts the default still
	 * says no, so this seam cannot quietly become the production one.
	 *
	 * @param callable|null $handler Core's signature: fn(array &$file, array $overrides): array.
	 */
	public static function use_handler( ?callable $handler ): void {
		self::$handler = $handler;
	}

	/**
	 * Store an uploaded file privately. Returns its new disk name, or why it was refused.
	 *
	 * @param array<string, mixed> $file One entry from $_FILES.
	 */
	public static function put( array $file ): string|WP_Error {
		if ( ! self::ensure() ) {
			return self::not_stored();
		}

		// REST requests do not load the admin file API, and that is where the uploader lives.
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Chosen before the call, so the landing check below knows exactly what to expect.
		$disk_name = self::new_disk_name();

		/*
		 * THE REDIRECT BUILDS ITS PATH FROM WHAT IT IS HANDED, AND MUST NEVER CALL self::dir().
		 * dir() asks wp_upload_dir(), which runs this very filter, which would call dir() again — a
		 * recursion that ends only when the stack does.
		 *
		 * PHP_INT_MAX so it runs last. A plugin that files uploads by year, by user or by post type is
		 * right to do that for the media library, and must not get to decide where this lands.
		 */
		$private = static function ( array $uploads ): array {
			$uploads['subdir'] = '/' . self::DIR;
			$uploads['path']   = trailingslashit( (string) $uploads['basedir'] ) . self::DIR;
			$uploads['url']    = trailingslashit( (string) $uploads['baseurl'] ) . self::DIR;

			return $uploads;
		};

		$overrides = array(
			// A REST request, not an admin form post: there is no form action to compare.
			'test_form'                => false,
			// A spreadsheet and nothing else, and WordPress checks the bytes against that too.
			'mimes'                    => array( 'csv' => 'text/csv' ),
			'unique_filename_callback' => static fn(): string => $disk_name,
		);

		// Removed in a `finally`, as `Quiet::post()` does, so the redirect cannot outlive this call —
		// not on a refusal and not on a throw. Left behind, every later upload in the request would
		// land in here instead of the media library.
		add_filter( 'upload_dir', $private, PHP_INT_MAX );

		try {
			$result = ( self::$handler ?? 'wp_handle_upload' )( $file, $overrides );
		} finally {
			remove_filter( 'upload_dir', $private, PHP_INT_MAX );
		}

		if ( ! is_array( $result ) || isset( $result['error'] ) ) {
			return self::upload_error( is_array( $result ) ? (string) $result['error'] : '' );
		}

		/*
		 * CHECK WHERE IT ACTUALLY LANDED. The uploader runs filters after choosing the name — on the
		 * name itself, before the move and on the final result — and any plugin on the site can hook
		 * them. One that renamed the file to `prices.php` would undo barriers 1 and 2
		 * while reporting success, so success means: our name, in our directory, and nothing else.
		 */
		$expected = self::path( $disk_name );
		$landed   = isset( $result['file'] ) ? realpath( (string) $result['file'] ) : false;

		if ( null !== $expected && $landed === $expected ) {
			return $disk_name;
		}

		self::discard( $disk_name, false === $landed ? '' : $landed );

		return self::not_stored();
	}

	/**
	 * What the importing user is told when WordPress refuses the upload.
	 *
	 * WORDPRESS'S OWN WORDS, NOT OURS. The refusal is almost always the site's policy — a type the
	 * site does not allow, a full quota, a security plugin's prefilter — and the person who can fix
	 * it needs to know which. Core has already translated it. 422, because the file or the site's
	 * rules refused it; a handler that answered nothing recognisable is the server's fault instead.
	 *
	 * @param string $message WordPress's own reason. Already translated; empty if the handler
	 *                        returned something unrecognisable.
	 */
	private static function upload_error( string $message ): WP_Error {
		if ( '' === trim( $message ) ) {
			return self::not_stored();
		}

		return new WP_Error(
			'plugixa_inventory_upload_refused',
			wp_strip_all_tags( $message ),
			array( 'status' => 422 )
		);
	}

	/**
	 * The generic refusal, for failures that are the server's rather than the file's.
	 */
	private static function not_stored(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_storage_failed',
			__( 'The file could not be stored.', 'plugixa-inventory' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Clean up an upload that did not land as sent.
	 *
	 * A path a filter reported is deleted ONLY if it sits directly inside this directory. Anywhere
	 * else it may be somebody else's file, and "a filter said so" is not a reason to delete it.
	 * The guard files are never deleted; a rename onto one of them overwrote it, and ensure() puts
	 * it back.
	 *
	 * @param string $disk_name The name the file was sent under.
	 * @param string $landed    The resolved path the uploader reported, or ''.
	 */
	private static function discard( string $disk_name, string $landed ): void {
		self::delete( $disk_name );

		$real_dir = realpath( self::dir() );

		if (
			'' !== $landed
			&& false !== $real_dir
			&& dirname( $landed ) === $real_dir
			&& ! array_key_exists( basename( $landed ), self::guards() )
		) {
			wp_delete_file( $landed );
		}

		self::ensure();
	}

	/**
	 * Remove a stored file. Silent if it is already gone.
	 *
	 * @param string $disk_name The stored name.
	 */
	public static function delete( string $disk_name ): void {
		$path = self::path( $disk_name );

		if ( null !== $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Destroy the whole directory. Uninstall only.
	 *
	 * **`glob( $dir . '/*' )` DOES NOT MATCH DOTFILES, AND THIS DIRECTORY ALWAYS CONTAINS ONE.**
	 * The sibling plugin this ports enumerated with `glob()`, so `.htaccess` — the deny rule that
	 * barrier 3 writes on every boot — survived every uninstall. The directory was therefore never
	 * empty, `rmdir()` always failed, and because the failure was swallowed nothing ever said so:
	 * uninstalling left the private directory on disk with the customer's files still in it. For a
	 * directory whose entire purpose is storing things nobody should be able to read, silently
	 * outliving its own uninstall is the wrong failure.
	 *
	 * `scandir()` sees dotfiles. It is also why `rmdir()` can be trusted here.
	 */
	public static function destroy(): void {
		$dir = self::dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- removing the plugin's own now-empty private dir; WP_Filesystem would need credentials we cannot ask for here.
		rmdir( $dir );
	}
}
