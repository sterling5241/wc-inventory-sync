=== WC Inventory Sync ===
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Keep stock quantities in sync between one master WooCommerce store and any number of subscriber stores, matched by SKU.

== Description ==

Install this plugin on your master store (the one whose inventory is correct) and set its role to **Master**. Install it on every other store and set their role to **Subscriber**, then point each one at the master.

* When a subscriber sells something (or an order is cancelled/refunded and stock is restored), it reports the change to the master in real time. The master updates its own stock and immediately re-broadcasts the new quantity to every other subscriber.
* When stock changes on the master — manual edit, order sale, CSV import, or via the REST API — the new quantity is pushed to every subscriber in real time.
* Products are matched across stores by SKU — use the same SKU for the same product everywhere (variations need their own SKU too).
* A periodic full-catalog reconciliation (default every 15 minutes, configurable, can be disabled) self-heals anything a real-time push missed, e.g. because a store was briefly offline.
* Failed sync requests are queued and retried automatically with backoff (up to 10 attempts over roughly an hour), so a temporary outage never silently loses a sale.
* All sync traffic is authenticated with a per-store HMAC key/secret pair and a 5-minute replay window — no shared WordPress logins between sites.

== Installation ==

See SETUP.md alongside this plugin for a full walkthrough. In short:

1. Install this plugin on your master store and on every subscriber store (zip the `wc-inventory-sync` folder and upload it under Plugins → Add New → Upload Plugin, on each site).
2. On the master: WooCommerce → Inventory Sync → Setup → set Role = Master, save. Go to "Subscriber Stores" and add each subscriber by name + URL — this generates an API Key and Secret for it.
3. On each subscriber: WooCommerce → Inventory Sync → Setup → set Role = Subscriber, save. Go to "Master Connection" and enter the master's site URL plus the API Key/Secret shown on the master for that store. Click Test Connection.
4. Make sure every product uses the same SKU across all stores.
5. From the master's "Subscriber Stores" tab, click "Full Sync Now" to push the current catalog out and get everyone in sync for the first time.

== Frequently Asked Questions ==

= What if I edit stock quantity directly on a subscriber store? =
Don't — the master is the single source of truth. A manual edit on a subscriber will be overwritten by the next real-time push or reconciliation from the master. Make inventory corrections on the master.

= Does this work with product variations? =
Yes, as long as the variation has its own SKU.

= What happens if a store is offline when a sync request is sent? =
The request is queued and retried automatically (every 5 minutes, with backoff, up to 10 attempts) until it succeeds. Check WooCommerce → Inventory Sync → Retry Queue.

= Does a subscriber need "Manage stock" enabled on its products? =
The master does, for its own broadcast logic. On a subscriber, an incoming update from the master will turn stock management on for that product automatically if it wasn't already, so the pushed quantity actually takes effect.

== Changelog ==

= 1.1.0 =
* Added: export the Sync Log to a Google Sheet. New "Google Sheets" tab — connect a Google Service Account (JSON key, no OAuth login screen), pick a spreadsheet + tab, and every sync event gets batched out every 5 minutes (plus a manual "Export Now" and a "Test Connection"). Batched rather than one API call per event, so a big reconciliation burst doesn't hammer Google's rate limits.
* New WCIS_Google_Sheets class handles the service-account JWT auth and the Sheets API append call directly (no external library).

= 1.0.14 =
* Fixed: a Manual Match only set the SKU — the matched product sat at whatever quantity it already had until the next reconciliation cycle. Both directions now also push the correct starting quantity at match time: master→subscriber pushes the master's current stock right after the SKU lands; subscriber→master applies the quantity captured from the master's item when the match was made.
* WCIS_Diagnostics payloads now include quantity/stock_status per item (previously only sku/manages), used to carry this through.
* WCIS_Master::send_update_stock() is now public so admin actions can push a one-off quantity outside the normal broadcast path.

