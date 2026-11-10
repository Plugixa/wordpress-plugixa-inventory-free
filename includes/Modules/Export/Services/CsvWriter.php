<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Export\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows in, one CSV document out.
 *
 * WHY `php://temp` AND NOT STRING CONCATENATION. Quoting, embedded delimiters, embedded newlines
 * and doubled quotes are all `fputcsv`'s problem the moment there is a stream to write to, and all
 * of them are a hand-rolled joiner's problem otherwise. `php://temp` is that stream without a file:
 * it holds the document in memory and only spills to disk past a threshold, so a 40-row export
 * never touches the filesystem and a 40,000-row one does not exhaust memory.
 *
 * THE TWO THINGS THIS CLASS EXISTS FOR ARE BOTH IN write_row(), and neither is obvious from a
 * function called "write a CSV": the BOM, and the formula guard. Both are the kind of defect that
 * ships, because both produce a file that opens perfectly on the machine of the person who wrote
 * the exporter.
 */
final class CsvWriter {

	/**
	 * The characters Excel and Sheets read as "this cell is a formula".
	 *
	 * A LEADING `-` IS ON THE LIST AND IT IS NOT PARANOIA, though it is the one that looks like it:
	 * a negative quantity is a perfectly ordinary cell in an inventory export. It is quoted rather
	 * than dropped, so `-40` still reads as minus forty to a human and as text to the spreadsheet.
	 *
	 * @var string[]
	 */
	private const FORMULA_PREFIXES = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Build a CSV document.
	 *
	 * @param string[]                         $headings Column headings, already translated.
	 * @param array<int, array<int, mixed>>    $rows     Rows, each a list in heading order.
	 */
	public static function build( array $headings, array $rows ): string {
		/*
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 * `php://temp` IS A MEMORY STREAM, NOT THE FILESYSTEM, WHICH IS WHY WP_Filesystem IS NOT
		 * THE ANSWER HERE.
		 *
		 * WPCS asks for `WP_Filesystem` on `fopen`/`fwrite`/`fclose` because a plugin writing to
		 * disk must respect the FS_METHOD a host has configured — FTP, SSH, direct — and must not
		 * assume it can write anywhere. None of that applies to a stream that exists only in this
		 * process's memory and is discarded before the function returns. `WP_Filesystem` has no
		 * API for one, so honouring the sniff would mean writing a real temporary FILE, on disk,
		 * on every CSV export: slower, and it introduces the permissions problem the sniff exists
		 * to prevent.
		 *
		 * `php://temp` also spills to a temporary file only above 2 MB, which is the behaviour
		 * that makes a large export survivable rather than an out-of-memory error.
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a php://temp memory stream, not a file; WP_Filesystem cannot open one. See above.
		$stream = fopen( 'php://temp', 'r+' );

		if ( false === $stream ) {
			return '';
		}

		/*
		 * A UTF-8 BYTE-ORDER MARK, WHICH EXCEL ON WINDOWS REQUIRES AND NOTHING ELSE MINDS.
		 *
		 * Without it Excel reads the file in the machine's ANSI code page, so an item called
		 * "Café" opens as "CafÃ©" — on the reviewer's machine and not on the developer's, which is
		 * what makes it a bug report rather than a test failure. LibreOffice, Sheets, `pandas` and
		 * every text editor skip the BOM without being asked.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- memory stream; see build()'s note.
		fwrite( $stream, "\xEF\xBB\xBF" );

		self::write_row( $stream, $headings );

		foreach ( $rows as $row ) {
			self::write_row( $stream, $row );
		}

		rewind( $stream );

		$csv = (string) stream_get_contents( $stream );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- memory stream; see build()'s note.
		fclose( $stream );

		return $csv;
	}

	/**
	 * @param resource          $stream Open stream.
	 * @param array<int, mixed> $row    One row's cells.
	 */
	private static function write_row( $stream, array $row ): void {
		fputcsv(
			$stream,
			array_map( array( self::class, 'cell' ), $row ),
			',',
			'"',
			/*
			 * ESCAPE DISABLED, EXPLICITLY. PHP's default is a backslash escape that is not part of
			 * RFC 4180 and that no spreadsheet implements: a cell containing `C:\path\` round-trips
			 * through PHP intact and arrives in Excel with the backslashes eaten. Passing '' turns
			 * it off and leaves only the doubled-quote escaping the format actually specifies.
			 *
			 * It is also the parameter PHP is in the middle of deprecating the default of, so
			 * saying what we mean here is what keeps this quiet on 8.4 and working on 9.
			 */
			''
		);
	}

	/**
	 * One cell, rendered and made safe to open.
	 *
	 * CSV INJECTION, WHICH IS A REAL VULNERABILITY AND NOT A LINTER'S OPINION. A spreadsheet
	 * evaluates any cell beginning with `=`, `+`, `-` or `@`, and the formula language reaches
	 * outside the document — `=HYPERLINK(...)` and the DDE functions can exfiltrate the sheet's
	 * contents to a URL when the recipient clicks "enable". Every string in this plugin's exports
	 * is user-supplied: item names, SKUs, reasons typed on an adjustment. So an attacker who can
	 * create one item can put a formula in a file that a colleague later opens, and the plugin
	 * would have carried it there.
	 *
	 * The fix is a leading apostrophe, which every spreadsheet reads as "the rest of this is text"
	 * and does not display. It is applied on write rather than on save so that the database keeps
	 * exactly what was typed.
	 *
	 * @param mixed $value The raw value.
	 */
	private static function cell( mixed $value ): string {
		if ( null === $value || is_bool( $value ) ) {
			// A bare `true` renders as "1" and `false` as "", which reads as a missing cell rather
			// than as "no". Yes/No is the only rendering a spreadsheet column can be filtered on.
			return null === $value ? '' : ( $value ? 'Yes' : 'No' );
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$text = (string) $value;

		foreach ( self::FORMULA_PREFIXES as $prefix ) {
			if ( str_starts_with( $text, $prefix ) ) {
				return "'" . $text;
			}
		}

		return $text;
	}
}
