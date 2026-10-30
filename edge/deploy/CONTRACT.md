# Edge ↔ app: what the self-host stack joins on

The edge half (`edge/`) and the app half (`deploy/` at the repository root) meet here. Variables and defaults are
listed in [README.md](README.md).

## Images and compose services

| Service | Image (default) | Build | Notes |
|---|---|---|---|
| `edge` | `${NICETRY_EDGE_IMAGE:-ghcr.io/nicetrybot/edge:latest}` | context `../edge` | Publishes `80:80` and `443:443` (TCP). Has its own healthcheck. Uses `restart: unless-stopped`. |
| `vector` | `${NICETRY_VECTOR_IMAGE:-ghcr.io/nicetrybot/vector:latest}` | context `../edge`, `target: vector` | `timberio/vector:0.58.0-alpine` with `deploy/vector` built in. A published install needs no files from this repo. Uses `depends_on: edge: condition: service_healthy`, so DB-IP has been tried first. Uses `restart: unless-stopped`. |

Volumes:

| Volume | edge | vector |
|---|---|---|
| `edge-data` | `/data` | |
| `edge-logs` | `/var/log/caddy` | `/var/log/caddy:ro` |
| `edge-dbip` | `/opt/dbip` | `/opt/dbip:ro` |
| `vector-data` (new) | | `/var/lib/vector` (must persist, see below) |

