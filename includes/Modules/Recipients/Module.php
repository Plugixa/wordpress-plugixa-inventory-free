<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Recipients;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\Recipients\Http\RecipientsController;
use PlugixaInventory\Modules\Recipients\Repositories\RecipientRepository;
use PlugixaInventory\Modules\Recipients\Services\RecipientService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recipients: the people assets get checked out to.
 *
 * Owns the `recipients` table. The Stock module names a recipient on a check-out and answers this
 * module's `plugixa_inventory_recipient_delete_blockers` filter, so somebody still holding a
 * laptop cannot be removed from the only record of who has it.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'recipients';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 70,
			'category'  => 'core',
			'core'      => true,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_recipients',
			'desc_key'  => 'app_recipients_desc',
			'icon'      => 'people',
			'cron'      => array(),
		);
	}

	/**
	 * @return string[]
	 */
	public function usage( Container $container ): array {
		unset( $container );

		return array();
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
			'label' => (string) ( $strings['app_recipients'] ?? 'recipients' ),
			'desc'  => (string) ( $strings['app_recipients_desc'] ?? '' ),
		);
	}

	public function blocker( Container $container ): ?string {
		unset( $container );

		return null;
	}

	public function migrate(): void {
		Schema::install();
	}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		return array();
	}

	public function registerServices( Container $container ): void {
		unset( $container );
	}

	public function registerRoutes( Container $container ): void {
		$container->get( RecipientsController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);

		add_filter( 'plugixa_inventory_entity_types', array( $this, 'register_entity' ) );

		add_filter(
			'plugixa_inventory_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				return 'recipient' === $entity_type
					? array_merge( $fields, $container->get( RecipientRepository::class )->filterable() )
					: $fields;
			},
			10,
			2
		);

		add_filter(
			'plugixa_inventory_record_writers',
			static function ( array $writers ) use ( $container ): array {
				$writers['recipient'] = array(
					'create' => array( $container->get( RecipientService::class ), 'create' ),
					'update' => array( $container->get( RecipientService::class ), 'update' ),
					'delete' => array( $container->get( RecipientService::class ), 'delete' ),
				);

				return $writers;
			}
		);

		add_filter(
			'plugixa_inventory_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'recipient' === $entity_type
					? $names + $container->get( RecipientRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);
	}

	/**
	 * Describe a recipient to the generic UI.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['recipient'] = array(
			'label'    => __( 'Recipient', 'plugixa-inventory' ),
			'plural'   => __( 'Recipients', 'plugixa-inventory' ),
			'api_base' => 'recipients',
			'fields'   => array(
				'name'       => array(
					'label'    => __( 'Name', 'plugixa-inventory' ),
					'type'     => 'text',
					'required' => true,
				),
				'email'      => array(
					'label' => __( 'Email', 'plugixa-inventory' ),
					'type'  => 'email',
				),
				'phone'      => array(
					'label' => __( 'Phone', 'plugixa-inventory' ),
					'type'  => 'text',
				),
				'department' => array(
					'label' => __( 'Department', 'plugixa-inventory' ),
					'type'  => 'text',
				),
				'user_id'    => array(
					'label' => __( 'WordPress user', 'plugixa-inventory' ),
					'type'  => 'user',
				),
				'notes'      => array(
					'label' => __( 'Notes', 'plugixa-inventory' ),
					'type'  => 'richtext',
				),
				'status'     => array(
					'label'   => __( 'Status', 'plugixa-inventory' ),
					'type'    => 'select',
					'options' => RecipientService::STATUSES,
				),
			),
		);

		return $entities;
	}
}
