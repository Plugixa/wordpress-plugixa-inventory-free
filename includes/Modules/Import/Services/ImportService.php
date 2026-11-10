<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Services;

use PlugixaInventory\Core\Entities\RecordWriter;
use PlugixaInventory\Core\Security\Capabilities;
use PlugixaInventory\Modules\Import\FileIntake;
use PlugixaInventory\Modules\Import\Repositories\ImportRepository;
use PlugixaInventory\Modules\Import\Repositories\ImportRowRepository;
use PlugixaInventory\Modules\Import\Storage;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setting an import up, and describing it.
 *
 * The two halves of the wizard that are not the run itself: taking the file and saying what is in
 * it, then remembering the mapping somebody chose.
 */
final class ImportService {

	/** Sample rows shown on the mapping screen. */
	private const SAMPLE_ROWS = 5;

	/** Per-line problems returned with a description. The rest are in the download. */
	private const PROBLEM_LIMIT = 50;

	public function __construct(
		private readonly ImportRepository $imports,
		private readonly ImportRowRepository $rows,
		private readonly RecordWriter $writer,
		private readonly FileIntake $files,
		private readonly CsvReader $reader,
		private readonly OpeningStock $stock
	) {}

	/**
	 * Take the uploaded file and describe what is in it.
	 *
	 * NOTHING IS IMPORTED HERE. This stores the file, reads its header and counts its rows so the
	 * user can map columns before anything is written. An import that started on upload would be an
	 * import nobody got to check first, and the mapping is the step people get wrong.
	 *
	 * @param array<string, mixed> $file  One entry from $_FILES.
	 * @param array<string, mixed> $input entity_type, mode, delimiter, enclosure, has_header.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( array $file, array $input ): array|WP_Error {
		$entity_type = sanitize_key( (string) ( $input['entity_type'] ?? '' ) );

		if ( ! $this->writer->knows( $entity_type ) ) {
			return new WP_Error(
				'plugixa_inventory_not_writable',
				__( 'That kind of record cannot be imported.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		/*
		 * CHECKED BEFORE THE FILE IS STORED, and that ordering is the point rather than an
		 * optimisation. The route's own callback asks whether this user may create anything at all;
		 * this asks whether they may create THIS. Without it, somebody with no permission to add
		 * items could still upload a spreadsheet of a competitor's price list and leave it sitting
		 * in the site's private storage, having been told only afterwards that the import would not
		 * run.
		 */
		if ( ! Capabilities::user_can( 'create', $entity_type ) ) {
			return new WP_Error(
				'plugixa_inventory_forbidden',
				__( 'You do not have permission to create those.', 'plugixa-inventory' ),
				array( 'status' => 403 )
			);
		}

		$mode = sanitize_key( (string) ( $input['mode'] ?? 'create' ) );

