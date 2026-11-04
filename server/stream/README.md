# Marsad live-stream collector

Counts who watches an HLS live stream and on what (website, iPhone/Android app, smart TVs,
IPTV players, other sites, re-broadcasting tools), and sends **counts only** to a Marsad site
every 5 minutes. Viewer IP addresses never leave the server: a viewer is a salted hash that
changes every day and is forgotten after two days. It only reads the web server's access log
(`docker logs`); it does not touch the streaming software.

## Install (Linux with systemd, Python 3.8+, Docker)

```sh
sudo mkdir -p /opt/marsad-stream
sudo cp marsad_stream.py /opt/marsad-stream/
sudo cp marsad-stream.service marsad-stream.timer /etc/systemd/system/
sudo install -m 600 /dev/null /etc/marsad-stream.env
sudo tee /etc/marsad-stream.env >/dev/null <<'ENV'
MARSAD_URL=https://example.com/wp-json/marsad/v1/push/stream
MARSAD_TOKEN=the push key from Marsad → Connections → Live stream
MARSAD_CONTAINER=web
MARSAD_PREFIX=/memfs/
MARSAD_OWN_HOSTS=example.com
ENV
sudo python3 /opt/marsad-stream/marsad_stream.py --dry-run   # look at what would be sent
sudo systemctl daemon-reload && sudo systemctl enable --now marsad-stream.timer
```

`MARSAD_CONTAINER` is the container whose log has the stream requests; `MARSAD_PREFIX` the
path of the segments. The log line must be nginx's default format plus the forwarded client
address as a last quoted field (as Traefik/nginx setups usually log it).

Tests: `python3 -m unittest discover -s server/stream`.
