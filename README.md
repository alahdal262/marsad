# Marsad — مَرصَد

**One dashboard for all your site's numbers.** Marsad pulls statistics from Google Analytics 4, Search Console, YouTube, Facebook, Instagram, Telegram, Google Play, the App Store, any JSON stats endpoint, and WordPress itself, stores them daily in your own database, and shows them on one page in wp-admin.

Each site connects its own accounts. Nothing passes through a third-party server. Updates are delivered from this repository's GitHub releases.

[العربية ↓](#بالعربية)

---

## Install

1. Download `marsad.zip` from the [latest release](https://github.com/alahdal262/marsad/releases/latest).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, and activate it.
3. Open **Marsad → Connections**, connect your sources, and press **Test** on each one.

Requires WordPress 6.5+ and PHP 7.4+ with the `openssl` extension.

Later releases appear in **Dashboard → Updates** like any other plugin. To turn that off, add `define( 'MARSAD_DISABLE_GITHUB_UPDATES', true );` to `wp-config.php`.

## Sources

| Source | What it records | What you need |
|---|---|---|
| WordPress | Posts per day since the very first post (posts later deleted included), comments, posts moved to the trash, posts deleted permanently (from install on); posts by author and category | Nothing. Always on. |
| Site & hosting | Totals of posts, pages, images, videos, comments and users by role; database and uploads size; home page response time; software versions and scheduled-task health | Nothing. Always on. |
| Google Analytics 4 | Users, new users, sessions, views, engagement; top pages, platforms, channels, sources, countries, devices | **Connect with Google**, then pick the property (advanced: a service-account key) |
| Search Console | Clicks, impressions, CTR, position; top queries and pages | **Connect with Google**, then pick the site (advanced: a service-account key) |
| YouTube | Subscribers, total views, videos; views of recent uploads | Paste the channel link. Any public channel works, no key needed (advanced: your own API key, or Connect with Google for your own channel) |
| Facebook Page | Followers, fans, people talking about the page | Paste one token from Facebook's token tools, pick the Page; Marsad keeps the Page's never-expiring token |
| Instagram | Followers, posts | Nothing more: found through the Facebook Page. Advanced: follow any public business/creator account by username |
| Telegram | Channel or group members | Bot token (the bot must be in the channel) and `@channel` or chat ID |
| Google Play | Daily installs, uninstalls, active devices, total users | **Connect with Google** with an account that can download Play reports, plus the package name and reports bucket |
| App Store | Downloads, re-downloads, updates; downloads by country | App Store Connect API key (Issuer ID, Key ID, `.p8`) with Sales access, vendor number; optional Apple ID to filter one app |
| Your own sources | Any numbers from a URL that returns JSON. None exist until you press **+ Add a source**; add as many as you need | URL and a list of `Label = path.to.value` lines |

### Connect with Google

One button connects Analytics, Search Console, YouTube and Google Play. The sign-in passes through **Marsad Connect** (`server/connect` in this repository), which holds Marsad's Google OAuth client so no site has to register its own. It keeps no tokens: the site that started the sign-in collects them once with a one-time secret, and later asks the relay for fresh access tokens with its refresh token. The statistics go straight from Google to your site. Privacy policy: https://form.yementv.tv/connect/privacy.html

Prefer not to use the relay? Each Google card still accepts a service-account key under **Advanced**, or you can run your own relay and point the plugin at it with `define( 'MARSAD_CONNECT_URL', 'https://your.domain/connect/' );`.

Secrets are encrypted at rest (AES-256-GCM, keyed from your `wp-config.php` salts) and are never shown again after saving. Leave a secret field empty to keep the stored value.

## How it works

- WP-Cron runs a sync hourly (configurable). The first sync of a source imports up to 90 days of history (configurable, capped per source); later syncs refresh the last few days, because most platforms revise recent numbers.
- Data lives in one table, `{prefix}marsad_metrics`, one row per source, metric, dimension and day. Old rows are purged after the retention period (default 730 days).
- Every setup step links straight to the page where you get what it asks for.
- The dashboard has six tabs: **Overview**, **Audience**, **Content & users**, **Search**, **Platforms & apps** and **Hosting**. Google Analytics and Search Console numbers for each period come from Google's own report of that period (range snapshots), so they match Google's interfaces. It compares each period with the one before it. Charts are server-rendered SVG, so the page loads no external scripts.
- Who can see the dashboard is set under **Marsad → Settings**. Only administrators can manage connections.

## Languages

Marsad follows each admin's own WordPress language (their profile language, else the site's): Arabic, English, French, Spanish, German, Italian, Portuguese, Russian, Turkish, Persian, Urdu, Hindi, Indonesian, Chinese and Japanese. Regional variants use their language's file (fr_CA reads French).

To add or fix a language: `php bin/l10n-catalog.php . > catalog.json`, edit or add `languages/source/tr-xx.json` (`{"singles": {english: text}, "plurals": [[forms…]]}`), then `php bin/l10n-build.php catalog.json languages/source/tr-xx.json xx`. The build refuses missing strings and changed placeholders.

## For developers

### Add your own source

```php
add_filter( 'marsad_connectors', function ( $list ) {
	$list[] = new My_Source();
	return $list;
} );

class My_Source extends Marsad_Connector {
	public function id()    { return 'my_source'; }
	public function label() { return 'My source'; }
	public function fields() {
		return array( 'token' => array( 'label' => 'Token', 'type' => 'secret', 'required' => true ) );
	}
	public function metrics() {
		return array( 'signups' => array( 'label' => 'Sign-ups', 'agg' => 'sum', 'kpi' => true, 'chart' => true ) );
	}
	public function fetch( $from, $to ) {
		$data = Marsad_Http::json( 'GET', 'https://api.example.com/stats?from=' . $from, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $this->get( 'token' ) ),
		) );
		$rows = array();
		foreach ( $data['days'] as $d ) {
			$rows[] = $this->row( 'signups', $d['date'], $d['count'] );
		}
		return $rows;
	}
}
```

`agg` is how days combine into a period: `sum` for counters, `avg` for rates, `last` for totals such as followers, `max`. Add `tables()` with the same shape for breakdowns and pass a fourth `$dim` argument to `row()`. Throw `Marsad_Exception` with a readable message on failure; it is shown on the Connections page.

Other filters: `marsad_headline` (the summary cards at the top of the dashboard) and `marsad_wordpress_post_types` (post types the WordPress source counts).

### Tests

```sh
php tests/run.php
```

No Composer or WordPress install needed; `tests/bootstrap.php` stubs what the code uses. CI runs lint and tests on PHP 7.4 and 8.3.

### Running Marsad Connect

Copy `server/connect/` to any PHP host with HTTPS and create `marsad-connect/config.php` four folders up (or set `MARSAD_CONNECT_CONFIG` to its path):

```php
<?php
return array(
	'client_id'     => '….apps.googleusercontent.com',
	'client_secret' => '…',
	'redirect_uri'  => 'https://your.domain/connect/callback.php',
	'data_dir'      => __DIR__ . '/tmp',
);
```

In Google Cloud: enable the Analytics Data, Analytics Admin, Search Console, YouTube Data and Cloud Storage APIs; create an OAuth consent screen (External, publish it to Production so refresh tokens do not expire after 7 days) and a Web OAuth client with the redirect URI above.

### Releasing

1. Bump the version in three places: the `Version:` header and `MARSAD_VERSION` in `marsad.php`, and `Stable tag:` in `readme.txt`.
2. Add a `## x.y.z` section to `CHANGELOG.md`.
3. Commit, then `git tag vX.Y.Z && git push --tags`.

The release workflow checks that the tag matches all three versions, runs the tests, builds `marsad.zip` and publishes the release. Every site running Marsad sees the update within about 6 hours, or immediately after **Settings → Check for updates now**.

To build a zip locally: `bin/build-zip.sh` (writes `dist/marsad.zip`).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

## بالعربية

**مَرصَد** لوحة واحدة لكل أرقام موقعك: يجلب الإحصاءات من Google Analytics 4 وSearch Console ويوتيوب وفيسبوك وإنستغرام وتيليغرام ومتجر Google Play وApp Store وأي رابط إحصاءات بصيغة JSON، ومن ووردبريس نفسه، ويخزّنها يومياً في قاعدة بيانات موقعك، ويعرضها في صفحة واحدة داخل لوحة التحكم.

كل موقع يربط حساباته بنفسه، ولا تمرّ البيانات عبر أي خادم وسيط. والتحديثات تصل لكل من ثبّت الإضافة من إصدارات هذا المستودع على GitHub.

### التثبيت

1. نزّل `marsad.zip` من [آخر إصدار](https://github.com/alahdal262/marsad/releases/latest).
2. في ووردبريس: **الإضافات ← أضف جديد ← رفع إضافة**، ثم فعّلها.
3. افتح **مرصد ← الاتصالات**، اربط مصادرك، واضغط **اختبار** على كل واحد.

يتطلب ووردبريس 6.5 أو أحدث وPHP 7.4 أو أحدث.

### الربط

- **مصادر Google** (التحليلات، Search Console، Google Play): أنشئ حساب خدمة في Google Cloud ومفتاح JSON له، وفعّل الواجهة البرمجية المذكورة في بطاقة الاتصال، ثم أضف بريد حساب الخدمة مستخدماً في المنتج نفسه. صفحة الاتصالات تعرض هذا البريد بعد حفظ المفتاح، وتحوّل رسالة «الواجهة غير مفعّلة» إلى الخطوة المطلوبة بالضبط.
- **يوتيوب**: الصق رابط القناة فقط، وتصلح أي قناة عامة دون مفتاح.
- **فيسبوك وإنستغرام**: معرّف الصفحة أو الحساب ورمز وصول طويل الأمد.
- **تيليغرام**: رمز بوت مضاف إلى القناة، ومعرّف القناة.
- **App Store**: مفتاح App Store Connect API بصلاحية المبيعات ورقم المورّد.
- **مصادرك الخاصة**: لا يوجد أي منها عند التثبيت؛ اضغط **+ أضف مصدراً** في صفحة الاتصالات، وأدخل الاسم والرابط وأسطراً بصيغة `الاسم = المسار.إلى.القيمة`. أضف ما تشاء منها، واحذف أيّاً منها متى شئت.

الأسرار تُخزَّن مشفّرة ولا تُعرض بعد الحفظ. اترك حقل السر فارغاً للإبقاء على القيمة المحفوظة.

### اللغة

تظهر الإضافة بلغة المستخدم في ووردبريس تلقائياً: العربية والإنجليزية والفرنسية والإسبانية والألمانية والإيطالية والبرتغالية والروسية والتركية والفارسية والأردية والهندية والإندونيسية والصينية واليابانية.

### من يرى اللوحة

تختار من **مرصد ← الإعدادات** الأدوار التي ترى لوحة الإحصاءات (المحررون أو الكتّاب مثلاً). إدارة الاتصالات للمدير فقط.

### التحديثات

عند نشر إصدار جديد على GitHub يظهر التحديث في **لوحة التحكم ← التحديثات** في كل موقع خلال ست ساعات تقريباً، أو فوراً من زر «تحقق من التحديثات الآن» في الإعدادات.
