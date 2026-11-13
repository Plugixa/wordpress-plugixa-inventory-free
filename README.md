<div align="center">

<img src="https://ps.w.org/plugixa-inventory/assets/icon-256x256.png" width="88" alt="Plugixa Inventory icon" />

# Plugixa Inventory - Stock Management, Warehouses & Stock Ledger

**A WordPress inventory plugin**: items, warehouses, stock movements and an append-only stock ledger, CSV import and export, and a fast React admin that also runs on the front end of your site.

[![Version](https://img.shields.io/wordpress/plugin/v/plugixa-inventory?style=flat-square&label=version)](https://wordpress.org/plugins/plugixa-inventory/)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/plugixa-inventory?style=flat-square&label=downloads&logo=wordpress)](https://wordpress.org/plugins/plugixa-inventory/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/plugixa-inventory?style=flat-square&label=tested&logo=wordpress)](https://wordpress.org/plugins/plugixa-inventory/)
[![Requires PHP](https://img.shields.io/badge/PHP%E2%89%A58.2-777BB4?style=flat-square&logo=php&logoColor=white)](https://wordpress.org/plugins/plugixa-inventory/)
[![License](https://img.shields.io/badge/GPL--2.0%2B-green?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

<img src="https://ps.w.org/plugixa-inventory/assets/banner-1544x500.png" width="772" alt="Plugixa Inventory - stock management for WordPress" />

</div>

## Links

| | |
|---|---|
| 📚 **Documentation** - getting started, items, warehouses, stock movements, the ledger, import and export | [plugixa.com/documentation/plugixa-inventory](https://www.plugixa.com/documentation/plugixa-inventory?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=docs) |
| 📰 **News** - releases, announcements, what is coming | [plugixa.com/news](https://www.plugixa.com/news?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=news) |
| 📋 **Changelog** - every release described in detail | [plugixa.com/changelog](https://www.plugixa.com/changelog?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=changelog) |
| ✉️ **Contact us** - pre-sales questions and support requests | [plugixa.com/contact](https://www.plugixa.com/contact?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=contact) |
| 🚀 **Plugixa Inventory Pro** - premium features and pricing | [plugixa.com/plugins/plugixa-inventory](https://www.plugixa.com/plugins/plugixa-inventory?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=pricing#pricing) |
| 💬 **Support forum** - free help, answered on WordPress.org | [wordpress.org/support/plugin/plugixa-inventory](https://wordpress.org/support/plugin/plugixa-inventory/) |

## Screenshots

<table>
<tr>
<td><img src="https://ps.w.org/plugixa-inventory/assets/screenshot-1.png" alt="" width="330" /></td>
<td><img src="https://ps.w.org/plugixa-inventory/assets/screenshot-2.png" alt="" width="330" /></td>
<td><img src="https://ps.w.org/plugixa-inventory/assets/screenshot-3.png" alt="" width="330" /></td>
</tr>
<tr>
<td align="center"><sub>Dashboard - stock value, low stock, trend and top movers</sub></td>
<td align="center"><sub>Items - SKUs, types and on-hand totals</sub></td>
<td align="center"><sub>Stock movement - drafted, then finalized to the ledger</sub></td>
</tr>
<tr>
<td><img src="https://ps.w.org/plugixa-inventory/assets/screenshot-4.png" alt="" width="330" /></td>
<td><img src="https://ps.w.org/plugixa-inventory/assets/screenshot-5.png" alt="" width="330" /></td>
<td><img src="https://ps.w.org/plugixa-inventory/assets/screenshot-6.png" alt="" width="330" /></td>
</tr>
<tr>
<td align="center"><sub>Stock ledger - every change, which nothing can edit</sub></td>
<td align="center"><sub>Warehouse overview - stock and activity per location</sub></td>
<td align="center"><sub>Stock health - proves the numbers follow the ledger</sub></td>
</tr>
</table>

## What it is

📦 A working stock system for WordPress, free and not feature-locked. Records live in the plugin's own database tables, not in posts and post meta, so lists, filters and the dashboard stay fast as your history grows:

- **Items** - assets, consumables and sellables, with SKUs, barcodes, serial numbers and reorder levels
- **Warehouses** - stock held per item and per warehouse, each with its own low-stock list and valuation
- **Stock movements** - add, remove, check in and check out; documents are drafted, then finalized, and a finalized document cannot be edited or deleted
- **Stock ledger** - append-only; on-hand quantities are derived from it, and no REST route, screen or setting can change it
- **Stock health** - a reconcile that proves the quantities still follow from the ledger, on demand and daily in the background
- **Dashboard** - stock value at cost, movement trend, low stock and top movers, plus a per-warehouse overview
- **Import and export** - CSV import with column matching, opening stock and one-click revert; CSV export from every list, filters included
- **For developers and teams** - a `[plugixa_inventory_app]` shortcode for the front end, a REST API (`plugixa-inventory/v1`), hooks, WP-CLI commands, six capabilities and an Inventory Manager role

The **WooCommerce integration** - one warehouse connected to your shop, every order, cancellation and refund posted to the ledger - is part of Plugixa Inventory Pro. A full tour is on the [documentation](https://www.plugixa.com/documentation/plugixa-inventory?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=docs) - and on the [WordPress.org plugin page](https://wordpress.org/plugins/plugixa-inventory/).

## Who is it for?

Shops and online sellers who want a real stock record behind their catalogue. Warehouses and storage rooms tracking quantities across several locations. Teams that look after assets, consumables or equipment. Staff members who use the inventory on the front end of the site without ever opening wp-admin.

## Get it

The free plugin itself is hosted on WordPress.org - install it from your dashboard (**Plugins → Add New → search "Plugixa Inventory"**) or fetch it directly:

```
Latest release:  https://downloads.wordpress.org/plugin/plugixa-inventory.latest-stable.zip
Install by ZIP:  Plugins → Add New → Upload Plugin → choose the ZIP → Activate
Requires:        WordPress 6.5+, PHP 8.2+
```

## About this repository

This repository is a **mirror of the WordPress.org distribution of the free version**, updated automatically every hour from SVN:

- the `main` branch tracks the published `trunk/`,
- Git tags (`1.0.0` → current) match the SVN `tags/<version>` directories, byte for byte.

Report bugs on the [support forum](https://wordpress.org/support/plugin/plugixa-inventory/); release notes live on our [changelog](https://www.plugixa.com/changelog?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=changelog). The premium version of Plugixa Inventory is distributed separately on [plugixa.com](https://www.plugixa.com/plugins/plugixa-inventory?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=repo-about#pricing) and is **not** contained here.

## License

GPL-2.0-or-later - see [LICENSE](LICENSE). Plugixa Inventory is developed by [Plugixa](https://www.plugixa.com/?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=license).
