<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorisation for every inventory read and write.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * SIX REAL WORDPRESS CAPABILITIES, PLUS ONE THAT IS PLUMBING.
 *
 * Core answers WHICH ACTIONS you may perform, through capabilities WordPress itself understands —
 * so `get_role()`, `add_cap()`, Members, User Role Editor and the plugin's own role builder all see
 * the same thing. A premium module then answers the two questions a capability cannot: on WHICH
 * ENTITIES, and on WHOSE RECORDS ("own" / "team" / "all"). That is why `user_can()` takes the record
 * itself even though nothing here looks at it, and why every decision has routed through this one
 * method since P1.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * NOTHING HERE GRANTS THESE TO ANYBODY BUT AN ADMINISTRATOR, and that is worth saying out loud now
 * that the role builder is not in every build.
 *
 * `Activator::migrate()` grants all six to `administrator` and to nobody else. A build with no role
 * builder therefore has no BUILT-IN way to give a warehouse clerk access — the capabilities are
 * ordinary WordPress ones, so any role editor can do it, but the plugin ships no screen for it.
 * That is a product decision rather than an accident; it is recorded here because this file is
 * where somebody will come looking when a non-administrator cannot open the inventory.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `MANAGE` IS NOT ONE OF THE SIX AND IS NEVER A CHECKBOX. It means "may open the inventory app" —
 * the admin menu and the shortcode gate on it — and it is granted at runtime by `has_cap()` below
 * to anyone holding any of the six. Storing it as a seventh choice would let somebody build a role
 * with `VIEW` and no `MANAGE`: every REST call would pass and no menu would appear, which reads as
 * broken rather than as denied.
 *
 * THE IMPLICATION RUNS ONE WAY ONLY. Holding `MANAGE` does not confer the six, or the six would
 * mean nothing. Installs that predate this split are carried over once by
 * `Activator::backfill_capabilities()` instead.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final class Capabilities {

	public const MANAGE = 'plugixa_inventory_manage';

	public const VIEW   = 'plugixa_inventory_view';
	public const CREATE = 'plugixa_inventory_create';
	public const EDIT   = 'plugixa_inventory_edit';
	public const DELETE = 'plugixa_inventory_delete';
	public const EXPORT = 'plugixa_inventory_export';

	public const MANAGE_SETTINGS = 'plugixa_inventory_manage_settings';

	/**
	 * Records the version that carried this install onto the granular capabilities.
	 */
	public const BACKFILL_OPTION = 'plugixa_inventory_caps_backfilled';

	/**
	 * The action vocabulary `user_can()` speaks, mapped to the capability that answers it.
	 *
	 * @var array<string, string>
	 */
	public const ACTION_CAPS = array(
		'read'   => self::VIEW,
		'create' => self::CREATE,
		'edit'   => self::EDIT,
		'delete' => self::DELETE,
		'export' => self::EXPORT,
	);

	/**
	 * The capabilities a role may be given, in the order the role builder presents them.
	 *
	 * `MANAGE` is deliberately absent: it is derived, never chosen. See the class docblock.
	 *
	 * @var string[]
	 */
	public const GRANTABLE = array(
		self::VIEW,
		self::CREATE,
		self::EDIT,
		self::DELETE,
		self::EXPORT,
		self::MANAGE_SETTINGS,
	);

	/**
	 * Everything this plugin introduces, including the derived one. Administrators get all of it.
	 *
	 * @var string[]
	 */
	public const ALL = array(
		self::MANAGE,
		self::VIEW,
		self::CREATE,
		self::EDIT,
		self::DELETE,
		self::EXPORT,
		self::MANAGE_SETTINGS,
	);

	/**
	 * Grant `MANAGE` to anybody holding any of the six.
	 *
	 * WHY A FILTER RATHER THAN A STORED CAPABILITY. The alternative is for the role builder to write
	 * `MANAGE` alongside whatever was ticked, which works right up until somebody grants
	 * `plugixa_inventory_view` from Members, from WP-CLI or from another plugin's role editor —
	 * all of which are supposed to work, because these are ordinary WordPress capabilities. They
	 * would produce a user who passes every REST check and has no way in. Derived state that is
	 * computed cannot drift from the thing it is derived from.
	 */
	public static function register_hooks(): void {
		add_filter( 'user_has_cap', array( self::class, 'has_cap' ), 10, 1 );

		// Not in `Activator::migrate()`: this reads users, and the functions for that are pluggable.
		add_action( 'init', array( self::class, 'backfill' ), 0 );
	}

	/**
	 * Carry pre-split installs onto the six granular capabilities. Runs exactly once.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * WITHOUT THIS, UPGRADING TAKES EVERYONE'S ACCESS AWAY. Before the split there was one
	 * capability and holding it meant you could do everything. Afterwards `read` asks for
	 * `VIEW`, which nobody has ever been granted — so every hand-granted user and every role
	 * somebody added `MANAGE` to would keep the menu (`has_cap()` above still opens the app) and be
	 * refused by all forty permission callbacks behind it. That is the worst shape a permission bug
	 * can take: the door opens and nothing inside works.
	 *
	 * BOTH PLACES A CAPABILITY CAN LIVE, for the reason `revoke_all()` records — roles in the
	 * `wp_user_roles` option, personal grants in `wp_usermeta`. Sweeping only the first would leave
	 * exactly the users an administrator had singled out.
	 *
	 * ONCE, AND MARKED. Re-running would quietly undo a deliberate narrowing: somebody who edited
	 * "Warehouse Staff" down to view-only would find it back to full access after the next update.
	 * The marker stores the version that ran it, matching `plugixa_inventory_costing_seeded_for`.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 */
	public static function backfill(): void {
		if ( false !== get_option( self::BACKFILL_OPTION ) ) {
			return;
		}

		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( self::MANAGE ) ) {
				foreach ( self::GRANTABLE as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}

		$users = new \WP_User_Query(
			array(
				'capability' => self::MANAGE,
				'fields'     => 'ID',
				'number'     => -1,
			)
		);

		foreach ( (array) $users->get_results() as $user_id ) {
			$user = get_userdata( (int) $user_id );

			/*
			 * ONLY PERSONAL GRANTS. Somebody who holds `MANAGE` through a role was already handled
			 * by the loop above, and copying the six onto them here would pin the old permissions
			 * to the person rather than the role — narrowing the role afterwards would then change
			 * nothing for anybody who happened to be in it at upgrade time.
			 */
			if ( false === $user || ! isset( $user->caps[ self::MANAGE ] ) ) {
				continue;
			}

			foreach ( self::GRANTABLE as $cap ) {
				$user->add_cap( $cap );
			}
		}

		update_option( self::BACKFILL_OPTION, PLUGIXA_INVENTORY_VERSION );
	}

	/**
	 * @param array<string, bool> $allcaps Every capability the user holds.
	 *
	 * @return array<string, bool>
	 */
	public static function has_cap( array $allcaps ): array {
		/*
		 * AN ADMINISTRATOR HOLDS EVERYTHING, UNCONDITIONALLY, AND THIS IS THE SAME ESCAPE HATCH THE
		 * REST OF THE PLUGIN ALREADY RELIES ON — the premium resolver short-circuits on
		 * `manage_options` before it reads a single role, and `RoleManager` refuses to narrow the
		 * `administrator` role at all.
		 *
		 * Activation writes these onto the role as well, so the stored state and this agree and a
		 * third-party role editor sees the truth. This is the belt: capabilities are ordinary
		 * WordPress strings and anything on the site may remove one, and an administrator who lost
		 * `VIEW` to somebody else's bulk edit would get a 403 from all forty routes with no symptom
		 * pointing at what did it.
		 */
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( self::ALL as $cap ) {
				$allcaps[ $cap ] = true;
			}

			return $allcaps;
		}

		if ( ! empty( $allcaps[ self::MANAGE ] ) ) {
			return $allcaps;
		}

		foreach ( self::GRANTABLE as $cap ) {
			if ( ! empty( $allcaps[ $cap ] ) ) {
				$allcaps[ self::MANAGE ] = true;

				break;
			}
		}

		return $allcaps;
	}

	/**
	 * `WP_User_Query` arguments that find everybody who may open the inventory — and possibly a few
	 * who may not, which is why every caller confirms each result with `user_can( $id, MANAGE )`.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * NEVER `'capability' => MANAGE`. A user query reads only what is STORED — capabilities on roles
	 * in the roles option, and on users in their meta — and `MANAGE` is stored almost nowhere: it is
	 * derived at runtime by `has_cap()` above. Asking for it found the administrators and nobody
	 * else, so a stores clerk given a role through the role screen was never sent a low-stock alert
	 * and could not be picked as anybody's assignee. `InventoryUsersTest`.
	 *
	 * So the query asks for anything `has_cap()` derives access FROM: any of the six, `MANAGE` itself
	 * where an older install stored it, and `manage_options`, which is given everything. Stored state
	 * can still overstate — a role grants View and the person has it explicitly taken away — and
	 * `user_can()` is the only thing that applies the whole picture, including any filter another
	 * plugin adds.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @return array{capability__in: string[]}
	 */
	public static function holder_query(): array {
		return array( 'capability__in' => array_merge( self::ALL, array( 'manage_options' ) ) );
	}

	/**
	 * May the current user perform an action on an entity?
	 *
	 * @param string                    $action      read|create|edit|delete|export.
	 * @param string                    $entity_type Entity slug.
	 * @param array<string, mixed>|null $record      The record, when acting on one.
	 * @param int|null                  $user_id     Defaults to the current user.
	 */
	public static function user_can( string $action, string $entity_type, ?array $record = null, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		/*
		 * AN UNRECOGNISED ACTION FALLS BACK TO `MANAGE`, WHICH IS THE NARROW ANSWER HERE, not the
		 * lax one: `MANAGE` is held only by people who already hold one of the six. A new verb
		 * added without a capability therefore behaves like the old all-or-nothing model until it
		 * is mapped, rather than being refused outright — a route nobody can call is a harder bug
		 * to spot than one that is merely not yet granular.
		 */
		$allowed = user_can( $user_id, self::ACTION_CAPS[ $action ] ?? self::MANAGE );

		/**
		 * Filter whether a user may perform an action on an inventory entity.
		 *
		 * @param bool                      $allowed     The free (capability-only) verdict.
		 * @param string                    $action      read|create|edit|delete|export.
		 * @param string                    $entity_type Entity slug.
		 * @param array<string, mixed>|null $record      The record, when acting on one.
		 * @param int                       $user_id     User being checked.
		 */
		return (bool) apply_filters( 'plugixa_inventory_user_can', $allowed, $action, $entity_type, $record, $user_id );
	}

	/**
	 * The capability map exposed to the React app so it can hide UI a user cannot use.
	 *
	 * IT HIDES A CONTROL, IT DOES NOT ENFORCE ANYTHING — every one of these is re-asked on the
	 * server by the route's own `permission_callback`. A client that lied would only be showing
	 * somebody a button that 403s.
	 *
	 * `settings` STAYS `manage_options` AND IS NOT THE SAME QUESTION AS `manage_settings`. The
	 * first is "may you administer this site", which gates the App Manager, the sample-content tool and the
	 * stock reconcile writes; the second is "may you change the inventory's own settings". Three
	 * screens read `settings` today and none of them means the narrower thing.
	 *
	 * @return array<string, bool>
	 */
	public static function map(): array {
		$map = array(
			'manage'          => current_user_can( self::MANAGE ),
			'view'            => current_user_can( self::VIEW ),
			'create'          => current_user_can( self::CREATE ),
			'edit'            => current_user_can( self::EDIT ),
			'delete'          => current_user_can( self::DELETE ),
			'export'          => current_user_can( self::EXPORT ),
			'manage_settings' => current_user_can( self::MANAGE_SETTINGS ),
			'settings'        => current_user_can( 'manage_options' ),
		);

		/**
		 * Filter the capability map handed to the admin application.
		 *
		 * @param array<string, bool> $map Capability => granted.
		 */
		return (array) apply_filters( 'plugixa_inventory_capabilities', $map );
	}

	/**
	 * Grant the inventory capabilities to a role.
	 *
	 * @param array<string, string[]> $caps Capability => role names.
	 */
	public static function grant( array $caps ): void {
		foreach ( $caps as $cap => $roles ) {
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );
				$role?->add_cap( $cap );
			}
		}
	}

	/**
	 * Give one WordPress user access to the inventory.
	 *
	 * THE CAPABILITY GOES ON THE USER, NOT ON A ROLE, and that is the whole design of this pair.
	 * `WP_User::add_cap()` writes to that person's own `wp_capabilities` meta and touches nothing
	 * else. `get_role( 'editor' )->add_cap()` would change what EVERY editor on the site can do,
	 * including on parts of the site that have nothing to do with the inventory, and it would keep doing so
	 * for every editor created afterwards — a grant nobody made, to people nobody looked at.
	 *
	 * It also cannot demote anybody, which the role-shaped alternative can: `Modules\Portal\Access`
	 * assigns its role with `remove_role()` then `add_role()`, and that is precisely why portal
	 * self-enrolment has to refuse administrators outright. There is no such hazard here.
	 *
	 * @param int $user_id The WordPress user.
	 */
	public static function grant_user( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return false;
		}

		/*
		 * THE SIX, NOT `MANAGE`. This method means "this person may use the inventory", and since
		 * the capability split that sentence is spelled with the six granular capabilities —
		 * granting only `MANAGE` would hand somebody the menu and a refusal behind every screen
		 * on it. `MANAGE` follows from them through `has_cap()` and is never stored.
		 */
		foreach ( self::GRANTABLE as $cap ) {
			$user->add_cap( $cap );
		}

		return true;
	}

	/**
	 * Take inventory access away from one WordPress user.
	 *
	 * NOTE WHAT THIS CANNOT DO. It removes a USER-level capability. An administrator holds
	 * `plugixa_inventory_manage` through the `administrator` ROLE (see `Core\Activator`), so calling this
	 * on one removes nothing and `user_can()` still answers true. That is not a bug to work around
	 * by editing the role — stripping the inventory from the administrator role would take it from every
	 * administrator on the site at once. It is a fact the UI has to state instead, which is why the
	 * access screen lists administrators as "via role" and offers them no button.
	 *
	 * @param int $user_id The WordPress user.
	 */
	public static function revoke_user( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return false;
		}

		// `MANAGE` too, so an install that predates the split is cleaned up by the same call.
		foreach ( array_merge( self::GRANTABLE, array( self::MANAGE ) ) as $cap ) {
			$user->remove_cap( $cap );
		}

		return true;
	}

	/**
	 * Whether this user holds the capability through a ROLE rather than a personal grant.
	 *
	 * Read off the user's own capability array: `WP_User::$caps` is the per-user grants only, while
	 * `allcaps` is those merged with everything their roles bring. Holding it in `allcaps` but not in
	 * `caps` means a role is the source, and this screen cannot take it away.
	 *
	 * IT CANNOT ASK ABOUT `MANAGE`, WHICH IS THE TRAP THE CAPABILITY SPLIT LAID HERE. `MANAGE` is
	 * derived by `has_cap()` and therefore never appears in `$user->caps` for anybody — so the old
	 * one-line test would call every personal grant a role grant, and the access screen would show
	 * "via role" beside people it could perfectly well revoke. The personal grant to look for is
	 * any of the six.
	 *
	 * @param int $user_id The WordPress user.
	 */
	public static function granted_by_role( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return false;
		}

		if ( ! user_can( $user_id, self::MANAGE ) ) {
			return false;
		}

		foreach ( array_merge( self::GRANTABLE, array( self::MANAGE ) ) as $cap ) {
			if ( isset( $user->caps[ $cap ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove every capability this plugin introduced. Used by the uninstaller.
	 *
	 * BOTH PLACES A CAPABILITY CAN LIVE, AND THE SECOND ONE WAS MISSING FOR FOUR RELEASES.
	 *
	 * This walked `wp_roles()->role_objects` and nothing else, which was complete for exactly as long
	 * as the plugin only ever granted to roles. Per-user grants live in `wp_usermeta`, not in the
	 * roles option — so once the access screen existed, uninstalling would have left
	 * `plugixa_inventory_manage` on every user ever granted it, on a site with no plugin to define what it
	 * means. Dead capabilities are not harmless: the next plugin to check a name it does not own, or
	 * the next reinstall, inherits a set of permissions nobody remembers granting.
	 *
	 * The user sweep runs first. Reversing them would be equally correct today, but a role-level
	 * revoke can change what `WP_User_Query` matches on the very next line, and a cleanup whose
	 * completeness depends on statement order is one statement away from being wrong.
	 */
	public static function revoke_all(): void {
		delete_option( self::BACKFILL_OPTION );

		/*
		 * EVERY INVENTORY CAPABILITY, NOT `MANAGE`. `grant_user()`, Members and WP-CLI all store the six
		 * and never `MANAGE`, so asking for `MANAGE` swept only users of a role that stored it — the
		 * administrators — and left every personal grant behind. What is swept here is what is STORED,
		 * so no runtime check follows: a user matched only through their role simply has nothing
		 * personal to remove.
		 */
		$users = new \WP_User_Query(
			array(
				'capability__in' => self::ALL,
				'fields'         => 'ID',
				'number'         => -1,
			)
		);

		foreach ( (array) $users->get_results() as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( false === $user ) {
				continue;
			}

			foreach ( array_keys( (array) $user->caps ) as $cap ) {
				if ( str_starts_with( (string) $cap, 'plugixa_inventory_' ) ) {
					$user->remove_cap( (string) $cap );
				}
			}
		}

		$roles = wp_roles();

		foreach ( $roles->role_objects as $role ) {
			foreach ( array_keys( $role->capabilities ) as $cap ) {
				if ( str_starts_with( (string) $cap, 'plugixa_inventory_' ) ) {
					$role->remove_cap( (string) $cap );
				}
			}
		}
	}
}
