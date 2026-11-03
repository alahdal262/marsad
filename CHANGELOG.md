# Changelog

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
