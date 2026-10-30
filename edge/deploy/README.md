# NiceTryBot edge for self-hosting

Two images from the [`Dockerfile`](../Dockerfile) in `edge/`, for amd64 and arm64. The repository's
`deploy/compose.yml` runs them next to the control panel. How the two halves fit together (names, paths, headers)
is in [CONTRACT.md](CONTRACT.md).

| Image | Build | What it is |
|---|---|---|
| edge | `docker build -t nicetry/edge .` | Caddy 2.11.7 with the modules in `caddy/build.sh`: caddy-storage-redis v1.8.2, caddy-ja3ja4 v0.2.0 and `nicetrybot.com/caddy/capture` (`caddy/nicetry-capture`, its tests run during the build). Ships `deploy/Caddyfile`, `deploy/Caddyfile.d/`, the entrypoint `nicetry-edge` and `dbip-fetch`. |
| vector | `docker build --target vector -t nicetry/vector .` | Vector 0.58.0 (the version production runs) with `deploy/vector/`. It tails the edge's access log, redacts and enriches each line exactly as production does, and sends it to the app. |

## The edge

On start, `nicetry-edge` does four things. It checks the settings and stops with a plain message if one is wrong.
It fetches the DB-IP Lite City and ASN databases into `/opt/dbip` when they are missing or more than 35 days old,
waiting up to 5 minutes for that the first time. It validates the Caddyfile. Then it runs Caddy as the `caddy`
user (uid 1000). Nothing is rendered: Caddy reads its settings from the environment. DB-IP is checked again every
day, or every hour while a download fails, and Caddy picks up new files on its own. Without DB-IP the edge still
runs; visitors' places are just unknown.

The Caddyfile has the same handlers, in the same order, as production. The control panel is one site
(`NICETRY_PANEL_HOST`), proxied to the app, with a certificate obtained up front. Its `/caddy/*` gets nginx's 404
at the edge, because only the edge and Vector use those endpoints, and they call the app directly. Every other hostname gets a
certificate on demand if the app's `/caddy/ask` allows it (asked with the token), and is routed by
`nicetry_origins` to its origin, but only on a public address unless `NICETRY_ALLOW_PRIVATE_ORIGINS` is on. A
request an origin sends back to the edge (a loop) gets nginx's 508, and a path with `../` gets nginx's 400. `/_q/*`
goes to the app (the gym). Responses carry `Server: nginx` and nginx's own error pages. The PHP upload-progress guard is on.
The access log is written as JSON to `/var/log/caddy/access.log`, rolled at 100 MiB with ten files kept. Two things
are left out on purpose: production's per-customer sites and exceptions, and HTTP/3.

Commands: `run` (the default) and `validate`. `reload` applies an edited, mounted Caddyfile:
`docker compose exec edge nicetry-edge reload`. Use that, not a bare `caddy reload`: the entrypoint works out the
Redis settings first. Anything else runs as given, e.g. `docker compose exec edge dbip-fetch --force`.

