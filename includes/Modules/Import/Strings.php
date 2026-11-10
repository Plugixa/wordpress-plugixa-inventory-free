<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the import wizard says.
 *
 * SEVERAL OF THESE ARE NOT LABELS BUT PROMISES, and they are the reason this catalogue is longer
 * than a three-step screen suggests. An import writes thousands of records and can post real stock;
 * the difference between a feature people trust and one they are frightened of is whether the screen
 * said what it was going to do BEFORE it did it. So `import_stock_body`, `import_rule_lookup` and
 * the three `import_revert_*` entries are each a specific commitment the code keeps, and changing
 * one of them without changing the behaviour it describes is a bug rather than a copy edit.
 *
 * THE NATURAL-KEY SENTENCES ARE FIVE SEPARATE ENTRIES, not one with a placeholder, because they
 * differ by more than a noun: an item falls back to its barcode and the others do not. The client
 * picks between them with a literal `switch` for the same reason every other computed label in this
 * app does — `bin/generate-strings.php` only sees `t( 'literal', 'literal' )`, so a key assembled
 * from a variable is invisible to it and untranslatable for ever.
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			// The app card and the nav entry.
			'app_import'                   => __( 'Import', 'plugixa-inventory' ),
			'app_import_desc'              => __( 'Bring items and reference data in from a CSV file, with a mapping step before anything is written.', 'plugixa-inventory' ),
			'import'                       => __( 'Import', 'plugixa-inventory' ),
			'import_intro'                 => __( 'Bring records in from a spreadsheet. Nothing is written until you have checked how the columns line up.', 'plugixa-inventory' ),

			// The three steps.
			'import_step_file'             => __( 'Choose a file', 'plugixa-inventory' ),
			'import_step_map'              => __( 'Match columns', 'plugixa-inventory' ),
			'import_step_run'              => __( 'Import', 'plugixa-inventory' ),

			// Step one.
			'import_what'                  => __( 'What are you importing?', 'plugixa-inventory' ),
			'import_mode'                  => __( 'When a record already exists', 'plugixa-inventory' ),
			'import_mode_create'           => __( 'Skip it — only add new records', 'plugixa-inventory' ),
			'import_mode_create_update'    => __( 'Update it, and add the ones that are new', 'plugixa-inventory' ),
			'import_mode_update'           => __( 'Update it — add nothing', 'plugixa-inventory' ),
			'import_mode_needs_edit'       => __( 'You do not have permission to change records that already exist.', 'plugixa-inventory' ),
			'import_choose_csv'            => __( 'Choose a CSV file', 'plugixa-inventory' ),
			'import_uploading'             => __( 'Reading the file…', 'plugixa-inventory' ),
			'import_download_template'     => __( 'Download a template', 'plugixa-inventory' ),
			'import_template_hint'         => __( 'A CSV with the right headings and one example row. Fill it in and bring it back.', 'plugixa-inventory' ),

			// How an existing record is recognised, per entity.
			'import_key_item'              => __( 'An item already here is recognised by its SKU, or by its barcode when it has no SKU.', 'plugixa-inventory' ),
			'import_key_category'          => __( 'A category already here is recognised by its code.', 'plugixa-inventory' ),
			'import_key_unit'              => __( 'A unit already here is recognised by its code.', 'plugixa-inventory' ),
			'import_key_warehouse'         => __( 'A warehouse already here is recognised by its code.', 'plugixa-inventory' ),
			'import_key_recipient'         => __( 'A person already here is recognised by their email address.', 'plugixa-inventory' ),

			// What the file should look like.
			'import_rules_title'           => __( 'What your file should look like', 'plugixa-inventory' ),
			'import_rule_headings'         => __( 'The first row is the column headings. Everything after it is one record per line.', 'plugixa-inventory' ),
			'import_rule_lookup'           => __( 'A related record, such as a category or a warehouse, is found by its name or its code. Nothing new is created for you.', 'plugixa-inventory' ),
			'import_rule_miss'             => __( 'A line naming something that does not exist is not imported, and is listed by line number when the run finishes.', 'plugixa-inventory' ),

			// Past runs.
			'import_recent'                => __( 'Recent imports', 'plugixa-inventory' ),
			'import_recent_empty'          => __( 'Nothing imported yet.', 'plugixa-inventory' ),
			'import_file'                  => __( 'File', 'plugixa-inventory' ),
			'import_when'                  => __( 'When', 'plugixa-inventory' ),
			'import_rows'                  => __( 'Rows', 'plugixa-inventory' ),
			'import_resume'                => __( 'Resume', 'plugixa-inventory' ),
			'import_continue_mapping'      => __( 'Continue', 'plugixa-inventory' ),
			'import_view_result'           => __( 'View the result', 'plugixa-inventory' ),
			'import_status_pending'        => __( 'Not started', 'plugixa-inventory' ),
			'import_status_mapped'         => __( 'Ready to import', 'plugixa-inventory' ),
			'import_status_running'        => __( 'Unfinished', 'plugixa-inventory' ),
			'import_status_done'           => __( 'Finished', 'plugixa-inventory' ),
			'import_status_failed'         => __( 'Stopped', 'plugixa-inventory' ),
			'import_status_reverted'       => __( 'Reverted', 'plugixa-inventory' ),
			'import_file_gone'             => __( 'The uploaded file has been cleaned up, so this run can no longer be resumed.', 'plugixa-inventory' ),

			// Step two, the mapping table.
			'import_column_in_file'        => __( 'Column in your file', 'plugixa-inventory' ),
			'import_example'               => __( 'Example', 'plugixa-inventory' ),
			'import_import_as'             => __( 'Import as', 'plugixa-inventory' ),
			'import_do_not_import'         => __( 'Do not import', 'plugixa-inventory' ),
			'import_guessed'               => __( 'Guessed', 'plugixa-inventory' ),
			'import_guess_again'           => __( 'Match again', 'plugixa-inventory' ),
			'import_clear_mapping'         => __( 'Clear all', 'plugixa-inventory' ),
			'import_group_fields'          => __( 'Fields', 'plugixa-inventory' ),
			/*
			 * `import_group_custom` USED TO BE HERE. It named the heading for mapping targets this
			 * module does not own — contributed by whichever module owns that kind of value — and a
			 * build without that module carried the word anyway, into the bundle and into the .pot.
			 * The heading is sent with the targets now; see `ImportTargets`'s `custom_label`.
			 */
			'import_group_stock'           => __( 'Opening stock — this posts a stock document', 'plugixa-inventory' ),
			'import_used_by'               => __( 'Already used by', 'plugixa-inventory' ),
			/* translators: {mapped} and {total} are numbers of columns, substituted by the admin app. */
			'import_n_mapped'              => __( '{mapped} of {total} columns mapped', 'plugixa-inventory' ),
			/* translators: {count} is a number of columns, substituted by the admin app. */
			'import_n_ignored'             => __( '{count} ignored', 'plugixa-inventory' ),
			/* translators: {fields} is a comma-separated list of field names, substituted by the admin app. */
			'import_missing_required'      => __( 'These have to be mapped before this can run: {fields}', 'plugixa-inventory' ),
			/* translators: {field} is a field name such as SKU, substituted by the admin app. */
			'import_missing_key'           => __( 'Nothing can be matched without {field}. Map it, or switch to only adding new records.', 'plugixa-inventory' ),
			/* translators: {field} is a field name; {a} and {b} are two column headings from the file. Substituted by the admin app. */
			'import_duplicate_target'      => __( '{field} is mapped twice — by “{a}” and by “{b}”. Pick one.', 'plugixa-inventory' ),
			'import_not_importable'        => __( 'Not importable', 'plugixa-inventory' ),
			'import_readonly_hint'         => __( 'On hand is set by stock movements, so it cannot be written directly. To bring opening stock in, map this column to Opening quantity instead.', 'plugixa-inventory' ),
			'import_run'                   => __( 'Import', 'plugixa-inventory' ),
			'import_back'                  => __( 'Back', 'plugixa-inventory' ),
			/* translators: {file} is a file name and {rows} a number of lines, substituted by the admin app. */
			'import_file_rows'             => __( '{file} · {rows} rows', 'plugixa-inventory' ),
			'import_number_format_help'    => __( 'Numbers are read using this site’s own thousands and decimal separators.', 'plugixa-inventory' ),

			// Opening stock.
			'import_opening_quantity'      => __( 'Opening quantity', 'plugixa-inventory' ),
			'import_opening_cost'          => __( 'Cost per unit', 'plugixa-inventory' ),
			'import_opening_warehouse'     => __( 'Warehouse the stock lands in', 'plugixa-inventory' ),
			'import_stock_title'           => __( 'This will post stock, not just fill a column', 'plugixa-inventory' ),
			'import_stock_body'            => __( 'A finalized stock movement is created for this import, in the warehouse you choose. It appears in the ledger, it moves On hand, and correcting it later means a second document.', 'plugixa-inventory' ),
			'import_stock_only_new'        => __( 'Opening stock is posted only for rows that create a new item, so running this file again will not double your stock.', 'plugixa-inventory' ),
			'import_stock_needs_warehouse' => __( 'Choose the warehouse the opening stock lands in.', 'plugixa-inventory' ),
			'import_stock_cost_hint'       => __( 'Left unmapped, each line is valued at the item’s own cost price.', 'plugixa-inventory' ),

			// Step three, running.
			'import_running'               => __( 'Importing…', 'plugixa-inventory' ),
			/* translators: {done} and {total} are numbers of lines, substituted by the admin app. */
			'import_progress'              => __( '{done} of {total} lines', 'plugixa-inventory' ),
			/* translators: {count} is a number of opening-stock lines, substituted by the admin app. */
			'import_posting_stock'         => __( 'Posting the opening stock — {count} lines to go', 'plugixa-inventory' ),
			'import_stop'                  => __( 'Stop', 'plugixa-inventory' ),
			/* translators: {done} and {total} are numbers of lines, substituted by the admin app. */
			'import_stopped'               => __( 'Stopped. {done} of {total} lines were imported; the rest are untouched.', 'plugixa-inventory' ),
			'import_stop_note'             => __( 'What has already been imported stays. Resume picks up from the next line.', 'plugixa-inventory' ),
			/* translators: {message} is the server’s own explanation, substituted by the admin app. */
			'import_failed'                => __( 'The import stopped: {message}', 'plugixa-inventory' ),
			'import_no_progress'           => __( 'The server stopped making progress, so this stopped rather than asking again forever.', 'plugixa-inventory' ),
			'import_leave_warning'         => __( 'An import is running. Leaving this page stops it — you can resume it from the list.', 'plugixa-inventory' ),

			// Step three, the result.
			'import_created'               => __( 'Added', 'plugixa-inventory' ),
			'import_updated'               => __( 'Updated', 'plugixa-inventory' ),
			'import_skipped'               => __( 'Skipped', 'plugixa-inventory' ),
			'import_errors'                => __( 'Not imported', 'plugixa-inventory' ),
			'import_line'                  => __( 'Line', 'plugixa-inventory' ),
			'import_what_happened'         => __( 'What happened', 'plugixa-inventory' ),
			/* translators: {shown} and {total} are numbers of problems, substituted by the admin app. */
			'import_problems_shown'        => __( 'Showing the first {shown} of {total} problems.', 'plugixa-inventory' ),
			'import_download_errors'       => __( 'Download the problems as CSV', 'plugixa-inventory' ),
			'import_another'               => __( 'Import another file', 'plugixa-inventory' ),
			/* translators: {count} is a number of items whose opening stock failed, substituted by the admin app. */
			'import_stock_errors'          => __( 'Opening stock could not be posted for {count} of them.', 'plugixa-inventory' ),
			/* translators: {reference} is a document reference such as ADJ-2026-0043, substituted by the admin app. */
			'import_unfinalized_document'  => __( 'An opening-stock document was left unfinalized ({reference}). Open it and finalize it, or delete it.', 'plugixa-inventory' ),

			// Revert.
			'import_revert'                => __( 'Revert this import', 'plugixa-inventory' ),
			'import_revert_title'          => __( 'Revert this import?', 'plugixa-inventory' ),
			'import_revert_body'           => __( 'The records this import added are moved to the trash. Records it updated are not changed back.', 'plugixa-inventory' ),
			'import_revert_stock_body'     => __( 'The records this import added are moved to the trash, and the opening stock it posted is taken back out with a reversing stock movement. An item that now has stock history is unpublished instead of being removed, because its history is part of the audit trail. Records it updated are not changed back.', 'plugixa-inventory' ),
			'import_revert_confirm'        => __( 'Revert', 'plugixa-inventory' ),
			/* translators: {removed} and {unpublished} are numbers of records, substituted by the admin app. */
			'import_reverted'              => __( 'Reverted. {removed} records were moved to the trash and {unpublished} were unpublished.', 'plugixa-inventory' ),
		);
	}
}
