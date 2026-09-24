=== OC Statistics for WooCommerce ===
Contributors: originalconcepts
Tags: woocommerce, analytics, statistics, reports, dashboard
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.3.269
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sales, visits and what to do about them, inside the WordPress dashboard. No third party, no account to open.

== Description ==

Most shop owners never open an analytics account, and the ones who do rarely
go back. This plugin puts the answer where they already are: a Statistics
screen in the WordPress menu, in plain language.

* Sales, orders, visits, conversion, average order and returning customers, against the period before.
* Where visitors came from — search, ads, social, email, referrals, direct — and on which device, switchable between orders and visits.
* Best-selling products and brands, with views, adds to cart, orders and revenue.
* Where buyers drop off: visits, a product viewed, a cart, the checkout, the order.
* What did not become a sale: cancelled, failed, refunded, pending and on hold.
* Plain-language insights that name the thing to fix, and say how each one was worked out.
* A daily or weekly email to whoever should get it.

Visits are counted by the site itself: a short-lived session cookie, no
personal data, no third-party script, no account. Robots and staff are not
counted, and a consent plugin can keep a visitor out with one filter.

= Works with any theme =

Nothing here depends on a particular theme. It reads WooCommerce's own
order tables, so it is at home with High-Performance Order Storage.

== Installation ==

1. Upload the plugin and activate it.
2. Open **Statistics** in the WordPress menu.

Counting starts the moment the plugin is active. Orders are read from
WooCommerce and reach back as far as the shop's history; visits are counted
from the day you switched it on.

== Frequently Asked Questions ==

= Does it slow the shop down? =

The counter is one small file and a single request sent after the page has
finished loading. Everything else happens in the dashboard or on a schedule.

= Does it send anything anywhere? =

No. Every number stays in the site's own database.

= I use the OC theme =

Then you already have this, built in, and the plugin will tell you so and
switch itself off.

== Changelog ==

= 1.0 =
* First release.
