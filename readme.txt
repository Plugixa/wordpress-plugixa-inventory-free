=== Plugixa Inventory – Stock Management, Warehouses, Stock Ledger and WooCommerce Integration ===
Contributors: zakaria04, freemius
Tags: inventory, inventory management, stock management, warehouse, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inventory for WordPress: items, warehouses, stock movements and an append-only ledger, with CSV import and export, in a fast React admin.

== Description ==

📦 Turn WordPress into a working stock system with **Plugixa Inventory** - items, warehouses, stock movements and an append-only ledger, in a fast React admin that also runs on the front end of your site.

📚 Read the [**Documentation**](https://www.plugixa.com/documentation/plugixa-inventory)
🌐 Visit the official website [**Plugixa**](https://www.plugixa.com/)
✉️ Questions or support requests: [**Contact us**](https://www.plugixa.com/contact)

[__Documentation__](https://www.plugixa.com/documentation/plugixa-inventory) | [__Contact__](https://www.plugixa.com/contact) | [__Support Forum__](https://wordpress.org/support/plugin/plugixa-inventory/) | [__Upgrade to Pro!__](https://www.plugixa.com/plugins/plugixa-inventory#pricing)

Start counting stock in **3 simple steps**:

1. Add your warehouses and items - with SKUs, barcodes, cost and sale prices
2. Raise a stock movement - add, remove, check in or check out - and finalize it
3. Watch the dashboard and the ledger update, per warehouse

Records live in the plugin's **own database tables**, not in posts and post meta, so lists, filters and the dashboard stay fast as your history grows.

**Selling with WooCommerce?** The WooCommerce integration is part of Plugixa Inventory Pro: it connects one warehouse to your shop, posts every order to the stock ledger, and keeps WooCommerce showing what that warehouse holds. The free plugin works on its own and does not change WooCommerce.

### Stock That Is Actually Per Warehouse

Every quantity is held against a specific item and warehouse, so "how much is in the west depot" is a question the dashboard can answer.

✅ **Items** - assets, consumables and sellable goods, with SKUs, barcodes and reorder levels
✅ **Warehouses** - each with its own stock levels, low-stock list and valuation
✅ **Stock movements** - add, remove, check in and check out, drafted then finalized
✅ **Stock ledger** - every change ever made, which nothing can edit or delete

= Key Features of Plugixa Inventory =

* Fast, Material UI-based admin application
* Unlimited items, categories, warehouses and recipients
* Three item types - assets, consumables and sellables - with SKUs, barcodes and serial numbers
* Stock held per item and per warehouse
* Stock movements: add stock, remove stock, check in and check out
* Documents are drafted, then finalized - a finalized document cannot be edited or deleted
* Append-only stock ledger that on-hand quantities are derived from
* Stock health check that proves the quantities still follow from the ledger
* Dashboard with stock value, movement trend, low stock and top movers
* Warehouse overview with stock, low-stock and activity for one location
* Stock valued at cost, with average costing
* CSV import with column matching, opening stock and one-click revert
* CSV export from every list, filters included
* Low-stock notifications in the app and by email
* Scanner and quick search across items and screens
* `[plugixa_inventory_app]` shortcode - the whole app on a page of your site
* App manager to switch features on and off without losing their data
* Six inventory capabilities and a ready-made Inventory Manager role
* REST API (`plugixa-inventory/v1`), action and filter hooks, and WP-CLI commands
* No CDN, no Google Fonts - fonts and icons are served from your own site

= Plugixa Inventory Pro Features =

* WooCommerce integration - the shop sells what a warehouse holds, and every order, cancellation and refund is posted to the stock ledger
* Warehouse transfers - move stock between locations with both ledger legs
* Inventory adjustments - addition, damage and subtraction, with a reason
* FIFO and LIFO costing, with a cost layer per receipt
* Reports - turnover, cost of goods sold, trends and stock ageing, with exports
* Custom fields - twelve field types on items, warehouses and recipients
* Units of measurement, with conversions
* Barcode and QR labels - print item labels on label sheets or a thermal printer
* Roles screen - build and assign inventory roles from the app
* Permissions - decide who can see and change which records
* Dark mode
* Installable on a phone or desktop like a native app, with desktop notifications
* Priority support

[Upgrade to Plugixa Inventory Pro](https://www.plugixa.com/plugins/plugixa-inventory#pricing)

= 📒 An Append-Only Stock Ledger =

Every change to every quantity is written to a stock ledger that nothing can edit or delete: no REST route, no admin screen, and a veto in the data layer. On-hand quantities are derived from it, and a built-in reconcile proves they still are - on demand from the admin, daily in the background, or from WP-CLI with `wp plugixa-inventory stock reconcile`.

= 🔒 Built to Stay Correct =

Concurrent finalizes cannot oversell. The check that there is enough stock is part of the same SQL statement that removes it, so there is no window between deciding and writing. The daily check reports anything that disagrees with the ledger; it never silently corrects it.

= 💰 Stock Valued at What It Cost You =

Stock on hand is valued at the item's cost price, falling back to its sale price only for an item with no cost recorded. Valuing inventory at what it sells for would overstate it by your whole margin. Stock leaving is costed on the ledger row that records the removal, so what a movement cost and what the remaining stock is worth are two separate answers.

= 📥 Import and Export From a Spreadsheet =

Bring items, categories, warehouses and recipients in from a CSV, with a column-matching step before anything is written. Categories and warehouses are found by name or code, and a line naming something that does not exist is reported by line number instead of being invented for you. Opening stock comes in as a real, finalized stock movement, so the ledger agrees with it from the first day. An import can be reverted. Every list has an Export button that writes what you are looking at to a CSV.

= 🖥️ On the Front End, Not Only in wp-admin =

Add `[plugixa_inventory_app]` to a page and the whole application runs there, inside your theme, with a full-screen button in its toolbar. It is the same application and the same API, so access rules are identical - staff who should not be in wp-admin never have to go there.

### 👨‍💻 Author

[**Plugixa Inventory**](https://www.plugixa.com/plugins/plugixa-inventory) is developed by [**Plugixa**](https://www.plugixa.com/), building focused, well-documented WordPress plugins with an emphasis on performance, clean code and correct data.

### 🛟 Documentation & Support

Plugixa Inventory is built to be usable without a manual, but it is documented in full.

👉 Read the [Documentation](https://www.plugixa.com/documentation/plugixa-inventory) - getting started, stock movements, the ledger, import, the shortcode, REST API, hooks and WP-CLI
👉 Ask on the [WordPress.org support forum](https://wordpress.org/support/plugin/plugixa-inventory/)
👉 Or [contact us directly](https://www.plugixa.com/contact)
👉 Browse more plugins at [plugixa.com](https://www.plugixa.com/)

= Shortcode Usage =

Put the application on a page:
`[plugixa_inventory_app]`

With options:
`[plugixa_inventory_app width="full" height="800"]`

== Installation ==

= MINIMUM REQUIREMENTS =

* WordPress 6.5 or greater
* PHP version 8.2 or greater

= AUTOMATIC INSTALLATION (EASIEST WAY) =

1. In your WordPress admin, go to Plugins > Add New
2. Search for "Plugixa Inventory"
3. Click Install Now, then Activate
4. Open "Plugixa Inventory" in the admin menu and add your first warehouse and item

= MANUAL INSTALLATION =

1. Download the plugin ZIP
2. Go to Plugins > Add New > Upload Plugin, choose the ZIP and click Install Now
   (or upload the `plugixa-inventory` folder to `/wp-content/plugins/` over FTP)
3. Activate the plugin through the Plugins menu. The database tables are created on activation
4. Open "Plugixa Inventory" in the admin menu
5. Optional: add the `[plugixa_inventory_app]` shortcode to a page to use the app on the front end

= GIVING OTHER PEOPLE ACCESS =

Administrators have access by default. To give someone else access, edit the user under Users and assign the **Inventory Manager** role that ships with the plugin, or grant these capabilities to a role of your own:

* `plugixa_inventory_view`: see items, stock and reports
* `plugixa_inventory_create`: add records and raise documents
* `plugixa_inventory_edit`: change records and finalize documents
* `plugixa_inventory_delete`: trash and restore records
* `plugixa_inventory_export`: download CSV exports
* `plugixa_inventory_manage_settings`: change plugin settings

Grant at least `plugixa_inventory_view`, or the person can open the app but every screen in it will refuse. Do not grant `plugixa_inventory_manage` on its own: it is derived from the six above rather than stored.

== Frequently Asked Questions ==

= Q. How do I add stock? =

Open Stock movements, click Create, choose "Add stock" and a warehouse, add your items with a quantity and cost, then click "Save & finalize". Finalizing is what writes the ledger and changes the on-hand quantity.

= Q. Can staff use the inventory without going into wp-admin? =

Yes. Add `[plugixa_inventory_app]` to any page and the whole application appears on it, inside your theme. Permissions are identical to the admin screen. Anyone without access sees a short "sign in" notice instead, and the application is never loaded for them.

= Q. What options does the shortcode take? =

* `width`: `wide` (default), `full` or `content`
* `height`: a number of pixels. Left out, the app sizes itself to the window
* `mode`: `embed` (default), or `full` to give the app the whole page with no theme around it

For example: `[plugixa_inventory_app width="full" height="800"]`

= Q. Why does the app appear in a frame rather than directly in the page? =

Because it is the only way it can keep working on any theme. A frame keeps the app's styling and your theme's styling apart completely, so your theme stays exactly as you designed it and the inventory looks the same on every site.

= Q. Does it store stock as posts or custom post types? =

No. It uses its own database tables. Stock levels, ledger rows and document lines are relational data, and storing them as posts and post meta makes every list and report slower as the history grows.

= Q. Can stock go negative, or be sold twice? =

No. The check that there is enough stock is part of the same SQL statement that removes it, so two people finalizing at the same moment cannot both succeed against the same units.

= Q. Can I delete a ledger row? =

No, and there is no setting for it. The ledger is the record every on-hand quantity is derived from. Correct a mistake with a second, opposite document, which stays visible in the audit trail.

= Q. What happens to my inventory if I delete the plugin? =

Nothing, unless you ask for it. Deleting the plugin removes its files and keeps every item, warehouse, document and ledger row. To remove the data as well, a site administrator turns on Settings > Uninstall > "Delete all inventory data when the plugin is deleted" before deleting.

= Q. What is the difference between the free and Pro versions? =

The free plugin is a complete stock system - items, warehouses, stock movements, the ledger, the dashboard, valuation at cost, CSV import and export, and the front-end shortcode. Pro adds the WooCommerce integration, warehouse transfers, inventory adjustments, FIFO and LIFO costing, reports, custom fields, units, barcode and QR labels, the Roles screen and the Permissions screen, dark mode, an installable app and desktop notifications. Upgrading never moves or changes your data. See the [Pro features](https://www.plugixa.com/plugins/plugixa-inventory#pricing).

= Q. Does it need any external service? =

Not to manage your inventory. Everything is read from and written to your own WordPress database, and no inventory data is ever sent anywhere. The one connection the plugin can make is to Freemius, for licensing and updates, described under "External services" below.

= Q. Where is the source for the JavaScript the plugin loads? =

Inside the plugin. The admin application is written in TypeScript and ships at `assets/backend/app/src/`, beside the `package.json`, `package-lock.json` and `vite.config.js` that build it. Rebuild it with `cd assets/backend/app && npm install && npm run build`, which needs Node.js 20.19+ or 22.12+. `assets/backend/build/SOURCE.txt` carries the same directions.

= Q. Where can I find the documentation? =

Full documentation - getting started, stock movements, the ledger, import and export, the shortcode, REST API, hooks and WP-CLI - is at https://www.plugixa.com/documentation/plugixa-inventory

== External services ==

This plugin connects to one third-party service, and only for licensing and updates. No inventory data - no item, warehouse, document, quantity, cost or ledger row - is sent to it or to anywhere else, ever.

= Freemius =

Used for licence activation, plugin updates, and an optional opt-in. On activation you are shown an opt-in screen; if you skip it, no identifiable data is sent. When you activate a licence or check for an update, your site URL, the plugin and SDK versions and your licence key are sent to https://api.freemius.com. If you opt in, the data that screen lists is sent to the same address: your WordPress user's first and last name and email address; your site's homepage URL and title, WordPress and PHP versions and site language; the plugin's version and whether it is active or uninstalled; and, unless you switch that item off on the opt-in screen, the names, slugs and versions of your installed plugins and themes and whether each is active.
Provided by Freemius, Inc. - Terms: https://freemius.com/terms/ - Privacy: https://freemius.com/privacy/

There is no other outbound request. The Freemius SDK in `vendor/freemius/` is the only code in the plugin that performs one. Fonts and icons are served from your own site; nothing is loaded from a CDN or from Google Fonts.

== Screenshots ==

1. Dashboard - stock value, low stock, a 30-day movement trend and the top movers on one screen
2. Items - the catalogue, with SKUs, types and on-hand totals per item
3. Stock movement - a document is drafted first, then finalized to write the ledger
4. Stock ledger - every change to every quantity, newest first, which nothing can edit or delete
5. Warehouse overview - stock, low stock and recent activity for one location
6. Stock health - the reconcile that proves the numbers still follow from the ledger

== Changelog ==

= 1.1.1 =
* New (Pro): print barcode or QR labels for items on A4 and Letter label sheets or a thermal printer, from the item list or an item's page
* New (Pro): items that have no barcode can be given one in a click, ready to scan with the item lookup
* New: an Upgrade to Pro page lists what Pro adds, and the dashboard card about it can be dismissed
* Updating does not change any of your existing items, stock or history

= 1.1.0 =
* New (Pro): WooCommerce integration. Choose the warehouse your shop sells from: every order, cancellation, failed payment and refund with restock is posted to the stock ledger, and WooCommerce always shows what that warehouse holds
* New (Pro): link products and variations to items by matching SKU or by hand, or import them as items with their WooCommerce stock as the opening balance
* New (Pro): backorders take what the warehouse holds, and the rest is filled automatically, oldest order first, as soon as stock arrives
* New (Pro): sales made while syncing was switched off, or while Pro was inactive, are caught up when it comes back, before the shop's stock is updated
* New (Pro): the stock of a linked product is read-only in WooCommerce, and a nightly check corrects and logs any change made outside the inventory
* Compatible with WooCommerce High-Performance Order Storage (HPOS)
* Updating does not change any of your existing items, stock or history

= 1.0.0 =
* Initial release
* React admin application for items, categories, warehouses and recipients
* Stock movements: add, remove, check in and check out, drafted then finalized
* Append-only stock ledger with a built-in reconcile
* Dashboard and per-warehouse overview, with stock valued at cost
* CSV import with column matching and revert, and CSV export from every list
* `[plugixa_inventory_app]` shortcode for the front end
* REST API, developer hooks and WP-CLI commands

== Upgrade Notice ==

= 1.1.1 =
Adds Pro barcode and QR labels. Updating does not change your existing items, stock or history.

= 1.1.0 =
Adds the Pro WooCommerce integration. Updating does not change your existing items, stock or history.

= 1.0.0 =
Initial release of Plugixa Inventory.
