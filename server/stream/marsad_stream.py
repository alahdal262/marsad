#!/usr/bin/env python3
"""Marsad live-stream collector.

Reads the HLS web server's access log (nginx, through `docker logs`), counts who watched
the live stream and on what, and pushes only those counts to a Marsad site. Viewer IPs
never leave the server: a viewer is a hash of IP + User-Agent with a salt that changes
every day and is forgotten after two days.

Per day: unique viewers, minutes watched, peak concurrent viewers (and when), viewers by
platform, sites embedding the stream, other apps and re-broadcasting tools. Plus "now":
viewers in the last two minutes.

Configuration (environment, e.g. /etc/marsad-stream.env):
  MARSAD_URL       https://example.com/wp-json/marsad/v1/push/stream
  MARSAD_TOKEN     the push key shown on Marsad's Live stream card
  MARSAD_CONTAINER docker container of the web server (default: web)
  MARSAD_PREFIX    path of the stream segments (default: /memfs/)
  MARSAD_STATE     state file (default: /var/lib/marsad-stream/state.json)
  MARSAD_OWN_HOSTS comma-separated hosts that are your own pages (default: the URL's host and its subdomains)

Run every 5 minutes (systemd timer in this folder). `--dry-run` prints the payload instead of sending it.
"""

import datetime as dt
import hashlib
import json
import os
import re
import secrets
import subprocess
import sys
import urllib.parse
import urllib.request

LINE = re.compile(
    r'\[(?P<time>[^\]]+)\] "(?:GET|HEAD) (?P<path>\S+) HTTP/[\d.]+" (?P<status>\d{3}) \S+ '
    r'"(?P<referer>[^"]*)" "(?P<ua>[^"]*)"(?: "(?P<xff>[^"]*)")?'
)
CLIENT = re.compile(r'^(?P<ip>\S+) ')
SEGMENT = re.compile(r'\.(ts|m4s|aac|mp4|cmfv|cmfa)(\?|$)', re.I)

BOTS = re.compile(r'bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|uptime|pingdom|headless', re.I)
TOOLS = re.compile(
    r'^(Lavf|libav|ffmpeg|ffprobe|curl|Wget|python|Go-http-client|okhttp|Java|VLC|LibVLC|mpv|OBS|'
    r'Streamer|noxpanel|Deno|node|axios|snap\d*|HostHerbTV|NXT|GStreamer|Kodi|TiviMate|IPTV)',
    re.I,
)

# (key, test on UA) — order matters: the first match wins.
DEVICE_RULES = [
    ('tv_samsung', re.compile(r'Tizen|SMART-TV.*Samsung|SamsungBrowser.*TV', re.I)),
    ('tv_lg', re.compile(r'Web0S|webOS|NetCast', re.I)),
    ('tv_fire', re.compile(r'\bAFT[A-Z0-9]|AmazonWebAppPlatform|Fire TV', re.I)),
    ('tv_roku', re.compile(r'Roku', re.I)),
    ('tv_apple', re.compile(r'AppleTV|tvOS', re.I)),
    ('tv_android', re.compile(r'Android TV|GoogleTV|BRAVIA|SMART_TV|SmartTV|MITV|AFTS|\bTV\b.*Android|HbbTV', re.I)),
]


def classify(ua, referer, own_hosts):
    """Return (platform key, name for the tools/sites table or '')."""
    ua = ua or ''
    host = ''
    if referer and referer != '-':
        host = (urllib.parse.urlsplit(referer).hostname or '').lower()
    if BOTS.search(ua):
        return 'bot', ''
    if ua.startswith('YemenTV/') or 'Flutter' in ua:
        if re.search(r'\b(iOS|iPhone|iPad)\b', ua):
            return 'app_ios', ''
        if re.search(r'\bAndroid\b', ua):
            return 'app_android', ''
        return 'app_unknown', ''
    if ua.startswith('just_audio') or 'AndroidXMedia3' in ua or 'ExoPlayer' in ua:
        return 'app_android', ''
    if 'AppleCoreMedia' in ua:
        return 'app_ios', ''
    for key, rule in DEVICE_RULES:
        if rule.search(ua):
            return key, ''
    m = TOOLS.match(ua)
    if m or (ua and not ua.startswith('Mozilla')):
        name = (m.group(1) if m else re.split(r'[/ ;(]', ua, 1)[0]) or 'unknown'
        return 'tools', name[:40]
    if re.fullmatch(r'Mozilla(/[\d.]+)?', ua.strip()):
        # A bare "Mozilla" is how many IPTV and set-top players announce themselves.
        return 'iptv', ''
    if host:
        if any(host == h or host.endswith('.' + h) for h in own_hosts):
            return ('live_page' if host.startswith('live.') else 'website'), host
        return 'other_sites', host
    return 'direct', ''


def parse_time(s):
    return dt.datetime.strptime(s, '%d/%b/%Y:%H:%M:%S %z').astimezone(dt.timezone.utc)


def read_log(container, since):
    """Lines from `docker logs` since a unix time (inclusive); access logs only."""
    out = subprocess.run(
        ['docker', 'logs', '--since', str(int(since)), container],
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=False, timeout=120,
    ).stdout
    return out.decode('utf-8', 'replace').splitlines()


