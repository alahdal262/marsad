# Deep analytics: range snapshots, full GA4 and Search Console, infographic overview

Status: approved direction (owner asked for "the most data possible, for decisions"). Builders: follow this
exactly; where it is silent, choose what a careful senior engineer would and note it in your report.

## Why

Measured on yementv.tv for the same 28 days:

| | Google | Marsad stores | Marsad showed |
|---|---|---|---|
| Search clicks | 4,090 | 4,090 | 4,090 |
| Distinct search queries | 5,206 | 644 (top 50 per day) | a few |
| Image/video/news search | 35,423 image impressions etc. | not fetched | — |
| GA4 users | 5,138 active users | daily values | "209" (average of days) |
| GA4 available | 390 dimensions, 98 metrics, 15 custom dims, realtime | 6 metrics | — |

Daily rows are right for charts but wrong for *range totals* of non-additive numbers (users, CTR, position)
and too coarse for long tables. Google's UIs compute each range server-side; Marsad must do the same.

## Core idea: range snapshots

For each standard range Marsad asks Google for the range's own report (deduplicated users, impression-weighted
CTR/position, full top-N tables, previous-period comparison) and stores the answer as JSON. The dashboard reads
snapshots for numbers and tables, and the existing daily `marsad_metrics` rows only for charts.

### Ranges

Keys: `'7'`, `'28'`, `'90'`, `'365'`, `'all'`. Each ends yesterday (UTC), exactly like `Marsad_Aggregate::range()`;
previous period = same length immediately before (none for `'all'`). For `'all'`, start = the source's
`history_start()` (GA4: `'2015-08-14'` is accepted by the API; Search Console keeps 16 months, so use today − 486 days).
Search Console data lags 2–3 days: request with `dataState: 'all'` (fresh data, as the UI does).

### Storage — `{prefix}marsad_snapshots`

```
id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
source varchar(40) NOT NULL,
range_key varchar(10) NOT NULL,
report varchar(80) NOT NULL,
payload longtext NOT NULL,          -- JSON
fetched_at datetime NOT NULL,
UNIQUE KEY srr (source, range_key, report)
```

`Marsad_Store::DB_VERSION` becomes 2; `Marsad_Store::install()` creates both tables via dbDelta;
`maybe_upgrade()` already runs install when the version differs. `uninstall.php` drops it too.

### `Marsad_Snapshots` (new, `includes/class-marsad-snapshots.php`)

```php
const RANGES = array( '7', '28', '90', '365', 'all' );
public static function table(): string;
public static function put( $source, $range_key, $report, array $payload ): void;     // upsert, JSON_UNESCAPED_UNICODE
public static function get( $source, $range_key, $report ): ?array;                  // array( 'payload' => array, 'fetched_at' => int unix ) or null
public static function reports( $source, $range_key ): array;                       // report => payload, every stored report
public static function range( $range_key, $today = null ): array;                    // from, to, prev_from|null, prev_to|null (uses Aggregate::range; 'all' uses history start passed in)
public static function stale( $source, $range_key, $ttl ): bool;                     // true when the 'totals' report is missing or older than $ttl
public static function refresh( Marsad_Connector $c, $deadline ): int;               // refresh stale ranges, most urgent first, stop at time() >= $deadline; returns reports written
public static function delete_source( $source ): void;
```

Refresh order and freshness (TTL): `'7'` 1 h, `'28'` 3 h, `'90'` 12 h, `'365'` 24 h, `'all'` 24 h.
A connector failing for one range must not stop other connectors; store the error per range in option
`marsad_snapshot_errors` (`source => range => array(at, message)`) and clear it on success.

### Connector contract additions (`Marsad_Connector`, defaults keep every other connector unchanged)

```php
public function supports_snapshots(): bool { return false; }
/** @return array report_key => payload  @throws Marsad_Exception */
public function snapshots( $range_key, array $range ): array { return array(); }
/** Translated labels for metric/dimension keys used in this connector's payloads. */
public function snapshot_labels(): array { return array(); }
```

### Sync integration