		if ( ! in_array( $mode, Importer::MODES, true ) ) {
			return new WP_Error(
				'plugixa_inventory_invalid_mode',
				__( 'That is not an import mode.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		// Changing existing records is a different permission from adding new ones, and the two
		// update modes do it. A user with create but not edit reaches this legitimately.
		if ( 'create' !== $mode && ! Capabilities::user_can( 'edit', $entity_type ) ) {
			return new WP_Error(
				'plugixa_inventory_forbidden',
				__( 'You do not have permission to change records that already exist.', 'plugixa-inventory' ),
				array( 'status' => 403 )
			);
		}

		$stored = $this->files->store( $file );

		if ( $stored instanceof WP_Error ) {
			return $stored;
		}

		$path      = (string) Storage::path( $stored['disk_name'] );
		$delimiter = $this->one_char( (string) ( $input['delimiter'] ?? ',' ), ',' );
		$enclosure = $this->one_char( (string) ( $input['enclosure'] ?? '"' ), '"' );
		$header    = ! isset( $input['has_header'] ) || (bool) $input['has_header'];

		$id = $this->imports->insert(
			array(
				'entity_type'    => $entity_type,
				'mode'           => $mode,
				'status'         => 'pending',
				'file_name'      => $stored['original_name'],
				'disk_name'      => $stored['disk_name'],
				'delimiter_char' => $delimiter,
				'enclosure_char' => $enclosure,
				'has_header'     => $header ? 1 : 0,
				'mapping'        => (string) wp_json_encode( array() ),
				'options'        => (string) wp_json_encode( array() ),
				'total_rows'     => $this->reader->count_rows( $path, $delimiter, $enclosure, $header ),
			)
		);

		if ( 0 === $id ) {
			// The bytes are unreachable garbage without a row pointing at them.
			Storage::delete( $stored['disk_name'] );

			return new WP_Error(
				'plugixa_inventory_insert_failed',
				__( 'The import could not be started.', 'plugixa-inventory' ),
				array( 'status' => 500 )
			);
		}

		return $this->describe( $id );
	}

	/**
	 * The header, a few sample rows, and what happened — the wizard's whole input.
	 *
	 * @param int $id The run.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function describe( int $id ): array|WP_Error {
		$import = $this->imports->find( $id );

		if ( null === $import ) {
			return $this->not_found();
		}

		$path = Storage::path( $this->imports->disk_name( $id ) );

		$delimiter = (string) $import['delimiter_char'];
		$enclosure = (string) $import['enclosure_char'];
		$header    = (bool) $import['has_header'];

		return array(
			'import'        => $this->imports->cast( $import ),
			'columns'       => null === $path ? array() : $this->reader->header( $path, $delimiter, $enclosure ),
			'sample'        => null === $path ? array() : $this->reader->rows( $path, 0, self::SAMPLE_ROWS, $delimiter, $enclosure, $header ),
			'errors'        => $this->rows->problems( $id, self::PROBLEM_LIMIT ),
			'error_summary' => $this->rows->problem_summary( $id ),
			'unfinalized'   => $this->stock->unfinalized( $id ),
		);
	}

	/**
	 * Save the column mapping and the synthetic choices.
	 *
	 * @param int                  $id      The run.
	 * @param array<int, string>   $mapping Column index => target name.
	 * @param array<string, mixed> $options The synthetic choices, e.g. the opening warehouse.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function map( int $id, array $mapping, array $options ): array|WP_Error {
		$import = $this->imports->find( $id );

		if ( null === $import ) {
			return $this->not_found();
		}

		if ( (int) $import['offset_rows'] > 0 ) {
			// Changing the mapping halfway would mean the first half of the file was read one way
			// and the second another, in one run, with nothing on screen saying so.
			return new WP_Error(
				'plugixa_inventory_import_started',
				__( 'This import has already started, so its columns can no longer be changed.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		if ( null !== $import['reverted_at'] ) {
			return new WP_Error(
				'plugixa_inventory_import_reverted',
				__( 'That import has been reverted.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$clean = array();

		foreach ( $mapping as $column => $field ) {
			$field = sanitize_key( (string) $field );

			if ( '' === $field ) {
				continue;
			}

			$clean[ (string) (int) $column ] = $field;
		}

		$this->imports->update(
			$id,
			array(
				'mapping' => (string) wp_json_encode( $clean ),
				'options' => (string) wp_json_encode(
					array(
						'warehouse_id' => max( 0, (int) ( $options['warehouse_id'] ?? 0 ) ),
					)
				),
				'status'  => 'mapped',
			)
		);

		return $this->describe( $id );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_not_found',
			__( 'That import does not exist.', 'plugixa-inventory' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * A delimiter is one character. Anything else is somebody pasting a word into a text box.
	 *
	 * @param string $value    What arrived.
	 * @param string $fallback What to use instead.
	 */
	private function one_char( string $value, string $fallback ): string {
		if ( '\t' === $value || 'tab' === strtolower( $value ) ) {
			return "\t";
		}

		return 1 === strlen( $value ) ? $value : $fallback;
	}
}
