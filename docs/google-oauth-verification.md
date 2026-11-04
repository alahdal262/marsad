# Google OAuth verification for Marsad Connect

Until Google verifies the Marsad OAuth app (project `marsad-513623`), people who press
"Connect with Google" see "Google hasn't verified this app", and at most 100 accounts can
ever sign in. Verification removes both. This file is everything the submission needs.

## Where

Google Cloud console → project **Marsad** → Google Auth Platform → **Verification Center**
→ "Prepare for verification".

## Before submitting

1. **Domain ownership.** `yementv.tv` must be verified in Search Console by the same Google
   account that owns the Cloud project (or that account added as an owner there). It already
   is a Search Console property; add `enyementv@gmail.com` as an owner if it is not.
2. **Branding is complete:** app name *Marsad*, logo (120×120 PNG), support e-mail, home page
   `https://form.yementv.tv/connect/`, privacy policy
   `https://form.yementv.tv/connect/privacy.html`, authorized domain `yementv.tv`.
3. **Scopes listed** under Data access are exactly the five below; nothing else.

## Scopes and why each is needed (paste into the form)

| Scope | Sensitivity | Justification |
|---|---|---|
| `openid`, `email` | non-sensitive | Shows the site owner which Google account is connected ("Connected as …") so they can disconnect the right one. |
| `https://www.googleapis.com/auth/analytics.readonly` | sensitive | Reads the Google Analytics 4 reports of the owner's own property (users, page views, traffic sources, countries, devices) to show them on the owner's WordPress dashboard. Read-only; no configuration is changed. |
| `https://www.googleapis.com/auth/webmasters.readonly` | sensitive | Reads Search Console performance data (clicks, impressions, queries, pages) and sitemap status of the owner's own verified site, shown on their dashboard. Read-only. |
| `https://www.googleapis.com/auth/youtube.readonly` | sensitive | Lists the owner's own YouTube channels so they can pick one, and reads its public statistics (subscribers, views, video counts). Read-only. |
| `https://www.googleapis.com/auth/devstorage.read_only` | sensitive | Google Play Console stores the app's monthly installs reports in a Cloud Storage bucket owned by Google Play; this scope reads those CSV reports for the owner's own app. Read-only; no other bucket is used. |

**Why narrower scopes are not enough:** each API offers no narrower read-only scope for these
reports (Analytics Data API and Search Console API require exactly these; Play's statistics
reports are only published to Cloud Storage).

**Data handling (Limited Use):** tokens pass through Marsad Connect only for the moment of
sign-in and refresh, and are never stored or logged there. Statistics go straight from Google
to the owner's own WordPress database and are shown only to users that site's administrator
allows. Nothing is sold, used for advertising, or shared. Source code:
https://github.com/alahdal262/marsad (relay in `server/connect`).

## Demo video (required for sensitive scopes)

Unlisted YouTube video, 2–3 minutes, English narration or captions, showing:

1. The browser address bar on a WordPress admin with Marsad installed: Marsad → Connections.
2. Press **Connect with Google**. Show the consent screen with the app name **Marsad** and
   the **client ID visible in the address bar** (Google checks it matches the project).
3. Grant every scope; show the return to Connections with "Connected as …".
4. For each scope, show where its data appears:
   - Analytics: pick the property, open Dashboard → Audience (users, channels, countries).
   - Search Console: pick the site, open Dashboard → Search (queries, pages).
   - YouTube: pick the channel; show the YouTube card (subscribers, views).
   - Play: show the Google Play card with installs.
5. Show **Disconnect Google** and the privacy policy link.

## After submitting

Google usually answers by e-mail to the project owner within 3–5 working days, sometimes with
questions; sensitive-scope reviews can take a few weeks. Nothing changes for already-connected
sites in the meantime.
