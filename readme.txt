=== Marsad ===
Contributors: alahdal262
Tags: analytics, statistics, dashboard, google analytics, social media
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.5.2
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
* Your own sources: add any JSON address with one button, as many as you need

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

= 0.5.2 =
* Google Play explains exactly which Play Console permission to grant when reports are not readable yet.

= 0.5.1 =
* The Connect with Google button only appears when sign-in is available; otherwise the working alternative is shown.

= 0.5.0 =
* In 15 languages, following each admin's WordPress language.
* Follow any public YouTube channel by pasting its link; any public Instagram business account by username.
* Every setup step links to the page that has what it asks for.
* Full publishing history since the first post, deleted posts, posts by status, and an All time view.

= 0.4.1 =
* Each source says exactly what it still needs instead of "Not connected".

= 0.4.0 =
* Connect with Google in one click; pick properties and channels from lists. Simpler Facebook and Instagram setup.

= 0.3.1 =
* Hosting tab shows the latest response time right away.

= 0.3.0 =
* New dashboard in four tabs, and a Site & hosting source with content totals, storage and response time.

= 0.2.0 =
* Your own sources are added with an "Add a source" button; none exist on a new install.

= 0.1.1 =
* Running totals (followers, campaign totals) recorded today now show on the dashboard instead of a day late.

= 0.1.0 =
* First release.
