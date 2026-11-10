<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Filters;

use PlugixaInventory\Core\Entities\EntityRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WHAT A GIVEN LIST MAY BE FILTERED BY. The allow-list, assembled once and consulted twice.
 *
 * It is consulted by the route that tells the client what to draw, and again by the compiler that
 * turns a request into SQL. Those must be the same list or the product has a filter it offers and
 * refuses, or — far worse — one it refuses to draw and still honours.
 *
 * The catalogue has two halves and they arrive differently, on purpose:
 *
 *   COLUMNS are DECLARED, by the owning repository's `filterable()`. They have to be, because a
 *   field in `EntityRegistry` is not necessarily a column: `plugixa_inventory_entity_fields` lets a
 *   module bolt a computed roll-up onto an entity it does not own, and "Open cases" on an Account
 *   is not something `t.open_cases = %d` can ask about. Declaring is the only way to know.
 *
 *   CUSTOM FIELDS are DERIVED, from the definitions table, by the CustomFields module answering the
 *   same filter at priority 20. No declaration, because none is needed — a custom field is always a
 *   real row with a known type, addressed by an integer id. The rule the product promises is "if
 *   the form has the field, the list filters by it", and deriving is the only way to keep that true
 *   without a list somebody has to remember to update.
 *
 * FIELDS THE USER MAY NOT READ ARE REMOVED, and this is not cosmetic. A filter on a hidden column
 * is an oracle: given `amount__gt`, twenty requests binary-search the value of a field the reader
 * was never allowed to see, and every one of them returns a perfectly ordinary list. EspoCRM prunes
 * its filter layout by `getScopeForbiddenFieldList` and then re-checks on apply; we do both too,
 * because pruning alone only stops the honest client.
 */
final class FilterCatalogue {

	/**
	 * The registry is read only to describe an `entity` filter's target — its list route and its
	 * label. Nothing here asks it what may be filtered; that stays the owning module's answer.
	 */
	public function __construct( private readonly EntityRegistry $entities ) {}

	/**
	 * Memoised per entity type. One list request asks for this several times — once to decide the
	 * route is filterable at all, then once per filter param to look the field up — and rebuilding
	 * means re-running the definitions query, and for deals the stages query, every time.
	 *
	 * IT MUST BE FLUSHED WHEN A DEFINITION CHANGES. See flush().
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $cache = array();

	/**
	 * Every filter available on this entity type, for the current user.
	 *
	 * @param string $entity_type Registry slug, e.g. "contact".
	 *
	 * @return array<string, array<string, mixed>> Keyed by filter key.
	 */
	public function for( string $entity_type ): array {
		if ( isset( $this->cache[ $entity_type ] ) ) {
			return $this->cache[ $entity_type ];
		}

		/**
		 * What a list of this entity may be filtered by.
		 *
		 * Answered at priority 10 by the module that owns the entity, from its repository's
		 * `filterable()`, and at priority 20 by CustomFields, which appends every field defined on
		 * the entity. A module may add a filter to an entity it does not own — the shape is the
		 * same as `Repository::filterable()` documents.
		 *
		 * @param array<string, array<string, mixed>> $fields      Filter key => descriptor.
		 * @param string                              $entity_type Registry slug.
		 */
		$fields = (array) apply_filters( 'plugixa_inventory_filterable_fields', array(), $entity_type );

		$this->cache[ $entity_type ] = $this->readable( $this->valid( $fields ), $entity_type );

		return $this->cache[ $entity_type ];
	}

	/**
	 * One descriptor, or null if this entity has no such filter.
	 *
	 * @param string $entity_type Registry slug.
	 * @param string $key         Filter key off the wire.
	 *
	 * @return array<string, mixed>|null
	 */
	public function field( string $entity_type, string $key ): ?array {
		return $this->for( $entity_type )[ $key ] ?? null;
	}

