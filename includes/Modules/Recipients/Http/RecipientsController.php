<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Recipients\Http;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Core\Http\CrudController;
use PlugixaInventory\Modules\Recipients\Repositories\RecipientRepository;
use PlugixaInventory\Modules\Recipients\Services\RecipientService;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for recipients.
 */
final class RecipientsController extends CrudController {

	public function __construct(
		private readonly RecipientService $service,
		private readonly RecipientRepository $recipients
	) {}

	protected function entity_type(): string {
		return 'recipient';
	}

	protected function api_base(): string {
		return 'recipients';
	}

	protected function repository(): Repository {
		return $this->recipients;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.name', 't.email', 't.department', 't.phone' );
	}

	/**
	 * Through the service, which asks whether anything is still checked out to this person.
	 *
	 * @param int $id Recipient id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}
}
