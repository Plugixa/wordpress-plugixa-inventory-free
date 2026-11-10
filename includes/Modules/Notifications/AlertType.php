<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The kinds of thing this plugin will tell somebody about, and what it does by default.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A MODULE REGISTERS THE EVENTS IT RAISES; THIS FILE OWNS ONLY THE ONES NOTIFICATIONS RAISES
 * ITSELF.
 *
 * `all()` used to be a closed list of five, and two of them belonged to other modules — a transfer
 * finishing and an adjustment being made. That was harmless while every module shipped in every
 * build and actively wrong the moment one of them stopped: the preference matrix offered a
 * checkbox for an event that could never fire, and the label ("Transfer completed") was that
 * module's vocabulary sitting in a file that ships whether or not the module does.
 *
 * So the list is a filter. A module that raises an event describes it where the event lives, next
 * to the code that fires it, and this module renders whatever turned up. Low stock and the daily
 * audit stay here because this module is what raises them; a check-out stays because it is the
 * one movement type free ships that is addressed to a person.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE DEFAULTS LIVE HERE AND NOT IN THE DATABASE, which is the decision this file exists to record.
 *
 * The obvious alternative — give every user a subscription row on activation — makes the default
 * unchangeable: once ten thousand rows say `email_enabled = 1`, changing what a new install does
 * means rewriting everybody's settings, and there is no way to tell somebody who deliberately chose
 * the current value from somebody who never looked. A missing row means "never expressed a
 * preference", and that is a state worth keeping distinguishable.
 *
 * SO: no row means the default below. A row means somebody decided.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * EMAIL DEFAULTS OFF FOR EVERYTHING EXCEPT LOW STOCK, and that asymmetry is deliberate. An in-app
 * notification costs the reader nothing until they open the bell; an email interrupts them. Low
 * stock is the one event where the whole point is to reach somebody who is NOT looking at the
 * screen — the others (a transfer finished, an adjustment was made) are things you find when you
 * come back to the app.
 */
final class AlertType {

	/** Stock has fallen to or below an item's reorder level. */
	public const LOW_STOCK = 'low_stock';

	/** An asset was checked out to somebody. */
	public const ITEM_CHECKED_OUT = 'item_checked_out';

	/** The daily stock audit found drift, or something else the plugin wants to say. */
	public const SYSTEM = 'system';

	/**
	 * Every type, with its label and what happens when nobody has said otherwise.
	 *
	 * @return array<string, array{label: string, description: string, email: bool, browser: bool}>
	 */
	public static function all(): array {
		$types = array(
			self::LOW_STOCK        => array(
				'label'       => __( 'Low stock', 'plugixa-inventory' ),
				'description' => __( 'An item has fallen to or below its reorder level.', 'plugixa-inventory' ),
				// The one type that emails by default — see the class docblock.
				'email'       => true,
				'browser'     => true,
			),
			self::ITEM_CHECKED_OUT => array(
				'label'       => __( 'Asset checked out', 'plugixa-inventory' ),
				'description' => __( 'An asset has been checked out to somebody.', 'plugixa-inventory' ),
				'email'       => false,
				'browser'     => true,
			),
			self::SYSTEM           => array(
				'label'       => __( 'System', 'plugixa-inventory' ),
				'description' => __( 'The daily stock check found something worth looking at.', 'plugixa-inventory' ),
				'email'       => false,
				'browser'     => true,
			),
		);

		/**
		 * Filter the kinds of event somebody may subscribe to.
		 *
		 * A module that raises its own event adds it here, with the same four keys: `label`,
		 * `description`, `email` and `browser`. Anything malformed is dropped by `normalise()`
		 * rather than reaching the preference screen half-built.
		 *
		 * @param array<string, array{label: string, description: string, email: bool, browser: bool}> $types Alert type => descriptor.
		 */
		$types = (array) apply_filters( 'plugixa_inventory_alert_types', $types );

		return self::normalise( $types );
	}

	/**
	 * Throw away anything a listener got wrong, and fill in what it left out.
	 *
	 * A DESCRIPTOR WITH NO LABEL IS DROPPED, NOT DEFAULTED. The preference matrix renders one row
	 * per type with a checkbox pair, and a row labelled with its own machine key ("transfer_completed")
	 * is worse than a row that is not there — it looks like a bug in the translation rather than in
	 * whatever registered it. The two booleans DO default, because "nobody said" is a real answer
	 * for them and matches what `defaults()` already does for an unknown type.
	 *
	 * @param array<mixed> $types What the filter returned.
	 *
	 * @return array<string, array{label: string, description: string, email: bool, browser: bool}>
	 */
	private static function normalise( array $types ): array {
		$clean = array();

		foreach ( $types as $key => $spec ) {
			if ( ! is_string( $key ) || '' === $key || ! is_array( $spec ) ) {
				continue;
			}

			$label = (string) ( $spec['label'] ?? '' );

			if ( '' === $label ) {
				continue;
			}

			$clean[ $key ] = array(
				'label'       => $label,
				'description' => (string) ( $spec['description'] ?? '' ),
				'email'       => (bool) ( $spec['email'] ?? false ),
				'browser'     => (bool) ( $spec['browser'] ?? true ),
			);
		}

		return $clean;
	}

	/**
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::all() );
	}

	/**
	 * @param string $type Alert type.
	 */
	public static function known( string $type ): bool {
		return isset( self::all()[ $type ] );
	}

	/**
	 * What this type does for somebody who has never expressed a preference.
	 *
	 * @param string $type Alert type.
	 *
	 * @return array{email: bool, browser: bool}
	 */
	public static function defaults( string $type ): array {
		$spec = self::all()[ $type ] ?? array( 'email' => false, 'browser' => true );

		return array( 'email' => (bool) $spec['email'], 'browser' => (bool) $spec['browser'] );
	}
}
