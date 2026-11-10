<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Lookup\Http;

use PlugixaInventory\Core\Http\RestController;
use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use PlugixaInventory\Modules\Stock\Repositories\ItemStockRepository;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "What is this?" — the barcode scanner's endpoint, and the quick search behind it.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A SCAN IS AN EXACT MATCH, AND THAT IS THE WHOLE DESIGN.
 *
 * A hand scanner types a code and presses Enter. If the answer is a LIST, the operator has to look
 * at the screen and choose — which defeats the point of the scanner, and is dangerous on a
 * check-out: picking the wrong row moves the wrong stock. So `/lookup` answers with ONE item or
 * with nothing, and never with a guess.
 *
 * `/lookup/search` is the other question — a human typing part of a name — and it answers with a
 * list on purpose. Keeping the two apart means neither has to be told which mode it is in.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * THE ORDER BARCODE → SKU → SERIAL IS NOT ARBITRARY. A scanner reads a barcode, so that is the
 * likeliest hit and costs one indexed lookup. SKU is next because it is what somebody types when
 * the label is damaged. Serial is last and is the only one that can identify a single physical
 * unit — which is also why a serial hit is worth telling the caller about explicitly, in `matched`.
 */
final class LookupController extends RestController {

	/** How many rows a quick search returns. Enough to choose from, few enough to read. */
	private const SEARCH_LIMIT = 20;

	public function __construct(
		private readonly ItemRepository $items,
		private readonly ItemStockRepository $stock
	) {}

	protected function entity_type(): string {
		return 'item';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/lookup',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'scan' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'code' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/lookup/search',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'search' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'q' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * One code in, one item out — or nothing.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function scan( WP_REST_Request $request ): WP_REST_Response {
		$code = trim( (string) $request->get_param( 'code' ) );

		if ( '' === $code ) {
			return $this->ok( array( 'found' => false, 'matched' => null, 'item' => null ) );
		}

		foreach ( array( 'barcode', 'sku', 'serial_number' ) as $column ) {
			$row = $this->exact( $column, $code );

			if ( null !== $row ) {
				return $this->ok(
					array(
						'found'   => true,
						// WHICH FIELD MATCHED, because the answer changes what the caller should do:
						// a serial identifies one physical unit, a SKU identifies a kind of thing.
						'matched' => $column,
						'item'    => $this->decorate( $row ),
					)
				);
			}
		}

		/*
		 * NOT A 404. "This barcode is not one of ours" is a normal answer to a normal question, and
		 * a scanner loop that had to distinguish an HTTP error from a miss would treat a network
		 * failure as an unknown item. A 200 saying `found: false` cannot be confused with either.
		 */
		return $this->ok( array( 'found' => false, 'matched' => null, 'item' => null ) );
	}

	/**
	 * Part of a name, a SKU, a barcode or a serial — several answers.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$term = trim( (string) $request->get_param( 'q' ) );

		if ( strlen( $term ) < 2 ) {
			// A one-character search matches most of the catalogue and tells nobody anything, so it
			// is answered with nothing rather than with everything.
			return $this->ok( array() );
		}

		$rows = $this->items->query()
			->where( "t.status = 'published'", array() )
			->search( $term, array( 't.name', 't.sku', 't.barcode', 't.serial_number' ) )
			->order_by( 't.name', 'ASC' )
			->paginate( self::SEARCH_LIMIT, 0 )
			->get();

		return $this->ok( array_map( array( $this, 'decorate' ), $rows ) );
	}

	/**
	 * An exact, live match on one column.
	 *
	 * PUBLISHED ONLY, and trashed items are excluded by `query()` itself. Scanning a code that
	 * belongs to a retired item and being handed it would let somebody check out stock of a thing
	 * the catalogue says is gone.
	 *
	 * @param string $column The column to match.
	 * @param string $code   The code.
	 *
	 * @return array<string, mixed>|null
	 */
	private function exact( string $column, string $code ): ?array {
		/*
		 * THE COLUMN IS A LITERAL FROM THIS FILE'S OWN LOOP, never from the request — the three
		 * names are written out above. It still goes through `%i` so that stays true by
		 * construction rather than by the reader checking.
		 */
		$rows = $this->items->query()
			->where( '%i = %s', array( $column, $code ) )
			->where( "t.status = 'published'", array() )
			->paginate( 1, 0 )
			->get();

		return array() === $rows ? null : $rows[0];
	}

	/**
	 * An item plus the one thing a scanner always wants next: how much of it there is.
	 *
	 * @param array<string, mixed> $row The item.
	 *
	 * @return array<string, mixed>
	 */
	private function decorate( array $row ): array {
		$row = $this->items->cast( $row );

		$row['stock_levels'] = $this->stock->levels( (int) $row['id'] );
		$row['on_hand']      = round( $this->stock->total( (int) $row['id'] ), 4 );

		return $row;
	}
}