Environment, one `.env` for all services:
- edge: `NICETRY_PANEL_HOST`, `NICETRY_EDGE_TOKEN`, `NICETRY_APP_URL=http://app:8080` (fixed in compose),
  `NICETRY_ACME`, `NICETRY_ACME_EMAIL`, `NICETRY_CERT_STORAGE`. With `redis` storage it also reads the app's own
  `REDIS_URL` or `REDIS_HOST`/`REDIS_PORT`/`REDIS_USERNAME`/`REDIS_PASSWORD`/`REDIS_DB`/`REDIS_SCHEME`, plus the
  optional `NICETRY_CERT_REDIS_URL`, `NICETRY_CERT_REDIS_CA` and `NICETRY_CERT_REDIS_TLS`. Also optional:
  `NICETRY_BLOCK_MULTIPART`, `NICETRY_ALLOW_PRIVATE_ORIGINS` (the same value as the app's, which checks origins
  when they're saved) and the `NICETRY_DBIP*` settings. Passing the whole `.env` with `env_file` is fine:
  unknown keys are ignored, and Laravel's `null` counts as empty.
- vector: `NICETRY_EDGE_TOKEN`, `NICETRY_APP_URL`, `EDGE_LOGS_BUCKET`, `EDGE_LOGS_REGION`, `EDGE_LOGS_ENDPOINT`,
  `EDGE_LOGS_PATH_STYLE`, `EDGE_LOGS_KEY`, `EDGE_LOGS_SECRET`.
- app: the same `NICETRY_EDGE_TOKEN` (config `services.nicetry_edge.token`). In bucket mode, the same
  `EDGE_LOGS_*` for the `edge-logs` disk. When `EDGE_LOGS_ENDPOINT` is set, `EDGE_LOGS_PATH_STYLE` should default to
  true, as it does on the Vector side.

## Paths

| Path (edge container) | What | Owner |
|---|---|---|
| `/var/log/caddy/access.log` | Caddy's JSON access log, mode 0640, rolled as `access-<time>.log` (100 MiB, 10 kept) | uid 1000. Vector must run as root (the image default) or uid 1000 to read it. |
| `/opt/dbip/dbip-city-lite.mmdb`, `/opt/dbip/dbip-asn-lite.mmdb` | DB-IP Lite, 0644, swapped by rename | uid 1000 |
| `/data/caddy` | certificates (file storage) and Caddy's local CA | uid 1000 |
| `/data/nicetry/lockdown.json` | the last lockdown list | uid 1000 |
| `/var/lib/vector` (vector container) | checkpoints and disk buffers | root |

## What the edge calls on the app

All calls go to `$NICETRY_APP_URL`, i.e. `http://app:8080`, straight over the compose network, not through the
edge. All of them carry `Authorization: Bearer $NICETRY_EDGE_TOKEN`, `ask` included (the edge's `nicetry_ask`
permission module sends it as a header, so the token is never in a URL or in Caddy's logs).

| Call | When | Answer the edge expects |
|---|---|---|
| `GET /caddy/ask?domain=<host>` | before an on-demand certificate | 2xx allows it, anything else refuses. Not asked for the panel host. |
| `GET /caddy/origins` | every 30 s, ETag | `{"origins":[...]}` |
| `GET /caddy/resolve-origin` | a host missing from the last poll | as now |
| `GET /caddy/site-headers` | every 30 s | `{"sites":[...]}` |
| `GET /caddy/response-captures` | every 30 s, ETag | `{"captures":[...]}` |
| `GET /caddy/gym-triggers` | every 30 s, ETag | `{"triggers":[...]}` |
| `GET /caddy/gym-check`, `GET /caddy/gym-report` | per request on a trigger | as now (3 s timeout; the request goes through if the app fails). `X-NiceTry-Uri` is the path the trigger matched: cleaned, or without a leading `*.php` segment (`/index.php/login` is sent as `/login`), when that's what matched. |
| `GET /caddy/live-tail` | every 10 s | `{"hosts":[...]}` |
| `GET /caddy/lockdowns` | every 10 s, ETag | `{"lockdowns":[...]}` (must name the key) |
| `GET /caddy/tarpit-feed` | every 10 s, ETag | `{"tarpits":[...]}` (must name the key) |
| `POST /caddy/ingest` | Vector, every ~2 s | 2xx. Body below. |

Polls that fail keep the last list, so sites stay up while the app restarts.

## Requests the edge proxies to the app

- **Panel**: `https://$NICETRY_PANEL_HOST/*` goes to the app with the Host unchanged (the panel host). Caddy sets
  `X-Forwarded-For`, `X-Forwarded-Proto: https` and `X-Forwarded-Host` itself and overwrites whatever the visitor
  sent. The app's TrustProxies must trust the edge's address on the compose network for these.
- **Except** `/caddy/*` on the panel host, which the edge answers itself with nginx's 404. Those endpoints are
  only for the edge and Vector, which call `$NICETRY_APP_URL` directly, with the token. On other hosts
  `/caddy/*` is just a path on that site's origin. Production's Caddyfile is unchanged.
- **Gym**: `/_q/*` on any host, panel included, goes to the app with `Host: $NICETRY_PANEL_HOST`, `X-Forwarded-Host:`
  the site's host, `Authorization: Bearer <token>`, `X-NiceTry-Client-Ip` and `X-NiceTry-JA4`. Cookies are
  stripped. These requests are not logged. This matches production, where the Host is the app's own.
- The `/up` health route is just a panel path through the edge (`https://panel.localhost/up`). Compose's own
  healthcheck for the app should call `http://localhost:8080/up` inside the app container.

## Requests the edge proxies to origins

- Only to public addresses: the `nicetry_origin` transport resolves the origin's hostname when it opens a
  connection and refuses private, loopback, link-local (169.254.169.254), unique-local, carrier-grade NAT,
  benchmarking, multicast and reserved addresses (IPv4-mapped too) with nginx's 502. Set
  `NICETRY_ALLOW_PRIVATE_ORIGINS=true` on both the app and the edge when origins really are on the private
  network (a container on the compose network, say).
- Every one carries `X-NiceTry-Hop`, a hash of `NICETRY_EDGE_TOKEN`. A request that arrives with this
  installation's value came from the edge itself (an origin pointing back at a site or preview the edge
  serves) and gets nginx's 508 Loop Detected. A visitor's own `X-NiceTry-Hop` is dropped.
- A path with `.` or `..` segments (after decoding) gets nginx's 400 before anything else, origins and the gym
  included.

## Ingest body

`Content-Type: application/x-ndjson`: one JSON object per line, the same shape as the bucket files and
`App\Actions\Edge\StoreEdgeLogLines` reads them. Each line has `event_id` (uuid v7), `occurred_at`, `host`,
`client_ip`, `method`, `path` (no query string, PINs masked as `:pin`), `status`, `duration_ms`, `user_agent`,
`action`, `bot_tag`, `ja3`, `ja4`, `country_code`, `region`, `city`, `latitude`, `longitude`, `asn` and `as_org`.
Some lines also carry `ja4h`, `via`, `ch_ua`, `ch_platform`, `lang`, `livewire_component`, and the `capture_id` /
`response_*` / `request_*` / `signals` fields.

- Batches are at most 1,000,000 bytes, so the app's nginx needs `client_max_body_size` of 2m or more. A 413 would
  drop the batch.
- In direct mode Vector retries timeouts, 408, 429 and 5xx with no limit, one batch at a time. A retried batch the
  app already stored is skipped by `event_id`. Any other 4xx (401 from a wrong token, 413, 422) drops that batch,
  and Vector logs it. Keep `NICETRY_EDGE_TOKEN` the same on both sides.
- Panel lines are sent too. The app skips them, because the panel host isn't a Domain.

## Why vector-data must persist

`event_id` is `uuid_v7(occurred_at)`: random apart from the time. It only dedupes the two copies of a line that
one Vector sent (bucket plus ingest, or a retry). A Vector without its checkpoints reads `access.log` from the
start again and makes new ids, so every line would be stored twice.

## Outbound calls from the edge

ACME (Let's Encrypt, ZeroSSL) and `download.db-ip.com`. Nothing else. Vector only talks to the app, and in bucket
mode to the bucket.
