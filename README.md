<div align="center">

<img src="https://ps.w.org/plugixa-inventory/assets/icon-256x256.png" width="128" alt="Plugixa Inventory icon">

# Plugixa Inventory

**Inventory and stock management for WordPress: items, warehouses, stock movements and an append-only stock ledger, CSV import and export, and a WooCommerce integration in Pro, in a fast React admin.**

[![Version](https://img.shields.io/wordpress/plugin/v/plugixa-inventory.svg)](https://wordpress.org/plugins/plugixa-inventory/)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/plugixa-inventory.svg)](https://wordpress.org/plugins/plugixa-inventory/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/plugixa-inventory.svg)](https://wordpress.org/plugins/plugixa-inventory/)
[![Requires PHP](https://img.shields.io/wordpress/plugin/required-php/plugixa-inventory.svg)](https://wordpress.org/plugins/plugixa-inventory/)
[![License: GPLv2 or later](https://img.shields.io/badge/license-GPLv2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

<img src="https://ps.w.org/plugixa-inventory/assets/banner-1544x500.png" alt="Plugixa Inventory banner">

</div>

## Links

| | |
|---|---|
| 📚 **Documentation** | https://www.plugixa.com/documentation/plugixa-inventory?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=docs |
| ⭐ **Plugixa Inventory Pro and pricing** | https://www.plugixa.com/plugins/plugixa-inventory?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=pricing#pricing |
| 📰 **News** | https://www.plugixa.com/news?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=news |
| 📋 **Changelog** | https://www.plugixa.com/changelog?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=changelog |
| ✉️ **Contact** | https://www.plugixa.com/contact?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=contact |
| 🛟 **Support forum** | https://wordpress.org/support/plugin/plugixa-inventory/ |

## Screenshots

| | | |
|---|---|---|
| ![Dashboard](https://ps.w.org/plugixa-inventory/assets/screenshot-1.png)<br>*Dashboard: stock value, low stock, a 30-day movement trend and the top movers* | ![Items](https://ps.w.org/plugixa-inventory/assets/screenshot-2.png)<br>*Items: the catalogue, with SKUs, types and on-hand totals per item* | ![Stock movement](https://ps.w.org/plugixa-inventory/assets/screenshot-3.png)<br>*A stock movement is drafted first, then finalized to write the ledger* |
| ![Stock ledger](https://ps.w.org/plugixa-inventory/assets/screenshot-4.png)<br>*Stock ledger: every change to every quantity, which nothing can edit or delete* | ![Warehouse overview](https://ps.w.org/plugixa-inventory/assets/screenshot-5.png)<br>*Warehouse overview: stock, low stock and recent activity for one location* | ![Stock health](https://ps.w.org/plugixa-inventory/assets/screenshot-6.png)<br>*Stock health: the reconcile that proves the numbers still follow from the ledger* |

## What it is

Plugixa Inventory turns WordPress into a working stock system. Records live in the plugin's own database tables, not in posts and post meta, so lists, filters and the dashboard stay fast as your history grows.

- Items: assets, consumables and sellables, with SKUs, barcodes, serial numbers and reorder levels
- Warehouses, each with its own stock levels, low-stock list and valuation - stock is held per item and per warehouse
- Stock movements: add stock, remove stock, check in and check out - documents are drafted, then finalized, and a finalized document cannot be edited or deleted
- An append-only stock ledger that on-hand quantities are derived from, which no REST route, admin screen or setting can edit or delete
- A stock health check that proves the quantities still follow from the ledger, on demand and daily in the background
- A dashboard with stock value, movement trend, low stock and top movers, plus a per-warehouse overview - stock is valued at cost, with average costing
- CSV import with column matching, opening stock and one-click revert, and CSV export from every list, filters included
- A `[plugixa_inventory_app]` shortcode that runs the whole application on a front-end page, a REST API (`plugixa-inventory/v1`), action and filter hooks, WP-CLI commands, six inventory capabilities and a ready-made Inventory Manager role

**About WooCommerce:** the WooCommerce integration connects one warehouse to your shop and posts every order, cancellation and refund to the stock ledger. It is part of Plugixa Inventory Pro. The free plugin is complete on its own and does not change WooCommerce.

## Who is it for?

Anyone who keeps stock and works in WordPress: shops and online sellers, warehouses and storage rooms, teams that track assets, consumables or equipment, and staff members who use the inventory on the front end without ever opening wp-admin.

## Get it

- **From WordPress.org:** in your admin, go to Plugins, Add New, search for "Plugixa Inventory", install and activate. Plugin page: https://wordpress.org/plugins/plugixa-inventory/
- **Direct download of the latest stable release:** https://downloads.wordpress.org/plugin/plugixa-inventory.latest-stable.zip
- **Requirements:** WordPress 6.5 or greater, PHP 8.2 or greater.
- Already selling with WooCommerce, or you want transfers, FIFO and LIFO costing, reports and labels? See [Plugixa Inventory Pro and pricing](https://www.plugixa.com/plugins/plugixa-inventory?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=pricing#pricing).

## About this repository

- This repository mirrors the WordPress.org distribution of Plugixa Inventory.
- The `main` branch tracks the plugin's SVN trunk, and each version tag (for example `1.1.1`) mirrors the matching SVN tag of the same number, byte for byte.
- The README you are reading exists only on `main`; it is not part of the plugin itself.
- Development and releases happen on WordPress.org. For questions and support, use the [support forum](https://wordpress.org/support/plugin/plugixa-inventory/); for anything else, [contact us](https://www.plugixa.com/contact?utm_source=github.com&utm_medium=repo_readme&utm_campaign=plugixa-inventory&utm_content=contact).

## License

Released under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html), as published on WordPress.org. The upstream license file ships with the plugin in this repository.

