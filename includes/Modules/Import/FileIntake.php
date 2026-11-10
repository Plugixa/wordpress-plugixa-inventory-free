<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import;

use PlugixaInventory\Core\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taking an uploaded CSV, or refusing it — the security boundary, in one place.
 *
 * ONE EXTENSION, AND IT IS STILL AN ALLOW-LIST. The sibling plugin this ports allows fifteen types
 * because it is a document library; this accepts a spreadsheet and nothing else, so the list has one
 * entry. It stays an allow-list rather than becoming an `if` because the shape is what makes it
 * safe: a deny-list of dangerous extensions fails open by construction — you forget `.phtml`, or
 * `.php5`, or whatever the next handler is called on somebody's server, and the hole is the one you
 * did not think of. Here, a type nobody listed simply is not a file.
 *
 * BOTH HALVES ARE CHECKED, because either one alone is a hole. An extension check alone trusts the
 * name, so `evil.php` renamed `data.csv` walks in. A mime check alone would admit `evil.php`
 * unrenamed, because the contents of a PHP file sniff as `text/plain` — which is exactly what a CSV
 * does too. Neither check subsumes the other, and the second one is the reason the allow-list maps
 * an extension to the set of mime types its BYTES are permitted to have rather than to `true`.
 *
 * It knows nothing about runs or entities. It takes one entry from `$_FILES` and returns either a
 * `WP_Error` or the three facts a caller needs to write a row: the display name, the on-disk name,
 * and the size. Storing that tuple, and cleaning up the bytes if the row write then fails, is the
 * caller's job — this only owns the file.
 */
final class FileIntake {

	/**
	 * What may be uploaded, and what it is allowed to actually BE.
	 *
	 * `text/plain` is on the list because that is what `finfo` says about a CSV more often than not
	 * — a comma-separated file has no magic bytes, so it is indistinguishable from any other text.
	 * That is a real limit of sniffing rather than a concession: it means the byte check cannot
	 * separate a CSV from a PHP file, and the EXTENSION check is what does that. The two together
	 * are the gate; neither is the gate on its own.
	 *
	 * @var array<string, string[]>
	 */
	private const ALLOWED = array(
		'csv' => array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' ),
	);

	/** Megabytes, unless the site says otherwise. */
	public const DEFAULT_MAX_MB = 10;

	/**
	 * Take an uploaded file, or refuse it.
	 *
	 * The whole gauntlet, in order: the upload arrived intact and is within size, its extension is
	 * on the allow-list AND its bytes are a mime that extension may have, and it moved into private
	 * storage.
	 *
	 * @param array<string, mixed> $file One entry from $_FILES.
	 *
	 * @return array{original_name: string, disk_name: string, size: int}|WP_Error
	 */
	public function store( array $file ): array|WP_Error {
		$invalid = $this->validate( $file );

		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$sniffed = $this->sniff( (string) $file['tmp_name'], (string) $file['name'] );

		if ( $sniffed instanceof WP_Error ) {
			return $sniffed;
		}

		$disk_name = Storage::put( $file );

		if ( $disk_name instanceof WP_Error ) {
			return $disk_name;
		}

		return array(
			/*
			 * sanitize_TEXT_field, not sanitize_FILE_name. This string is display only: it is
			 * rendered on the import screen and never touches the filesystem — the file on disk is
			 * 32 random hex characters. Running it through the filesystem sanitiser would turn
			 * "Q3 stock count.csv" into "Q3-stock-count.csv" and hand the user back a name they did
			 * not choose, to protect a filesystem that is never going to see it.
			 *
			 * It is still cleaned: tags stripped, control characters and line breaks removed — the
			 * latter being what would otherwise let a crafted name inject a second header.
			 */
			'original_name' => mb_substr( sanitize_text_field( (string) $file['name'] ), 0, 255 ),
			'disk_name'     => $disk_name,
			'size'          => (int) $file['size'],
		);
	}

	/**
	 * The upload arrived, is not empty, and is within both limits.
	 *
	 * @param array<string, mixed> $file One entry from $_FILES.
	 */
	private function validate( array $file ): ?WP_Error {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return new WP_Error(
				'plugixa_inventory_file_too_big',
				sprintf(
					/* translators: %s: a formatted file size, e.g. "10 MB". */
					__( 'That file is larger than this server accepts (%s).', 'plugixa-inventory' ),
					size_format( (float) wp_max_upload_size() )
				),
				array( 'status' => 422 )
			);
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error(
				'plugixa_inventory_upload_failed',
				__( 'The file did not upload correctly. Try again.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$size = (int) ( $file['size'] ?? 0 );

		if ( $size <= 0 ) {
			return new WP_Error(
				'plugixa_inventory_empty_file',
				__( 'That file is empty.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		// The smaller of the two is always the real one: the host's ceiling can change under a
		// stored setting, and a setting above it would promise something PHP will refuse anyway.
		$configured = (int) Settings::get( 'import_max_upload_mb', self::DEFAULT_MAX_MB ) * MB_IN_BYTES;
		$ceiling    = min( $configured, (int) wp_max_upload_size() );

		if ( $size > $ceiling ) {
			return new WP_Error(
				'plugixa_inventory_file_too_big',
				sprintf(
					/* translators: %s: a formatted file size, e.g. "10 MB". */
					__( 'That file is larger than the %s limit for imports.', 'plugixa-inventory' ),
					size_format( (float) $ceiling )
				),
				array( 'status' => 422 )
			);
		}

		if ( ! array_key_exists( $this->extension_of( (string) ( $file['name'] ?? '' ) ), self::ALLOWED ) ) {
			return new WP_Error(
				'plugixa_inventory_not_csv',
				__( 'Import expects a CSV file. Save your spreadsheet as CSV and try again.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		return null;
	}

	/**
	 * What the bytes actually are, checked against what the extension is allowed to be.
	 *
	 * @param string $tmp_path The PHP temp file.
	 * @param string $name     The name the browser sent.
	 *
	 * @return string|WP_Error The sniffed mime.
	 */
	private function sniff( string $tmp_path, string $name ): string|WP_Error {
		$extension = $this->extension_of( $name );

		$checked = wp_check_filetype_and_ext( $tmp_path, $name, array( 'csv' => 'text/csv' ) );

		$mime = (string) ( $checked['type'] ?: '' );

		if ( '' === $mime && function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );

			if ( false !== $finfo ) {
				$mime = (string) finfo_file( $finfo, $tmp_path );

				finfo_close( $finfo );
			}
		}

		/*
		 * A SERVER THAT CANNOT SNIFF IS NOT A REASON TO WAVE THE FILE THROUGH, but it is also not a
		 * reason to refuse every import on that host. `finfo` is compiled in almost everywhere and
		 * missing often enough to matter. With no opinion available, the extension check above is
		 * the whole gate — which is the same position a `.csv` allow-list would be in anyway, since
		 * a CSV has no magic bytes to check.
		 */
		if ( '' === $mime ) {
			return 'text/plain';
		}

		if ( ! in_array( strtolower( $mime ), self::ALLOWED[ $extension ], true ) ) {
			return new WP_Error(
				'plugixa_inventory_type_mismatch',
				__( 'That file is named as a CSV but does not contain one.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		return $mime;
	}

	/**
	 * The lower-cased extension of a file name, sanitised first.
	 *
	 * @param string $name The name the browser sent.
	 */
	private function extension_of( string $name ): string {
		return strtolower( (string) pathinfo( sanitize_file_name( $name ), PATHINFO_EXTENSION ) );
	}
}