	/**
	 * Forget everything memoised. Called whenever a custom field definition changes.
	 *
	 * A CACHE THAT OUTLIVES ITS INPUT IS A BUG WAITING FOR A SLOW DAY. Within one web request this
	 * could not go stale — nothing defines a field and lists records in the same request — and it
	 * would have been easy to leave it at that and call the lifetime an implementation detail. It
	 * is not: the container is a singleton, so anything holding it open for longer than a request
	 * (the test suite does exactly this, and WP-CLI would) gets a catalogue frozen at whatever the
	 * first caller asked for. The suite found it immediately, which is the only reason this note is
	 * a design decision rather than a bug report from someone scripting an import.
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * The catalogue as the client needs it: no SQL, plus the operators each field may use.
	 *
	 * THE `sql` KEY NEVER LEAVES THE SERVER. Not because knowing `t.amount` would help an attacker
	 * — it would not — but because a client that has seen a column name is a client that will
	 * eventually send one, and then someone will make it work. `GET /reports/sources` has answered
	 * this way since Reports shipped and this matches it deliberately.
	 *
	 * @param string $entity_type Registry slug.
	 *
	 * @return array<int, array<string, mixed>> An ordered list, because a drawer draws it in order.
	 */
	public function present( string $entity_type ): array {
		$presented = array();

		foreach ( $this->for( $entity_type ) as $key => $descriptor ) {
			$type = (string) $descriptor['type'];

			$entry = array(
				'key'              => $key,
				'label'            => (string) $descriptor['label'],
				'type'             => $type,
				'operators'        => Operators::allowed_for( $type ),
				'default_operator' => Operators::default_for( $type ),
				'custom'           => ! empty( $descriptor['custom'] ),
			);

			/*
			 * THE HEADING A GROUPED FIELD SITS UNDER, IF WHOEVER CONTRIBUTED IT SUPPLIED ONE.
			 *
			 * `custom` says a field is grouped below the entity's own columns; this says what to
			 * call the group. They are separate because the flag is a MECHANISM — `FilterCompiler`
			 * reads it to choose a semi-join over a column comparison — and the heading is
			 * VOCABULARY belonging to whichever module contributed the fields. The drawer used to
			 * spell that heading out itself, which put one module's name in a Core file that ships
			 * whether or not the module does.
			 *
			 * Omitted when empty, so the client's "draw no heading" branch is the natural one.
			 */
			if ( ! empty( $descriptor['group_label'] ) ) {
				$entry['group_label'] = (string) $descriptor['group_label'];
			}

			if ( isset( $descriptor['options'] ) ) {
				$entry['options'] = array_values( (array) $descriptor['options'] );
			}

			// Only for options the client cannot possibly have a map for — user-named rows.
			if ( isset( $descriptor['labels'] ) ) {
				$entry['labels'] = (array) $descriptor['labels'];
			}

			/*
			 * AN `entity` FILTER HAS TO CARRY WHERE ITS CHOICES COME FROM, or the drawer draws an
			 * empty dropdown. The client has an `entityRegistry`, but nothing registers into it --
			 * it is a premium seam no module uses -- so the slug alone tells the client nothing.
			 * The registry that IS populated is this one, on the server, and it already knows the
			 * `api_base` to list and how to title it. Sending those two turns the slug into
			 * something renderable without the client needing a second registry to agree with.
			 */
			if ( isset( $descriptor['entity'] ) ) {
				$slug            = (string) $descriptor['entity'];
				$entry['entity'] = $slug;

				$target = $this->entities->get( $slug );

				if ( null !== $target ) {
					$entry['entity_api_base'] = (string) ( $target['api_base'] ?? '' );
					$entry['entity_label']    = (string) ( $target['label'] ?? $slug );
				}
			}

			$presented[] = $entry;
		}

		return $presented;
	}

	/**
	 * Drop anything malformed, so a bad descriptor is an absent filter rather than a broken query.
	 *
	 * A third-party module answering the filter is the reason this exists. A descriptor with no
	 * `type`, or a type nothing knows, would reach `Operators::allowed_for()` and come back with an
	 * empty operator list — a filter that renders and can never be applied. Refusing it here means
	 * the failure is "the field is not offered", which is at least honest.
	 *
	 * @param array<string, mixed> $fields Raw descriptors.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function valid( array $fields ): array {
		$valid = array();

		foreach ( $fields as $key => $descriptor ) {
			if ( ! is_string( $key ) || '' === $key || ! is_array( $descriptor ) ) {
				continue;
			}

			$type = (string) ( $descriptor['type'] ?? '' );

			if ( ! in_array( $type, Operators::TYPES, true ) ) {
				/*
				 * A SILENT `continue` IS WHY SEVENTEEN OF THESE SHIPPED UNNOTICED.
				 *
				 * Six repositories declared `'record'` -- correct in the OTHER vocabulary, the one
				 * `EntityRegistry` field descriptors use, where `EntityExporter` reads it -- and one
				 * declared `'boolean'` four times where the type is `'bool'`. One word, two
				 * vocabularies, and the penalty for confusing them was a filter that never appeared
				 * rather than an error anybody could see. Every foreign-key filter in the plugin was
				 * missing from the drawer for that reason.
				 *
				 * The drop still has to happen -- a third-party module answering the filter must not
				 * be able to reach `Operators::allowed_for()` with a type nothing knows, which
				 * returns an empty operator list and draws a filter that can never be applied. But
				 * under WP_DEBUG it now says so.
				 */
				self::complain(
					sprintf(
						'Filter "%1$s" declares type "%2$s", which is not one of: %3$s. It has been dropped.',
						$key,
						$type,
						implode( ', ', Operators::TYPES )
					)
				);