`Marsad_Sync::run_all()` (cron and "Sync now"): after the daily runs, call `Marsad_Snapshots::refresh()` for
every enabled connector that `supports_snapshots()`, with one shared deadline of 45 s from the start of the
snapshot phase (shared hosting); what is not refreshed is picked up by the next cron. "Sync now" may pass 90 s.
`handle_forget_connector` with delete_data also calls `Marsad_Snapshots::delete_source()`.

## Payload format (all snapshot reports)

Two shapes only, so the dashboard has one renderer:

**Totals** (report key `totals`, and `totals_<type>` for Search Console types):
```json
{ "metrics": { "activeUsers": { "value": 5138, "prev": 4801 }, "engagementRate": { "value": 0.53, "prev": 0.5 } } }
```
`prev` is null when there is no previous period. Ratios are stored as Google returns them (0–1); percent
formatting happens at render time.

**Table** (every other report):
```json
{
  "dims":    ["pagePath", "pageTitle"],
  "metrics": ["screenPageViews", "activeUsers", "userEngagementDuration"],
  "rows":    [ { "d": ["/news/a", "Title"], "m": [900, 700, 52000], "p": [810, null, null] } ],
  "sort":    "screenPageViews",
  "total_rows": 5206
}
```
`p` (previous-period values aligned with `m`, or null entries) is optional. `total_rows` is Google's
row count before the limit (GA4 `rowCount`; Search Console: number of rows returned when under the limit),
so the UI can say "top 1,000 of 5,206".

Metric formats are declared once in a static map (`Marsad_Snapshots::FORMATS`, extendable by connectors via
`snapshot_labels()` companion `snapshot_formats()` returning key => 'number'|'percent'|'decimal'|'seconds'|'duration'|'text'|'link'|'country'),
e.g. `engagementRate` => percent, `averageSessionDuration` => seconds, `position` => decimal, `ctr` => percent.

## GA4 (`Marsad_Connector_Ga4`)

`supports_snapshots()` true. One `snapshots()` call per range issues **batchRunReports** (max 5 requests per
batch, so several batches) with `dateRanges` = current and, when present, previous (GA4 returns
`dateRange` dimension `date_range_0` / `date_range_1` — map them into `m` / `p`). Use `keepEmptyRows: false`,
`limit` as listed, `orderBys` by the sort metric desc. Exclude `(not set)` dimension values from tables.
Pure static parser(s) (`Marsad_Connector_Ga4::parse_snapshot( array $requests, array $responses )`) do the
mapping so tests can feed recorded API answers.

Reports (key: dimensions → metrics, limit):

| key | dimensions | metrics | limit |
|---|---|---|---|
| totals | — | activeUsers, totalUsers, newUsers, sessions, engagedSessions, engagementRate, bounceRate, screenPageViews, screenPageViewsPerSession, averageSessionDuration, userEngagementDuration, eventCount, keyEvents, sessionsPerUser | — |
| pages | pagePath, pageTitle | screenPageViews, activeUsers, userEngagementDuration, engagementRate | 500 |
| landing | landingPage | sessions, newUsers, engagementRate, keyEvents | 200 |
| channels | sessionDefaultChannelGroup | sessions, activeUsers, engagementRate, keyEvents | 30 |
| source_medium | sessionSourceMedium | sessions, activeUsers, engagementRate | 200 |
| campaigns | sessionCampaignName | sessions, activeUsers, keyEvents | 100 |
| social | sessionSource (dimensionFilter sessionDefaultChannelGroup = "Organic Social") | sessions, activeUsers | 50 |
| referrals | sessionSource (filter channel = "Referral") | sessions, activeUsers | 100 |
| countries | country | activeUsers, sessions, screenPageViews | 250 |
| cities | city, country | activeUsers, sessions | 250 |
| languages | language | activeUsers | 60 |
| devices | deviceCategory | activeUsers, sessions, engagementRate | 10 |
| os | operatingSystem | activeUsers | 30 |
| browsers | browser | activeUsers | 30 |
| models | mobileDeviceModel | activeUsers | 50 |
| resolutions | screenResolution | activeUsers | 30 |
| new_returning | newVsReturning | activeUsers, sessions, engagementRate | 5 |
| age | userAgeBracket | activeUsers | 10 |
| gender | userGender | activeUsers | 5 |
| heatmap | dayOfWeek, hour | activeUsers | 200 (sorted by dims) |
| events | eventName | eventCount, totalUsers | 200 |
| key_events | eventName | keyEvents | 50 (rows with keyEvents > 0) |
| search_terms | searchTerm | eventCount, totalUsers | 200 |
| outbound | linkUrl (filter eventName = "click") | eventCount | 100 |
| authors | customEvent:googlesitekit_post_author | screenPageViews, activeUsers, userEngagementDuration | 100 |
| categories | customEvent:category | screenPageViews, activeUsers | 100 |
| post_dates | customEvent:googlesitekit_post_date | screenPageViews | 400 (content age) |
| scroll | customEvent:percentage (filter eventName = "scroll") | eventCount | 20 |
| video | — | `customEvent:video_percent`-family metrics if present | — |

