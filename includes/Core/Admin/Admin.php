<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Admin;

use PlugixaInventory\Core\App\Bundle;
use PlugixaInventory\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin menu and the mount point for the React application.
 *
 * Every inventory screen is a route inside one single-page app, so the submenus are hash
 * links into it rather than separate PHP pages.
 */
final class Admin {

	private const SLUG = 'plugixa-inventory';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Plugixa Inventory', 'plugixa-inventory' ),
			__( 'Plugixa Inventory', 'plugixa-inventory' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-groups',
			30
		);

		$render = array( $this, 'render' );

		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'plugixa-inventory' ),
			__( 'Dashboard', 'plugixa-inventory' ),
			Capabilities::MANAGE,
			self::SLUG,
			$render
		);

		/**
		 * Register additional inventory admin submenus.
		 *
		 * Free entity modules and the premium overlay both hook this, so neither has
		 * to edit this file to appear in the menu.
		 *
		 * @param string   $parent_slug The inventory top-level menu slug.
		 * @param callable $render      Callback that renders the app container.
		 */
		do_action( 'plugixa_inventory_admin_submenus', self::SLUG, $render );
	}

	/**
	 * The app's mount point. React takes over from here.
	 *
	 * The markup is `Bundle`'s rather than a literal, because `[plugixa_inventory_app]` needs the same
	 * element and `main.tsx` finds it by id — three files agreeing on one string, none of which is
	 * where you would look for the other two.
	 */
	public function render(): void {
		printf( '<div id="%s"></div>', esc_attr( Bundle::ROOT_ID ) );
	}
}