| Variable | Default | |
|---|---|---|
| `NICETRY_PANEL_HOST` | (required) | The control panel's hostname, e.g. `panel.example.com`. |
| `NICETRY_EDGE_TOKEN` | (required) | Bearer token for the app's `/caddy/*` endpoints: the same value as the app's. Letters, digits, `.`, `-`, `_`; a shorter one than 32 characters gets a warning. |
| `NICETRY_APP_URL` | `http://app:8080` | The app as the edge reaches it on the private network. |
| `NICETRY_ACME` | `letsencrypt` | `letsencrypt` (with ZeroSSL when Let's Encrypt refuses) or `internal` (Caddy's own CA, for local runs and tests). |
| `NICETRY_ACME_EMAIL` | (required for letsencrypt) | ACME account email. ZeroSSL needs it too. |
| `NICETRY_CERT_STORAGE` | `file` | `file` (in `/data`, for one node) or `redis` (shared by several edge nodes). |
| `REDIS_URL` | | With `redis` storage: `redis://` or `rediss://` (TLS) `[user[:password]@]host[:port][/db]`; percent-encode special characters. |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_DB` | `6379`, db `0` | Used when there is no `REDIS_URL` (the app's own keys). A `tls://` host or `REDIS_SCHEME=tls` turns on TLS. Laravel's `null` counts as empty. |
| `NICETRY_CERT_REDIS_URL` | | A separate Redis (or database) just for certificates, instead of the app's. |
| `NICETRY_CERT_REDIS_CA` | | Path to a PEM CA for a Redis whose certificate isn't publicly trusted. Without it, the system's CAs are used and the hostname is checked. |
| `NICETRY_CERT_REDIS_TLS` | (from the URL) | `true` or `false`, to override what the URL implies. |
| `NICETRY_BLOCK_MULTIPART` | `off` | `on` refuses every multipart upload with nginx's 403 (bot_tag `php_multipart`): the emergency switch for a PHP upload CVE. |
| `NICETRY_ALLOW_PRIVATE_ORIGINS` | `false` | `true` lets sites' origins be on a private network (10.x, 192.168.x, a container's name). Off, the edge only connects to origins on public addresses, so nobody who can add a domain can make it reach the cloud's metadata service or the stack's other containers (they get nginx's 502). Set the app's to the same value. |
| `NICETRY_DBIP` | `on` | `off` never fetches DB-IP. |
| `NICETRY_DBIP_WAIT` | `300` | How many seconds a start waits for the first DB-IP download before Caddy starts without it. |
| `NICETRY_DBIP_URL` | `https://download.db-ip.com/free` | Where `dbip-fetch` looks for `dbip-{city,asn}-lite-YYYY-MM.mmdb.gz`, e.g. a mirror. |
| `NICETRY_DBIP_MAX_AGE` | `35` | Days before the files are fetched again. |

Ports: 80 and 443 (TCP). The admin API listens on localhost:2019 inside the container only. The healthcheck is
built in: the container is healthy once port 80 answers.

Volumes: `/data` holds certificates (`/data/caddy`) and the last lockdown list (`/data/nicetry/lockdown.json`), so a
restart while the app is down keeps locked sites locked. `/var/log/caddy` holds the access log, which Vector reads.
`/opt/dbip` holds the DB-IP files, which Vector reads too. When the edge starts as root (the default), it gives
these directories to uid 1000.

## Vector

The entrypoint picks the log mode from `EDGE_LOGS_BUCKET`. It loads `vector.yaml` (the source, redaction and
DB-IP) and then one of two sink files:

- **direct** (no bucket, the default): `direct.yaml`. Every line goes to `$NICETRY_APP_URL/caddy/ingest` as
  NDJSON, every 2 s, one batch at a time. Failed batches are retried until the app takes them. A backlog waits in
  a 256 MiB disk buffer.
- **bucket**: `bucket.yaml`. This is production's shape. Every line goes to the bucket as gzipped NDJSON under
  `raw/dt=YYYY-MM-DD/hh=HH/`, for the app's `edge:import-logs`. Only lines Caddy marks `tail: true` go to
  `/caddy/ingest`.

Vector doesn't read environment variables in its config, so `secret.sh` (an exec secret backend) hands them over.
The DB-IP tables are links under `/run/nicetry/dbip`. They point at the edge's files, or at an empty database until
those exist; the entrypoint waits up to a minute for them. Every 5 minutes it checks whether the edge has replaced
them, and if so it reloads Vector.

| Variable | Default | |
|---|---|---|
| `NICETRY_EDGE_TOKEN` | (required) | As above. |
| `NICETRY_APP_URL` | `http://app:8080` | As above. |
| `EDGE_LOGS_BUCKET` | (empty: direct mode) | The bucket, for bucket mode. |
| `EDGE_LOGS_REGION` | `us-east-1` | E.g. `nyc3` for Spaces, `auto` for R2, `us-east-1` for MinIO. |
| `EDGE_LOGS_ENDPOINT` | AWS's regional endpoint | Set it for anything that isn't AWS, e.g. `https://nyc3.digitaloceanspaces.com`. |
| `EDGE_LOGS_PATH_STYLE` | `true` with an endpoint, `false` without | Bucket in the path rather than the hostname. |
| `EDGE_LOGS_KEY`, `EDGE_LOGS_SECRET` | (empty: the instance's role) | Static access keys. |
| `NICETRY_DBIP_WAIT` | `60` | Seconds to wait for the edge's DB-IP files at start. |
| `NICETRY_DBIP_RECHECK` | `300` | Seconds between checks for refreshed DB-IP files. |

Volumes: `/var/log/caddy` and `/opt/dbip` from the edge (read-only is enough), and `/var/lib/vector`, which must
persist. It holds the file checkpoints and the disk buffers. Event ids are made new each time a line is read, so a
Vector that loses its place sends the whole log again, and every line comes back as a new request.

## Smoke test, the edge alone

```sh
docker build -t nicetry/edge .
docker network create nicetry-smoke
# Anything on the network can stand in for the app; whoami echoes each request back.
docker run -d --name app --network nicetry-smoke traefik/whoami:v1.11
docker run -d --name edge --network nicetry-smoke -p 127.0.0.1:8443:443 \
  -e NICETRY_ACME=internal -e NICETRY_PANEL_HOST=panel.localhost \
  -e NICETRY_APP_URL=http://app:80 -e NICETRY_EDGE_TOKEN=$(openssl rand -hex 24) nicetry/edge
docker logs -f edge                      # DB-IP downloads first (about 65 MB), then Caddy starts
curl -sk --resolve panel.localhost:8443:127.0.0.1 https://panel.localhost:8443/ -D -   # 200, server: nginx
docker rm -f edge app && docker network rm nicetry-smoke
```

whoami doesn't answer the `/caddy/*` polls with JSON, so the edge logs that it is keeping its (empty) lists.

`deploy/smoke/run.sh [https-port]` goes further, with no app needed. It builds both images, then answers the
edge's calls with a stub app (`deploy/smoke/stub_app.py`). It checks four things:
- the panel answers with `Server: nginx`;
- `site.localhost` is routed by `nicetry_origins` to a whoami origin (a container, so the edge runs with
  `NICETRY_ALLOW_PRIVATE_ORIGINS=true`);
- Vector delivers the redacted lines to `/caddy/ingest`;
- the first two batches, which the stub refuses with a 503, arrive anyway after retries.

It prints PASS and removes everything it started.