Custom-dimension reports run only when the property's metadata (GET `…/metadata`, cache 24 h in a transient
keyed by property) contains that `apiName`; otherwise the report is skipped silently. Age/gender may be all
`(not set)` when Google signals is off — then store an empty table (UI shows "not available for this property").
A report that Google rejects as incompatible must not fail the whole range: run it alone, drop it, and record
the message in the range's error entry.

**Realtime** (not stored): `Marsad_Connector_Ga4::realtime()` → `runRealtimeReport` for activeUsers total,
by `unifiedScreenName` (10), `country` (10), `deviceCategory`; cached 60 s in a transient; returns
`array( 'active' => int, 'pages' => …, 'countries' => …, 'devices' => … )` or null on error.
`wp_ajax_marsad_realtime` (cap marsad_view, nonce) returns it as JSON for the dashboard to poll every 60 s.

Daily series stay as today, plus: none required.

## Search Console (`Marsad_Connector_Search_Console`)

`supports_snapshots()` true. Per range (all with `dataState: 'all'`, `aggregationType: 'auto'`):

| key | request | rows |
|---|---|---|
| totals_web, totals_image, totals_video, totals_news, totals_discover, totals_googleNews | no dimensions, `type` = …, current + previous | totals shape (clicks, impressions, ctr, position) |
| totals | = totals_web (so the generic renderer finds `totals`) | |
| queries | dims query, type web, rowLimit 1000, + previous period for the same queries (second request, map by query) | metrics clicks, impressions, ctr, position; `p` = prev |
| pages | dims page, rowLimit 500, + previous | same |
| countries | dims country (ISO-3 lower-case; store as is, format 'country') | same, 250 |
| devices | dims device | same |
| appearance | dims searchAppearance | same |
| query_pages | dims query, page, rowLimit 1000 | clicks, impressions, ctr, position |
| opportunities | computed from `queries`: position between 4 and 20, impressions ≥ max(50, 1% of top impressions), CTR below the median CTR of rows at the same rounded position; sorted by impressions; 100 rows; extra metric `potential_clicks` = impressions × (median CTR of positions 1–3 in this data) − clicks, floored at 0 | |
| rising / falling | computed from `queries` with `p`: clicks delta, top 50 each (rising: delta > 0 desc; falling: delta < 0 asc); include new queries (prev 0) in rising | |
| sitemaps | GET `sites/{site}/sitemaps` (range-independent; store under every range key, cheap) | dims path; metrics submitted, indexed (from `contents`), errors, warnings; plus `lastDownloaded` as text |

Pure static functions: `parse_rows()`, `opportunities( array $queries_table )`, `movers( array $queries_table )`.
Daily fetch: switch to `dataState: 'all'` and keep today's behaviour otherwise (top-N per day stays for
daily charts; the full tables come from snapshots).

## Dashboard

Existing tabs stay; add **Audience** (`audience`) and **Search** (`search`). The range menu maps 7/28/90/365/0
to snapshot keys `'7'/'28'/'90'/'365'/'all'`.