def update(state, lines, prefix, own_hosts, last_ts):
    """Fold log lines newer than last_ts into the per-day state. Returns the newest time seen."""
    newest = last_ts
    for line in lines:
        m = LINE.search(line)
        if not m or not m.group('path').startswith(prefix) or m.group('status') not in ('200', '206'):
            continue
        # Only media segments mean watching; a playlist alone is a check (monitors, link previews).
        if not SEGMENT.search(m.group('path')):
            continue
        try:
            t = parse_time(m.group('time'))
        except ValueError:
            continue
        ts = t.timestamp()
        if ts <= last_ts:
            continue
        newest = max(newest, ts)
        xff = (m.group('xff') or '').split(',')[0].strip()
        ip = xff if xff and xff != '-' else (CLIENT.match(line).group('ip') if CLIENT.match(line) else '')
        day = state['days'].setdefault(t.strftime('%Y-%m-%d'), new_day())
        vid = hashlib.sha256((day['salt'] + ip + '|' + m.group('ua')).encode()).hexdigest()[:16]
        platform, name = classify(m.group('ua'), m.group('referer'), own_hosts)
        if platform == 'bot':
            day['bots'][vid] = 1
            continue
        day['viewers'][vid] = platform
        if platform in ('tools', 'other_sites', 'website', 'live_page') and name:
            day['names'].setdefault(platform, {}).setdefault(name, {})[vid] = 1
        day['minutes'].setdefault(t.strftime('%H:%M'), {})[vid] = 1
    return newest


def new_day():
    return {'salt': secrets.token_hex(16), 'viewers': {}, 'minutes': {}, 'names': {}, 'bots': {}}


def summary(day):
    """Counts only: nothing that identifies a viewer leaves this function."""
    by_platform = {}
    for platform in day['viewers'].values():
        by_platform[platform] = by_platform.get(platform, 0) + 1
    peak, peak_at = 0, ''
    for minute, ids in day['minutes'].items():
        if len(ids) > peak:
            peak, peak_at = len(ids), minute
    names = {
        kind: {name: len(ids) for name, ids in sorted(group.items(), key=lambda x: -len(x[1]))[:100]}
        for kind, group in day['names'].items()
    }
    return {
        'viewers': len(day['viewers']),
        'watch_minutes': sum(len(ids) for ids in day['minutes'].values()),
        'peak': peak,
        'peak_at': peak_at,
        'platforms': by_platform,
        'sites': names.get('other_sites', {}),
        'tools': names.get('tools', {}),
        'bots': len(day['bots']),
    }


def now_summary(day, now):
    """Viewers in the last two minutes, by platform."""
    ids = {}
    for back in (0, 1):
        minute = (now - dt.timedelta(minutes=back)).strftime('%H:%M')
        ids.update(day['minutes'].get(minute, {}))
    platforms = {}
    for vid in ids:
        p = day['viewers'].get(vid, 'direct')
        platforms[p] = platforms.get(p, 0) + 1
    return {'viewers': len(ids), 'platforms': platforms, 'at': now.strftime('%Y-%m-%dT%H:%M:%SZ')}


def load_state(path):
    try:
        with open(path) as f:
            return json.load(f)
    except (OSError, ValueError):
        return {'last_ts': 0, 'days': {}}


def save_state(path, state):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(state, f)
    os.chmod(tmp, 0o600)
    os.replace(tmp, path)


def main():
    url = os.environ.get('MARSAD_URL', '')
    token = os.environ.get('MARSAD_TOKEN', '')
    container = os.environ.get('MARSAD_CONTAINER', 'web')
    prefix = os.environ.get('MARSAD_PREFIX', '/memfs/')
    state_path = os.environ.get('MARSAD_STATE', '/var/lib/marsad-stream/state.json')
    own = os.environ.get('MARSAD_OWN_HOSTS', '')
    site_host = (urllib.parse.urlsplit(url).hostname or '').lower()
    own_hosts = [h.strip().lower() for h in own.split(',') if h.strip()] or [site_host.split('www.', 1)[-1]]
    dry = '--dry-run' in sys.argv

    state = load_state(state_path)
    now = dt.datetime.now(dt.timezone.utc)
    since = state['last_ts'] or (now - dt.timedelta(minutes=10)).timestamp()
    state['last_ts'] = update(state, read_log(container, since), prefix, own_hosts, state['last_ts'])
    # Only today and yesterday are kept: older salts are forgotten.
    keep = {(now - dt.timedelta(days=d)).strftime('%Y-%m-%d') for d in (0, 1)}
    state['days'] = {k: v for k, v in state['days'].items() if k in keep}
    today = state['days'].setdefault(now.strftime('%Y-%m-%d'), new_day())

    payload = {
        'version': 1,
        'generated': now.strftime('%Y-%m-%dT%H:%M:%SZ'),
        'days': {k: summary(v) for k, v in sorted(state['days'].items())},
        'now': now_summary(today, now),
    }
    if dry or not url:
        print(json.dumps(payload, indent=1, ensure_ascii=False))
    else:
        req = urllib.request.Request(
            url, data=json.dumps(payload).encode(), method='POST',
            headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token, 'User-Agent': 'marsad-stream/1'},
        )
        with urllib.request.urlopen(req, timeout=30) as r:
            if r.status != 200:
                raise SystemExit('Marsad answered HTTP %d' % r.status)
    save_state(state_path, state)


if __name__ == '__main__':
    main()
