"""Tests for the live-stream collector: python3 -m unittest discover server/stream"""

import datetime as dt
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import marsad_stream as ms  # noqa: E402

OWN = ['yementv.tv']


def line(ua, referer='-', ip='1.2.3.4', t='04/Nov/2026:13:24:46 +0000', path='/memfs/x_output_0_video_1.ts?session=a', status='200'):
    return '172.16.1.2 - - [%s] "GET %s HTTP/1.1" %s 700000 "%s" "%s" "%s"' % (t, path, status, referer, ua, ip)


class Classify(unittest.TestCase):
    def test_platforms_seen_on_the_real_server(self):
        cases = {
            'YemenTV/5.1.1 (iOS; Mobile; Flutter)': 'app_ios',
            'YemenTV/5.1.1 (Android; Mobile; Flutter)': 'app_android',
            'YemenTV/5.1.1 (Mobile; Flutter)': 'app_unknown',
            'just_audio/1.0 (Linux;Android 14) AndroidXMedia3/1.4.1': 'app_android',
            'MITV.AGENT.2167281': 'tv_android',
            'Mozilla/5.0 (Linux; Android 9; SMART_TV Build/PPRN; wv) AppleWebKit/537.36': 'tv_android',
            'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36': 'tv_lg',
            'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36': 'tv_samsung',
            'Mozilla/5.0 (Linux; Android 9; AFTKA Build/PS7633) AppleWebKit/537.36': 'tv_fire',
            'Roku/DVP-12.5 (12.5.0.4174-88)': 'tv_roku',
            'Mozilla': 'iptv',
            'Mozilla/5.0': 'iptv',
            'Googlebot-Image/1.0': 'bot',
        }
        for ua, want in cases.items():
            self.assertEqual(ms.classify(ua, '-', OWN)[0], want, ua)

    def test_tools_are_named(self):
        self.assertEqual(ms.classify('Lavf/60.16.100', '-', OWN), ('tools', 'Lavf'))
        self.assertEqual(ms.classify('curl/8.5.0', '-', OWN), ('tools', 'curl'))
        self.assertEqual(ms.classify('noxpanel/1.0', '-', OWN), ('tools', 'noxpanel'))

    def test_browsers_by_page(self):
        chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36'
        self.assertEqual(ms.classify(chrome, 'https://yementv.tv/live/', OWN), ('website', 'yementv.tv'))
        self.assertEqual(ms.classify(chrome, 'https://live.yementv.tv/c.html', OWN), ('live_page', 'live.yementv.tv'))
        self.assertEqual(ms.classify(chrome, 'https://other.example/tv', OWN), ('other_sites', 'other.example'))
        self.assertEqual(ms.classify(chrome, '-', OWN)[0], 'direct')


class Counting(unittest.TestCase):
    def test_viewers_minutes_peak_and_no_ips_out(self):
        state = {'last_ts': 0, 'days': {}}
        chrome = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/141 Safari/537.36'
        lines = [
            line(chrome, 'https://live.yementv.tv/c.html', '9.9.9.1', '04/Nov/2026:13:24:46 +0000'),
            line(chrome, 'https://live.yementv.tv/c.html', '9.9.9.1', '04/Nov/2026:13:25:02 +0000'),
            line('YemenTV/5 (Mobile; Flutter)', '-', '9.9.9.2', '04/Nov/2026:13:25:10 +0000'),
            line('Lavf/60', '-', '9.9.9.3', '04/Nov/2026:13:25:11 +0000'),
            line('Googlebot/2.1', '-', '9.9.9.4', '04/Nov/2026:13:25:12 +0000'),
            line(chrome, '-', '9.9.9.5', '04/Nov/2026:13:25:13 +0000', path='/api/v3/process', status='200'),
            line(chrome, '-', '9.9.9.6', '04/Nov/2026:13:25:14 +0000', status='404'),
        ]
        newest = ms.update(state, lines, '/memfs/', OWN, 0)
        self.assertGreater(newest, 0)
        s = ms.summary(state['days']['2026-11-04'])
        self.assertEqual(s['viewers'], 3)
        self.assertEqual(s['platforms'], {'live_page': 1, 'app_unknown': 1, 'tools': 1})
        self.assertEqual(s['watch_minutes'], 4)  # 13:24 one viewer, 13:25 three
        self.assertEqual((s['peak'], s['peak_at']), (3, '13:25'))
        self.assertEqual(s['tools'], {'Lavf': 1})
        self.assertEqual(s['bots'], 1)
        self.assertNotIn('9.9.9', repr(s))

    def test_lines_already_counted_are_skipped(self):
        state = {'last_ts': 0, 'days': {}}
        l1 = [line('YemenTV/5 (Mobile; Flutter)', t='04/Nov/2026:13:24:46 +0000')]
        last = ms.update(state, l1, '/memfs/', OWN, 0)
        ms.update(state, l1, '/memfs/', OWN, last)
        self.assertEqual(ms.summary(state['days']['2026-11-04'])['watch_minutes'], 1)

    def test_now_counts_the_last_two_minutes(self):
        state = {'last_ts': 0, 'days': {}}
        ms.update(state, [line('Mozilla', ip='1.1.1.1', t='04/Nov/2026:13:24:46 +0000'), line('Mozilla', ip='1.1.1.2', t='04/Nov/2026:13:20:00 +0000')], '/memfs/', OWN, 0)
        now = dt.datetime(2026, 11, 4, 13, 25, 30, tzinfo=dt.timezone.utc)
        n = ms.now_summary(state['days']['2026-11-04'], now)
        self.assertEqual(n['viewers'], 1)
        self.assertEqual(n['platforms'], {'iptv': 1})


if __name__ == '__main__':
    unittest.main()


class OnlyWatchersCount(unittest.TestCase):
    def test_playlist_only_clients_are_not_viewers(self):
        state = {'last_ts': 0, 'days': {}}
        lines = [
            line('curl/8.5', path='/memfs/x.m3u8?ytvcheck=1', ip='5.5.5.5'),
            line('curl/8.5', path='/memfs/x_output_0_video.m3u8?session=a', ip='5.5.5.5'),
            line('Lavf/60', path='/memfs/x_output_0_video_10.ts?session=b', ip='6.6.6.6'),
        ]
        ms.update(state, lines, '/memfs/', OWN, 0)
        s = ms.summary(state['days']['2026-11-04'])
        self.assertEqual(s['viewers'], 1, 'checking the playlist is not watching')
        self.assertEqual(s['tools'], {'Lavf': 1})
