# Changelog

## 0.7.0

- **Live stream**: a collector for the streaming server (`server/stream`, Python, systemd timer) counts who watches the HLS stream and on what — website, live player page, iPhone and Android apps, Samsung/LG/Fire/Roku/Apple/Android TVs, IPTV players, other sites playing the stream, re-broadcasting tools — and pushes counts only (no IPs) to `/wp-json/marsad/v1/push/stream`. Viewers a day, hours watched, peak viewers at once, and "watching now".
- **Roles**: Settings chooses, per role, which dashboard sections it sees and which platforms it may connect (the Google account, own sources, or single sources). The Connections page then shows that role only those cards; every handler checks the same rule.
- **X (Twitter)** (paid API plan required), **Threads** (token renewed weekly, pasted once) and **TikTok** (refresh token rotated automatically).
- Social audience in the headline now includes X, Threads and TikTok.
- The All time subtitle no longer shows one start date that contradicts sources keeping longer history.
- `docs/google-oauth-verification.md`: everything Google's app verification asks for.
- Translations for all 15 languages.

## 0.6.1

- The total in the middle of the channels donut is drawn left-to-right, so Arabic, Persian and Urdu screens no longer cut it at the ring.
- Search Console tables say "5,000+" when Google stopped at the requested limit, instead of presenting the limit as the true count.

## 0.6.0

- **Range snapshots**: for 7, 28, 90 and 365 days and all time, Marsad stores Google's own report of the period (deduplicated users, impression-weighted CTR and position, full tables, previous-period comparison), refreshed in the background with a shared time budget. Visitors now match Google Analytics (e.g. 5,132 active users for 28 days, where the daily average 209 was shown before).
- **Google Analytics 4**: 29 reports: totals, pages, landing pages, channels, source/medium, campaigns, social, referrals, countries, cities, languages, devices, OS, browsers, models, resolutions, new vs returning, age, gender, an hour-by-day heatmap, events, key events, site search terms, outbound links, and the Site Kit dimensions (authors, publish dates, scroll depth, video). Live visitors refresh every minute.
- **Search Console**: web, image, video, news, Discover and Google News totals; up to 1,000 queries and 500 pages with previous values; countries, devices, search appearance, query→page; opportunities with estimated extra clicks; rising and falling queries; sitemaps.
- **Overview**: headline numbers from Google's reports, key findings in plain sentences, one card per platform, channels donut, devices, top countries, new vs returning and search-type splits.
- **Tables**: sortable, filterable, "show all", change column, CSV export that Excel opens correctly (formulas neutralised).
- Hardening from an independent review: honest comparisons when the previous period is not kept, ranges that crash the host are retried last and reported, lower memory, refresh on every hourly run, old property data dropped when the property changes, failed saves reported.
- Translations for all 15 languages.

## 0.5.2

- Google Play: a 403 from the reports bucket now names the account and the exact Play Console permission to grant ("View app information and download bulk reports"), in every language, instead of the raw storage error.

## 0.5.1

- "Connect with Google" appears only when Marsad Connect can actually sign in (checked through its new `status.php`); otherwise the card explains the way that works today instead of sending people to an error page.
- Translation sources live in `languages/source/` so rebuilds keep every string.

## 0.5.0

- **15 languages**: the plugin follows each admin's own WordPress language: Arabic, English, French, Spanish, German, Italian, Portuguese, Russian, Turkish, Persian, Urdu, Hindi, Indonesian, Chinese and Japanese. Regional variants fall back to their language. `bin/l10n-catalog.php` and `bin/l10n-build.php` keep them complete; a test fails on any missing string or broken placeholder.
- **YouTube without access**: paste any public channel's link or @handle. Numbers come through Marsad Connect's shared key, so no key is needed (your own key and Connect with Google remain).
- **Instagram without access**: follow any public business or creator account by username (Business Discovery, read through your own connected account).
- Every setup step has a button that opens the exact page it talks about.
- **Full history**: the WordPress source imports every day since the first post and keeps it regardless of the retention setting. Posts later moved to the trash still count on the day they were published.
- **Deleted posts**: posts moved to the trash per day, posts deleted permanently (counted from install), and posts by status (published, drafts, pending, scheduled, private, trash).
- **All time** range, with monthly columns for long periods.

## 0.4.1

- Each source now says exactly what it still needs ("Missing: Bot token", "Missing: Connect with Google …") on its card, on the dashboard and right after saving, instead of a plain "Not connected".

## 0.4.0

- **Connect with Google**: one sign-in for Analytics, Search Console, YouTube and Google Play, through the Marsad Connect relay. Properties, sites and channels are picked from lists instead of typed.
- Facebook: paste one token, pick the Page; Marsad keeps the Page's own token, which does not expire. Instagram is found through the Page.
- Service-account keys and the YouTube API key moved under "Advanced" on each card.
- `server/connect`: the relay itself, with tests and a privacy policy.

## 0.3.1

- Hosting tab shows the latest home page response time right away, instead of a dash until the first full day is recorded.

## 0.3.0

- New dashboard in four tabs: Overview, Content & users, Platforms & apps, Hosting.
- New always-on "Site & hosting" source, recorded daily: totals of posts, pages, images, videos, comments by status, users by role, categories and tags; database and uploads size; home page response time.
- Hosting tab: software versions, plugins, upload limit, HTTPS, debug mode, late scheduled tasks and failing sources.
- Daily publishing bars with an average line, ranked lists, stacked bars for users and comments, and custom sources shown as funnels.

## 0.2.0

- Your own sources are added with a **+ Add a source** button and can be removed again. A new install starts with none, and you can add as many as you need. A site that already set up the old single JSON source keeps it.

## 0.1.1

- Running totals (followers, subscribers, campaign totals) recorded today now show on the dashboard. They were hidden until the next day because the period ends yesterday.

## 0.1.0

First release.

- Dashboard with headline cards, per-source KPIs, SVG charts and top-N tables, compared against the previous period (7, 28, 90 or 365 days).
- Sources: WordPress, Google Analytics 4, Search Console, YouTube, Facebook Page, Instagram, Telegram, Google Play, App Store, and a generic JSON endpoint.
- Hourly background sync with history import on first connection and a refresh window afterwards.
- Secrets encrypted at rest; connection test button; readable errors for disabled Google APIs.
- Roles that can view the dashboard are configurable.
- Updates from GitHub releases.
- Arabic translation.