				continue;
			}

			// A column filter needs a fragment; a custom field needs a definition id. Exactly one
			// of the two, because they compile down completely different paths.
			$is_custom = ! empty( $descriptor['custom'] );

			if ( $is_custom ? empty( $descriptor['field_id'] ) : empty( $descriptor['sql'] ) ) {
				// The second half of the same trap: CustomFieldRepository's six declared no `sql`
				// at all, so correcting only their `type` would have moved them from one silent
				// drop to the other.
				self::complain(
					sprintf(
						'Filter "%s" has no SQL fragment to compare against. It has been dropped.',
						$key
					)
				);

				continue;
			}

			$descriptor['label'] = (string) ( $descriptor['label'] ?? $key );

			$valid[ $key ] = $descriptor;
		}

		return $valid;
	}

	/**
	 * Say that a descriptor was dropped, to a developer and to nobody else.
	 *
	 * `_doing_it_wrong()` RATHER THAN A LOG LINE, because this is a mistake in code that can only be
	 * made by whoever wrote the descriptor — the same audience the function exists for — and it is
	 * silent unless WP_DEBUG is on, so a production site sees nothing new. It is deliberately not an
	 * exception: one bad descriptor from one module must not take down the filter drawer for every
	 * other entity, which is exactly the fragility the `continue` was protecting against.
	 *
	 * @param string $message What was wrong.
	 */
	private static function complain( string $message ): void {
		_doing_it_wrong( __METHOD__, esc_html( $message ), '1.0.0' );
	}

	/**
	 * Remove fields the current user may not read.
	 *
	 * Custom fields are not subject to this: field-level ACL is declared against an entity's own
	 * columns, and `AccessResolver` has no concept of a `cf_` key. A custom field a user should not
	 * see is a gap in the ACL model, not in this filter — and it is the same gap the detail panel
	 * already has, so closing it here alone would only make the two disagree.
	 *
	 * @param array<string, array<string, mixed>> $fields      Valid descriptors.
	 * @param string                              $entity_type Registry slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function readable( array $fields, string $entity_type ): array {
		$denied = self::denied_fields( $entity_type );

		if ( array() === $denied ) {
			return $fields;
		}

		return array_diff_key( $fields, array_flip( $denied ) );
	}

	/**
	 * The columns of this entity the current user may not read.
	 *
	 * Static because the compiler needs the same answer and must not depend on an instance being
	 * threaded to it. The data lives in the premium-shaped Roles module and Core cannot import it,
	 * so it is asked for the same way record-level access is: through a filter, defaulting to
	 * "nothing is denied" when no module answers.
	 *
	 * @param string $entity_type Registry slug.
	 *
	 * @return string[]
	 */
	public static function denied_fields( string $entity_type ): array {
		/**
		 * Fields of an entity the current user may not read.
		 *
		 * Answered by the Roles module from `AccessResolver::unreadable_fields()`. With no roles
		 * module present nothing is denied, which is the pre-roles behaviour and the correct
		 * default for a filter catalogue.
		 *
		 * @param string[] $denied      Field names.
		 * @param string   $entity_type Registry slug.
		 * @param int      $user_id     Whose access is being asked about.
		 */
		return array_values(
			array_filter(
				array_map(
					'strval',
					(array) apply_filters(
						'plugixa_inventory_unreadable_fields',
						array(),
						$entity_type,
						get_current_user_id()
					)
				)
			)
		);
	}
}
