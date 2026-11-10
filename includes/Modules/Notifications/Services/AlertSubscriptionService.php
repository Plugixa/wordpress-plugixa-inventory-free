<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications\Services;

use PlugixaInventory\Core\Security\Capabilities;
use PlugixaInventory\Modules\Notifications\AlertType;
use PlugixaInventory\Modules\Notifications\Repositories\AlertSubscriptionRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who should be told about what, and how.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE ANSWER IS A MERGE, NOT A LOOKUP, and getting that backwards is the bug this class exists to
 * prevent.
 *
 * Most people have no row in `alert_subscriptions`, because a row is a deliberate choice and nobody
 * has made one yet. So "who wants the low-stock email" cannot be answered by reading that table —
 * doing so returns the handful of people who have visited the preferences screen, and the alert
 * silently reaches nobody on a fresh install.
 *
 * The audience is therefore: everybody who can use this plugin, minus those who have said no.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * "EVERYBODY WHO CAN USE THIS PLUGIN" IS WHAT WORDPRESS WOULD LET IN, not the administrator role
 * and not one stored capability. A site that gave a warehouse-manager role some of the inventory's
 * capabilities expects those people to be the ones told about low stock — and asking about access
 * rather than about a role means this is right without the plugin knowing what roles exist. How to
 * ask is `Capabilities::holder_query()`; asking for `plugixa_inventory_manage` directly found the
 * administrators only, because that capability is derived and stored nowhere else.
 */
final class AlertSubscriptionService {

	/**
	 * How many users one alert will consider.
	 *
	 * A REAL BOUND. A multisite or a membership site can have tens of thousands of users, and
	 * `get_users()` with no limit loads every matching one into memory inside a cron run. Anybody
	 * who genuinely has more than this many people managing inventory has a different problem, and
	 * `plugixa_inventory_alert_audience` is the filter for it.
	 */
	public const MAX_AUDIENCE = 200;

	public function __construct( private readonly AlertSubscriptionRepository $subscriptions ) {}

	/**
	 * One user's effective preferences — their choices over the defaults.
	 *
	 * @param int $user_id The user.
	 *
	 * @return array<string, array{email: bool, browser: bool}>
	 */
	public function for_user( int $user_id ): array {
		$stored = $this->subscriptions->for_user( $user_id );
		$out    = array();

		foreach ( AlertType::keys() as $type ) {
			$out[ $type ] = $stored[ $type ] ?? AlertType::defaults( $type );
		}

		return $out;
	}

	/**
	 * Record one user's choices.
	 *
	 * @param int                                        $user_id     The user.
	 * @param array<string, array<string, mixed>>        $preferences Alert type => {email, browser}.
	 */
	public function save( int $user_id, array $preferences ): true|WP_Error {
		foreach ( $preferences as $type => $choice ) {
			$type = (string) $type;

			if ( ! AlertType::known( $type ) ) {
				return new WP_Error(
					'plugixa_inventory_unknown_alert_type',
					sprintf(
						/* translators: %s: the alert type that was asked for. */
						__( '"%s" is not something this plugin can notify about.', 'plugixa-inventory' ),
						$type
					),
					array( 'status' => 422 )
				);
			}

			$this->subscriptions->put(
				$user_id,
				$type,
				! empty( $choice['email'] ),
				! empty( $choice['browser'] )
			);
		}

		return true;
	}

	/**
	 * Everybody who should hear about one kind of event, and how each wants it.
	 *
	 * @param string $alert_type The type.
	 *
	 * @return array<int, array{email: bool, browser: bool}> Keyed by user id.
	 */
	public function audience( string $alert_type ): array {
		$defaults = AlertType::defaults( $alert_type );
		$explicit = $this->subscriptions->for_type( $alert_type );

		$candidates = get_users(
			array_merge(
				Capabilities::holder_query(),
				array(
					'number' => self::MAX_AUDIENCE,
					'fields' => 'ID',
				)
			)
		);

		$audience = array();

		foreach ( $candidates as $user_id ) {
			$user_id = (int) $user_id;

			// The query reads stored capabilities; this applies the whole picture. See holder_query().
			if ( ! user_can( $user_id, Capabilities::MANAGE ) ) {
				continue;
			}

			$choice = $explicit[ $user_id ] ?? $defaults;

			// Somebody who has switched BOTH off is not in the audience at all — including them
			// with two falses would have every caller re-check the same thing.
			if ( empty( $choice['email'] ) && empty( $choice['browser'] ) ) {
				continue;
			}

			$audience[ $user_id ] = array(
				'email'   => ! empty( $choice['email'] ),
				'browser' => ! empty( $choice['browser'] ),
			);
		}

		/**
		 * Filter who hears about one kind of event.
		 *
		 * The seam for a site whose rule is not "everyone who can manage inventory" — one warehouse's
		 * manager, an on-call rota, a Slack relay user. Returning an empty array silences the type
		 * without anybody having to unsubscribe one by one.
		 *
		 * @param array<int, array{email: bool, browser: bool}> $audience   Keyed by user id.
		 * @param string                                        $alert_type The type.
		 */
		return (array) apply_filters( 'plugixa_inventory_alert_audience', $audience, $alert_type );
	}

	/**
	 * Forget a user who no longer exists.
	 *
	 * @param int $user_id The user.
	 */
	public function forget_user( int $user_id ): int {
		return $this->subscriptions->forget_user( $user_id );
	}
}
