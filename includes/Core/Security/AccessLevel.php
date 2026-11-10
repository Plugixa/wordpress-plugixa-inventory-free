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
 * The access vocabulary: what a role may say, and which of two answers wins.
 *
 * Four levels on one ladder, most permissive first:
 *
 *   all   every record of that type
 *   team  records belonging to a team the user is on, plus their own and anything shared with them
 *   own   records assigned to the user, plus anything shared with them
 *   no    none
 *
 * ONE LADDER, NOT TWO. EspoCRM stores `create` as "yes"/"no" and everything else as
 * all/team/own/no, which means two vocabularies and a UI that has to know which cell uses which.
 * Creating a record has no owner to compare against — there is no record yet — so the middle rungs
 * are meaningless there, but they are meaningless in a way that collapses safely: `own` and `team`
 * both mean "may create" because neither is `no`. So `create` is stored on the same ladder and
 * rendered as a checkbox. Nothing is lost, and there is one comparison function instead of two.
 *
 * MOST PERMISSIVE WINS when a user holds several roles. That is Espo's rule and it is the only one
 * that makes roles composable: a "Support" role and a "Sales" role granted together should let you
 * do the union of both, otherwise adding a role could take access away and nobody could reason
 * about what a user can do without reading every role they hold.
 */
final class AccessLevel {

	public const ALL  = 'all';
	public const TEAM = 'team';
	public const OWN  = 'own';
	public const NONE = 'no';

	/**
	 * The ladder, most permissive first. Index IS the rank, so `max()` is an array lookup.
	 */
	private const RANK = array(
		self::NONE => 0,
		self::OWN  => 1,
		self::TEAM => 2,
		self::ALL  => 3,
	);

	/**
	 * The actions a role can rule on.
	 *
	 * `stream` is here although the Stream module does not exist yet. It is in Espo's matrix, it is
	 * the next phase, and a role saved today with no opinion about it would otherwise have to be
	 * re-edited later. An action nothing asks about costs a column in a grid.
	 */
	public const ACTIONS = array( 'read', 'create', 'edit', 'delete', 'stream' );

	/**
	 * Is this one of the four levels?
	 *
	 * @param mixed $level Candidate.
	 */
	public static function valid( mixed $level ): bool {
		return is_string( $level ) && array_key_exists( $level, self::RANK );
	}

	/**
	 * Coerce anything to a level, defaulting to the most restrictive.
	 *
	 * DEFAULTING TO `no` IS THE POINT. An unreadable value in a role's JSON — a typo, a key from a
	 * newer version, a hand-edited row — must not grant anything. The failure mode of a malformed
	 * role is a user who cannot see records and says so, not one who can see records nobody meant
	 * them to.
	 *
	 * @param mixed $level Candidate.
	 */
	public static function normalise( mixed $level ): string {
		return self::valid( $level ) ? (string) $level : self::NONE;
	}

	/**
	 * The more permissive of two levels.
	 *
	 * @param string $a One level.
	 * @param string $b The other.
	 */
	public static function max( string $a, string $b ): string {
		return self::rank( $a ) >= self::rank( $b ) ? self::normalise( $a ) : self::normalise( $b );
	}

	/**
	 * Does this level permit the action at all, whatever the record?
	 *
	 * @param string $level A level.
	 */
	public static function grants_anything( string $level ): bool {
		return self::NONE !== self::normalise( $level );
	}

	/**
	 * @param string $level A level.
	 */
	public static function rank( string $level ): int {
		return self::RANK[ self::normalise( $level ) ];
	}
}
