<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Http;

use PlugixaInventory\Core\Security\Capabilities;
use PlugixaInventory\Core\Settings;
use PlugixaInventory\Core\Uninstaller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST access to the inventory's settings.
 *
 * Thin on purpose: what a setting is, what it defaults to and what counts as a valid value
 * are all questions for Core\Settings. This class only answers who may ask.
 */
final class SettingsController extends RestController {

	protected function entity_type(): string {
		return 'settings';
	}

	/**
	 * Settings are administration, not data. Reading them is fine for anyone who can use the
	 * inventory — the app needs the currency to render an amount. Changing them is not: these are
	 * site-wide and affect every user.
	 *
	 * IT TAKES THE INVENTORY'S OWN CAPABILITY RATHER THAN `manage_options`, and that is the whole
	 * point of the role builder: "runs the warehouse" and "administers the WordPress site" are
	 * different jobs, and before the split there was no way to say the first without granting the
	 * second. Administrators are unaffected — activation grants them every inventory capability.
	 *
	 * THE THINGS THAT KEPT `manage_options` DID SO DELIBERATELY: the App Manager (which decides
	 * what the plugin even is), the sample-content installer (which wipes and seeds), the stock reconcile writes
	 * (which repair the ledger) and both role screens. A role builder gated on a capability it can
	 * itself grant is a lock whose key is inside it.
	 *
	 * ONE KEY ON THIS ROUTE IS `manage_options` TOO: whether deleting the plugin erases the inventory.
	 * "May change the date format" is not "may arrange for the whole ledger to disappear". It is
	 * checked as a CHANGE, not as a presence — the settings form re-sends every value it loaded, so
	 * refusing any payload that merely carries the key would lock a settings manager out of the date
	 * format too. A no-op is not an attempt.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_manage_settings( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return $this->forbidden();
		}

		$payload = (array) $request->get_json_params();

		if (
			array_key_exists( Uninstaller::SETTING, $payload )
			&& rest_sanitize_boolean( $payload[ Uninstaller::SETTING ] ) !== (bool) Settings::get( Uninstaller::SETTING, false )
			&& ! current_user_can( 'manage_options' )
		) {
			return $this->fail(
				'plugixa_inventory_forbidden',
				__( 'Only a site administrator can change what deleting the plugin does to the inventory.', 'plugixa-inventory' ),
				rest_authorization_required_code()
			);
		}

		return true;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'POST, PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok(
			array(
				'settings' => Settings::all(),
				/**
				 * The lists a settings control needs to offer a choice.
				 *
				 * Filtered for the same reason the defaults and the sanitizer are: a module owns its
				 * own settings, so the Stock module puts its costing methods here and the Units
				 * module its weight units, without Core learning that either exists. Values are read
				 * by the admin app only.
				 *
				 * @param array<string, mixed> $choices Keyed by control.
				 */
				'choices'  => apply_filters(
					'plugixa_inventory_settings_choices',
					array(
						'currencies'          => Settings::CURRENCIES,
						'currency_positions'  => Settings::CURRENCY_POSITIONS,
						'date_formats'        => Settings::DATE_FORMATS,
						'separators'          => Settings::SEPARATORS,
						/*
						 * The site's timezone, sent as a value rather than a choice, because it is
						 * NOT this plugin's to set -- see the Settings class docblock. The admin app
						 * formats ledger timestamps against it and needs to be told what it is;
						 * offering it as a picker would imply a second copy exists to change.
						 */
						'site_timezone'       => wp_timezone_string(),
					)
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( array( 'settings' => Settings::save( (array) $request->get_json_params() ) ) );
	}
}