= 1.0.13 =
* Product lists are now sorted alphabetically by name (natural, case-insensitive sort) — the Not Synced table, both Manual Match dropdowns on both roles, and the remote lists fetched from another store (they're sorted before being sent back, so this applies everywhere without extra work).

= 1.0.12 =
* Fixed: Manual Match (master side) now lists the master's ENTIRE catalog as possible match sources, not just not-synced or recently-failed items. A product you already matched to one subscriber was disappearing from the picker when trying to match it to a different subscriber, since it was no longer "broken" by either of the previous two narrower definitions.
* New WCIS_Diagnostics::get_all_products() backs this (same product-type eligibility rule used everywhere else: simple products and variations always count, a variable parent only counts when it manages its own shared stock).

= 1.0.11 =
* Fixed: Manual Match (master side) now also includes master products that recently failed to sync to the selected subscriber, not just products that are unsynced on the master itself. Most failing products are already perfectly fine on the master (SKU set, stock management on, syncing everywhere else) — they were never showing up as matchable before because they had nothing wrong with them locally.

= 1.0.10 =
* Fixed: a real-time push to a subscriber that doesn't carry a given product (404 "no product with this SKU") no longer gets queued for retry — that's not a transient failure, retrying can't ever succeed until the subscriber's catalog changes. Other failures (timeouts, connection errors, etc.) still retry as before.

= 1.0.9 =
* Added a "Settings" link to the plugin's row on the Plugins page (next to Deactivate), jumping straight to WooCommerce → Inventory Sync.

= 1.0.8 =
* Added Author / Author URI to the plugin header so "By dexabyte.ca" shows on the Plugins page.

= 1.0.7 =
* Added a "Manual Match" tab, on both master and subscriber: pair up a not-synced item on this site with the matching item on the other side and copy the master's SKU onto it, for cases where the same product ended up with different (or no) SKUs on each store and never matched automatically.
* Master can only ever write to a subscriber (never the reverse), same trust direction as the rest of the plugin — a subscriber's Manual Match tab can only copy a SKU the master already has, never assign one on the master remotely.
* New /apply-sku REST route (subscriber-side) backing the master → subscriber half of a match.

= 1.0.6 =
* Added a new /not-synced REST route: any site can report its own "Not Synced" list to an authenticated caller.
* Subscriber Issues tab now also fetches and shows that subscriber's own Not Synced list live, with a link to jump straight to fixing it on that store.

= 1.0.5 =
* Added (master only): a "Subscriber Issues" tab — pick a subscriber store and see everything currently wrong involving just that store (failed pushes, sales it reported that couldn't be applied, anything still stuck in the retry queue).
* Added to the "Not Synced" tab: a "Generate SKU" button for products missing one (sets AUTOGEN-<product id> on this site — the matching product on your other store(s) still needs the same value to actually sync), and an "Enable + Save Qty" action to turn on stock management and set a starting quantity in one step.

= 1.0.4 =
* Added auto-updates: the plugin now checks https://github.com/sterling5241/wc-inventory-sync for new releases and shows an "update available" notice on the Plugins page, same as a WordPress.org plugin. Uses the bundled Plugin Update Checker library (vendor/plugin-update-checker/), MIT licensed.
* Note: sites already on 1.0.3 or earlier need one last manual reinstall to get this update-checker code in place. Every release after that is a normal in-dashboard update.

= 1.0.3 =
* Fixed: 1.0.2 wrongly excluded ALL variable products from the "Not Synced" tab. Corrected — a variable product's parent can legitimately hold its own shared stock quantity (WooCommerce applies it to any variation that doesn't manage stock individually), so it's now only flagged when neither the parent nor any of its variations manages stock at all.

= 1.0.2 =
* Fixed: the "Not Synced" tab no longer flags variable/grouped/external parent products (they never carry their own stock by design — only their variations do, and those are still checked individually).

= 1.0.1 =
* Added a "Not Synced" tab listing published products missing a SKU and/or stock management, so skipped products are easy to find and fix.
* Sync Log now shows the product name alongside the SKU for each event.

= 1.0.0 =
* Initial release.