**Overview — an infographic of every platform and section (owner's explicit request).**
1. Headline row: Visitors (= GA4 snapshot `totals.activeUsers` for the range, fallback daily average with the
   label "daily average"), Page views, Search clicks, Social audience, App downloads, Posts published — each with
   change vs previous and a sparkline.
2. **Key findings**: up to 8 short sentences generated by `Marsad_Insights::from_snapshots( $range_key )`
   (new class): biggest channel change, top rising query, fastest-growing country, page with most views and
   one with high views but low engagement, search opportunity with most potential clicks, share of mobile,
   new-visitor share, YouTube subscriber change, posts published vs previous. Each insight: text (translated,
   sprintf with formatted numbers) + tone (good/bad/neutral) + link to the tab that explains it. No insight
   when data is missing — never invent.
3. **Platform cards grid** — one card per enabled source (GA4, Search Console, YouTube, Facebook, Instagram,
   Telegram, Google Play, App Store, every custom source, WordPress, Site): logo-letter badge, 1 big number, 2–3
   secondary numbers, change badge, a mini chart (sparkline or mini bars), link to its tab. A source with a sync
   error shows its error state instead of numbers.
4. Infographic row: channels donut (SVG, from GA4 channels), devices split bar, top 5 countries bars with
   flags-as-letters (ISO code chips, no images), new vs returning split, search types split (web/image/video/news).
5. Existing daily posts chart and busiest categories remain.

**Audience**: totals cards (all 14 GA4 totals with changes), realtime card (active now + top pages now,
auto-refresh 60 s via the ajax endpoint), channels, source/medium, campaigns, social, referrals, countries,
cities, languages, devices, OS, browsers, models, resolutions, new vs returning, age, gender, heatmap
(7×24 grid, colour scale, accessible table fallback), events, key events, search terms, outbound links, scroll depth.

**Content**: add GA pages table (views, users, avg engagement time = userEngagementDuration / activeUsers,
engagement rate, change), landing pages, authors (GA), categories (GA), content age (views by publish month from
post_dates), alongside the existing WordPress sections.

**Search**: totals per search type (cards), queries table, pages table, countries, devices, appearance,
opportunities, rising, falling, query→page, sitemaps.

**Table component** (one, reused): server-rendered `<table>` with sortable headers (click to sort, aria-sort),
a search box filtering rows client-side, first 25 rows visible + "Show all N" button, change column with the
existing change badge, `total_rows` note ("Top 1,000 of 5,206"), and an **Export CSV** button →
`admin-post.php?action=marsad_export&source=…&range=…&report=…&_wpnonce=…` (cap marsad_view) streaming CSV
with a UTF-8 BOM (Excel opens Arabic correctly), header row = translated labels, all stored rows.
JS lives in `assets/admin.js` (no external libraries), works without JS (all rows readable via "Show all" being
a `<details>` fallback).

Every new visible string goes through `__( …, 'marsad' )`. RTL via logical CSS properties only.
Escape everything; payload values are untrusted (page titles, queries).

## Tests (required; `php tests/run.php` must stay green, warnings are failures)

- Snapshots: put/get/upsert, stale by TTL, refresh order and deadline stop, error recorded per range and cleared,
  delete_source; DB install creates the new table (assert the SQL passed to dbDelta).
- GA4: `parse_snapshot` maps current/previous date ranges into m/p, drops (not set), keeps rowCount; skipped
  custom reports when metadata lacks the dimension; an incompatible report is isolated.
- Search Console: totals per type, queries with prev mapping, `opportunities()` and `movers()` on fixed data.
- Insights: each rule fires on crafted snapshots and stays silent without data.
- Dashboard: every tab (overview, content, audience, search) renders with fixture snapshots and with none; CSV
  export produces BOM + header + rows and refuses without the capability/nonce.
- Realtime ajax: nonce and capability enforced.

## Non-goals

No external chart libraries or fonts; no data leaves the site except the API calls themselves; no change to
the relay; translations are a separate step after the build (strings in English only during the build).
