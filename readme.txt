=== Marsad ===
Contributors: alahdal262
Tags: analytics, statistics, dashboard, google analytics, social media
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One dashboard for your site's numbers: Google Analytics, Search Console, YouTube, Facebook, Instagram, Telegram, Google Play, App Store and more.

== Description ==

Marsad collects statistics from the platforms your site uses, stores them daily in your own database, and shows them together on one page in wp-admin.

Sources:

* WordPress: posts and comments, by author and category
* Google Analytics 4: users, sessions, views, engagement; top pages, channels, sources, countries, devices
* Google Search Console: clicks, impressions, CTR, position; top queries and pages
* YouTube: subscribers, views, videos
* Facebook Page and Instagram: followers
* Telegram: channel members
* Google Play: installs, uninstalls, active devices
* App Store: downloads, re-downloads, updates, by country
* Any JSON endpoint: map values by path

Each site connects its own accounts. Credentials are encrypted at rest and data never passes through a third-party server.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Marsad → Connections and connect your sources.
3. Press Test on each connection, then open Marsad → Dashboard.

== Frequently Asked Questions ==

= Who can see the dashboard? =

Administrators by default. Choose more roles under Marsad → Settings.

= How often is data refreshed? =

Hourly by default, through WP-Cron. Use "Sync now" on the dashboard to refresh immediately.

== Changelog ==

= 0.1.1 =
* Running totals (followers, campaign totals) recorded today now show on the dashboard instead of a day late.

= 0.1.0 =
* First release.
