<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\Import\Http\ImportController;
use PlugixaInventory\Modules\Import\Repositories\ImportRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Getting data in: a CSV, a column mapping, and a batched run that can be undone.
 *
 * IMPORT ONLY — EXPORT IS SOMEBODY ELSE'S. The sibling plugin this ports keeps both halves in one
 * module because "they are one job to the person doing it and they share the CSV mechanics". That
 * argument does not hold here: `Modules\Export` ships the other direction, and two modules writing
 * the same file would be two things to keep in step. So this one reads and never writes.
 *
 * The two are deliberately separate CARDS in the App Manager, sitting next to each other, and both
 * are free. Export used to live inside Reports for no better reason than that the CSV writer was
 * written there first; when Reports became premium that stopped being harmless, because a plugin
 * that can take data in and not out is worse than one that does neither.
 *
 * IT REGISTERS NO ENTITY. An import is a record of something that happened, not a thing to be
 * listed, filtered or assigned — and registering it in `plugixa_inventory_entity_types` would put it
 * in global search and offer it in its own import-target picker, which is a loop nobody asked for.
 *
 * WRITES GO THROUGH `Core\Entities\RecordWriter`, WHICH WAS BUILT FOR THIS. Its docblock calls
 * itself "the seam CSV import needs" and its refusal message says "Records of type %s cannot be
 * imported"; five modules already register their services on `plugixa_inventory_record_writers`.
 * This module is the consumer that seam was waiting for, so importing an item runs exactly the
 * validation, the derived columns and the lifecycle events that creating one through the REST API
 * does. Documents and the ledger register no writer, deliberately, and this module must never
 * reach around that: opening stock is posted by asking `AdjustmentService` for a real document.
 */
final class Module implements ModuleInterface, AppInterface {

	/** Removes stored CSVs long after the run that needed them. */
	public const CLEANUP_HOOK = 'plugixa_inventory_import_cleanup';

	/** How long an uploaded file is kept, in days, once its run is over. */
	public const KEEP_FILES_DAYS = 30;

