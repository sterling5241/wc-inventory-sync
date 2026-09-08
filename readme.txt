=== WC Inventory Sync ===
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.6
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
