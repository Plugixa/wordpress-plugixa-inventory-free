<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reading a CSV somebody exported from something else.
 *
 * IT STREAMS, AND THAT IS NOT AN OPTIMISATION. `file_get_contents()` on a 200 MB export puts 200 MB
 * into PHP's memory limit before a single row is examined, and that limit is shared with everything
 * else in the request. `fgetcsv()` on a handle reads one line at a time, so a file larger than the
 * memory limit still imports.
 *
 * THE BOM IS STRIPPED. Excel writes UTF-8 files with a byte-order mark, so the first heading of
 * every file Excel produced is `\xEF\xBB\xBFsku` rather than `sku` — which maps to nothing,
 * silently, and only for the first column. It is the single most common reason an import "works"
 * and leaves every record missing its name.
 *
 * THE ESCAPE CHARACTER IS EXPLICITLY EMPTY, matching the writer on the export side. PHP's default
 * backslash escaping is not RFC 4180 and is not what any spreadsheet produces: with it, a cell
 * containing a Windows path loses characters. Passing `''` also silences the 8.4 deprecation of the
 * default.
 */
final class CsvReader {

	/**
	 * The header row, without opening the whole file.
	 *
	 * @param string $path      Absolute path.
	 * @param string $delimiter Field separator.
	 * @param string $enclosure Quote character.
	 *
	 * @return string[]
	 */
	public function header( string $path, string $delimiter = ',', string $enclosure = '"' ): array {
		$handle = $this->open( $path );

		if ( null === $handle ) {
			return array();
		}

		$row = fgetcsv( $handle, 0, $delimiter, $enclosure, '' );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return is_array( $row ) ? array_map( array( $this, 'clean' ), $row ) : array();
	}

	/**
	 * A window of rows.
	 *
	 * @param string $path       Absolute path.
	 * @param int    $offset     Data rows to skip (the header is never counted).
	 * @param int    $limit      How many to return.
	 * @param string $delimiter  Field separator.
	 * @param string $enclosure  Quote character.
	 * @param bool   $has_header Whether line one is names rather than data.
	 *
	 * @return array<int, string[]>
	 */
	public function rows( string $path, int $offset, int $limit, string $delimiter = ',', string $enclosure = '"', bool $has_header = true ): array {
		$handle = $this->open( $path );

		if ( null === $handle ) {
			return array();
		}

		if ( $has_header ) {
			fgetcsv( $handle, 0, $delimiter, $enclosure, '' );
		}

		$rows    = array();
		$skipped = 0;

		while ( count( $rows ) < $limit ) {
			$row = fgetcsv( $handle, 0, $delimiter, $enclosure, '' );

			if ( false === $row || null === $row ) {
				break;
			}

			// fgetcsv returns array( null ) for a blank line. A trailing newline is in every file
			// ever written, so counting those as rows would report an error on the last line of all
			// of them.
			if ( array( null ) === $row ) {
				continue;
			}

			if ( $skipped < $offset ) {
				++$skipped;

				continue;
			}

			$rows[] = array_map( array( $this, 'clean' ), $row );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $rows;
	}

	/**
	 * How many data rows the file holds.
	 *
	 * @param string $path       Absolute path.
	 * @param string $delimiter  Field separator.
	 * @param string $enclosure  Quote character.
	 * @param bool   $has_header Whether line one is names.
	 */
	public function count_rows( string $path, string $delimiter = ',', string $enclosure = '"', bool $has_header = true ): int {
		$handle = $this->open( $path );

		if ( null === $handle ) {
			return 0;
		}

		$count = 0;

		while ( true ) {
			$row = fgetcsv( $handle, 0, $delimiter, $enclosure, '' );

			if ( false === $row || null === $row ) {
				break;
			}

			if ( array( null ) === $row ) {
				continue;
			}

			++$count;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $has_header ? max( 0, $count - 1 ) : $count;
	}

	/**
	 * @param string $path Absolute path.
	 *
	 * @return resource|null
	 */
	private function open( string $path ) {
		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a file in the plugin's own private directory; WP_Filesystem has no streaming read.
		$handle = fopen( $path, 'r' );

		if ( false === $handle ) {
			return null;
		}

		$first = fgets( $handle, 4 );

		// Rewind unless those three bytes were the BOM, in which case leaving the pointer where it
		// is consumes it.
		if ( "\xEF\xBB\xBF" !== $first ) {
			rewind( $handle );
		}

		return $handle;
	}

	/**
	 * @param mixed $value One cell.
	 */
	private function clean( mixed $value ): string {
		return trim( (string) $value );
	}
}