	public function id(): string {
		return 'import';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 10,
			'category'  => 'tools',
			'core'      => false,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_import',
			'desc_key'  => 'app_import_desc',
			'icon'      => 'upload',
			'cron'      => array(
				self::CLEANUP_HOOK,
			),
		);
	}

	/**
	 * @return string[]
	 */
	public function usage( Container $container ): array {
		$count = $container->get( ImportRepository::class )->finished_count();

		if ( 0 === $count ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: %s: a number of completed import runs. */
				_n( '%s import', '%s imports', $count, 'plugixa-inventory' ),
				number_format_i18n( $count )
			),
		);
	}

	/**
	 * The card's name and description, from this module's OWN catalogue.
	 *
	 * Read back out of `Strings::all()` rather than written again here: the pair is registered
	 * there so `bin/generate-strings.php` and the .pot both see it, and a second copy would be a
	 * second thing to keep in step.
	 *
	 * @return array{label: string, desc: string}
	 */
	public function labels(): array {
		$strings = Strings::all();

		return array(
			'label' => (string) ( $strings['app_import'] ?? 'import' ),
			'desc'  => (string) ( $strings['app_import_desc'] ?? '' ),
		);
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function blocker( Container $container ): ?string {
		/*
		 * AN UNFINISHED RUN BECOMES UNRESUMABLE THE MOMENT THIS MODULE IS OFF, because the route
		 * that continues it is registered here. Its records are already created and its opening
		 * stock may be half posted, so leaving it stranded is not a tidy state to abandon somebody
		 * in — the honest move is to refuse the toggle and say which action clears it.
		 */
		return $container->get( ImportRepository::class )->has_unfinished()
			? __( 'An import has not finished. Complete or revert it first.', 'plugixa-inventory' )
			: null;
	}

	public function migrate(): void {
		Schema::install();

		/*
		 * NOT IN THE ACTIVATION HOOK. `Activator::migrate()` re-runs on every version drift, so a
		 * plugin updated by file copy — which never fires an activation hook — still gets its
		 * directory. And a user who deletes the folder gets it back, instead of silently losing
		 * every upload from then on.
		 */
		Storage::ensure();
	}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		return array();
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function registerServices( Container $container ): void {
		unset( $container );

		/*
		 * THE UNINSTALL LISTENER LIVES HERE AND NOT IN `registerHooks()`, WHICH IS THE ONE PLACE
		 * THIS MODULE DEPARTS FROM THE HOUSE PATTERN.
		 *
		 * `ModuleRegistry` skips `registerHooks()` for a DISABLED module — that is how it stops a
		 * switched-off module's cron and routes — but it runs `registerServices()` for every module
		 * on disk regardless of the flag. Registering the teardown in `registerHooks()` would
		 * therefore mean that a site which had switched Import off kept a directory full of the
		 * customer's spreadsheets on disk forever after the plugin was uninstalled, with nothing
		 * left that knew the folder existed.
		 *
		 * The tables need no listener: `Core\Uninstaller` drops everything matching the plugin's
		 * table prefix, so a module's schema is cleaned up whether or not the module still exists.
		 * Only the filesystem is outside that sweep.
		 */
		add_action( 'plugixa_inventory_uninstall', array( Storage::class, 'destroy' ) );
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function registerRoutes( Container $container ): void {
		$container->get( ImportController::class )->register_routes();
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);

		$this->register_settings();
		$this->register_cleanup( $container );
	}

	/**
	 * The upload ceiling, which belongs to Import because Import is what receives a file.
	 *
	 * It is a SETTING rather than a constant because the useful value differs per site and the
	 * failure it prevents is silent: a file larger than PHP will accept arrives as an empty upload
	 * with an error code, and "nothing happened" is the least helpful thing an import screen can
	 * say. Bounding it here lets the refusal name a number the user can act on.
	 *
	 * It is bounded BY `wp_max_upload_size()` at the point of use rather than clamped here,
	 * because the host's limit can change under a stored value and the smaller of the two is
	 * always the real one.
	 */
	private function register_settings(): void {
		add_filter(
			'plugixa_inventory_settings_defaults',
			static function ( array $defaults ): array {
				$defaults['import_max_upload_mb'] = FileIntake::DEFAULT_MAX_MB;

				return $defaults;
			}
		);

		add_filter(
			'plugixa_inventory_sanitize_settings',
			static function ( array $clean, array $input ): array {
				$megabytes = (int) ( $input['import_max_upload_mb'] ?? FileIntake::DEFAULT_MAX_MB );

				$clean['import_max_upload_mb'] = max( 1, min( 512, $megabytes ) );

				return $clean;
			},
			10,
			2
		);
	}

	/**
	 * Forget the file, keep the receipt.
	 *
	 * A STORED CSV IS SOMEBODY'S SUPPLIER LIST, AND KEEPING IT FOREVER IS NOT FREE. Once a run has
	 * finished there is nothing left that needs the bytes: the counters are columns, the per-line
	 * problems are rows, and the revert works from the row log rather than from the file. So the
	 * file goes and the run stays — a history that remembers what happened without holding the
	 * customer's data open indefinitely.
	 *
	 * A run that is still `pending` or unfinished is never touched, however old, because that file
	 * is the only thing a resume could read.
	 *
	 * @param Container $container The plugin container.
	 */
	private function register_cleanup( Container $container ): void {
		add_action(
			self::CLEANUP_HOOK,
			static function () use ( $container ): void {
				$repository = $container->get( ImportRepository::class );

				foreach ( $repository->stale_files( self::KEEP_FILES_DAYS ) as $run ) {
					Storage::delete( (string) $run['disk_name'] );

					$repository->update( (int) $run['id'], array( 'disk_name' => '' ) );
				}
			}
		);

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}
}
