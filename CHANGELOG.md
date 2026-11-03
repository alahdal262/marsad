# Changelog

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
