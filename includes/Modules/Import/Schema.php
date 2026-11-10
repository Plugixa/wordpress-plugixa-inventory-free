<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import;

use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An import run, and what it did to every line of the file.
 *
 * THE ROW TABLE IS WHAT MAKES AN IMPORT REVERTIBLE, and revertible is the difference between a
 * feature people use and one they are frightened of. Importing four thousand items with the wrong
 * column mapping is not a rare accident; it is what happens the first time somebody tries. Without a
 * record of which ids this run created, undoing it means finding them by hand among records that
 * look exactly like the real ones.
 *
 * SO THE ROWS ARE KEPT EVEN FOR FAILURES. A row that errored carries its number and the reason,
 * which is the only way to answer "which line of my file was wrong" — and a spreadsheet of four
 * thousand lines is not something anyone will diff by eye.
 *
 * IT IS ALSO WHERE THE PENDING OPENING STOCK LIVES, which is this plugin's addition to the shape.
 * A quantity column does not fill a field; it has to become a finalized stock movement, and those are
 * posted per warehouse rather than per row (see {@see Services\OpeningStock}). The three `stock_*`
 * columns hold each row's contribution until its document is written, so the pending set survives
 * between batches — and between a closed browser and a resumed run — without a staging table of its
 * own. Once posted, `stock_document_id` is what ties the line to the document, which is what makes
 * the revert able to reconstruct exactly what it must take back out.
 *
 * WHAT IS DELIBERATELY NOT STORED: the file's contents. It lives in the private directory under a
 * random name and this table points at it. Putting a CSV of somebody's inventory in a `longtext`
 * column would put it in every database backup twice.
 *
 * dbDelta rules apply: lowercase types, TWO spaces after "PRIMARY KEY", every KEY named.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'imports', 'import_rows' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$charset = Db::charset();

		$changes = Db::delta( self::imports( $charset ), self::rows( $charset ) );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * One run.
	 *
	 * `offset_rows` IS THE RESUMPTION POINT, and it is the column the whole batching design rests
	 * on. A CSV of fifty thousand items cannot be imported inside one PHP request on any host worth
	 * supporting, so the client asks for a bounded batch at a time and this is where the run
	 * remembers how far it got. A browser closed halfway leaves a resumable import rather than a
	 * half-finished mess with no handle on it.
	 *
	 * `enclosure_char` DEFAULTS TO A DOUBLE QUOTE, written here as `\"` because this is a
	 * double-quoted PHP string. If dbDelta ever reports this table as changed on a settled schema,
	 * this column is the first place to look — a default it cannot compare is a table it re-ALTERs
	 * on every page load, forever, while reporting nothing. `ImportSchemaTest` asserts the second
	 * run is empty precisely so that cannot go unnoticed.
	 *
	 * `stock_lines` and `stock_error_count` have no counterpart in the CRM this ports: they exist
	 * because opening stock can fail independently of the record that carries it, and a summary
	 * saying "412 items imported" while three of them silently have no stock is the kind of quiet
	 * wrongness this module is supposed to prevent.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function imports( string $charset ): string {
		$table = Db::table( 'imports' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			entity_type varchar(50) NOT NULL default '',
			mode varchar(20) NOT NULL default 'create',
			status varchar(20) NOT NULL default 'pending',
			file_name varchar(255) NOT NULL default '',
			disk_name varchar(64) NOT NULL default '',
			delimiter_char varchar(4) NOT NULL default ',',
			enclosure_char varchar(4) NOT NULL default '\"',
			has_header tinyint(1) NOT NULL default 1,
			mapping longtext,
			options longtext,
			total_rows int(11) NOT NULL default 0,
			offset_rows int(11) NOT NULL default 0,
			created_count int(11) NOT NULL default 0,
			updated_count int(11) NOT NULL default 0,
			skipped_count int(11) NOT NULL default 0,
			error_count int(11) NOT NULL default 0,
			stock_lines int(11) NOT NULL default 0,
			stock_error_count int(11) NOT NULL default 0,
			revert_offset int(11) NOT NULL default 0,
			reverted_at datetime default NULL,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY entity_type (entity_type),
			KEY status (status),
			KEY created_by (created_by),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * What happened to one line of the file.
	 *
	 * `line_number` RATHER THAN THE OBVIOUS `row_number`, WHICH IS A RESERVED WORD IN MySQL 8. The
	 * CREATE TABLE is a syntax error with it, and the way that fails is worth knowing: dbDelta does
	 * not report a failed statement, so the table simply never appears and every insert into it
	 * fails silently afterwards. The sibling plugin this ports hit exactly that, and it was the
	 * run-dbDelta-twice assertion that caught it — a table that could not be created is reported as
	 * "created" on every single run.
	 *
	 * THIS IS A LOG, NOT A SET OF RECORDS, so it has no audit columns and no soft delete and its
	 * repository does not extend `Repository`. That is a deliberate asymmetry rather than an
	 * oversight: extending the base would fire `plugixa_inventory_entity_created` for every line of
	 * a fifty-thousand-row import, which the notification bell would then have to learn to ignore.
	 *
	 * `stock_quantity` and `stock_cost` are the row's contribution to its warehouse's opening
	 * stock movement, held here until the document is flushed. `stock_warehouse_id` non-null with
	 * `stock_document_id` still null IS the pending set — that pair is the query
	 * {@see Services\OpeningStock::pending()} runs, and the reason for the composite key below.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function rows( string $charset ): string {
		$table = Db::table( 'import_rows' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			import_id bigint(20) unsigned NOT NULL default 0,
			line_number int(11) NOT NULL default 0,
			entity_id bigint(20) unsigned default NULL,
			action varchar(20) NOT NULL default 'created',
			message varchar(255) NOT NULL default '',
			stock_warehouse_id bigint(20) unsigned default NULL,
			stock_quantity decimal(18,4) NOT NULL default 0.0000,
			stock_cost decimal(18,4) NOT NULL default 0.0000,
			stock_document_id bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY import_id (import_id),
			KEY import_action (import_id,action),
			KEY stock_pending (import_id,stock_warehouse_id,stock_document_id),
			KEY stock_document (stock_document_id)
		) {$charset};";
	}
}
