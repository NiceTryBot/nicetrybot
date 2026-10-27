# The self-host stack: where the app and the edge meet

What each half relies on from the other, with the names both sides use. The app half lives here
(`deploy/` at the repository root); the edge half is `edge/Dockerfile` and `edge/deploy/`.
Change a name on one side only together with the other.

## Images

| Service | Image (default) | Built from | Target |
|---|---|---|---|
| `app`, `worker`, `scheduler` | `ghcr.io/nicetrybot/app:${NICETRY_VERSION:-latest}` | repository root, `deploy/Dockerfile` | `app` (last stage) |
| `edge` | `ghcr.io/nicetrybot/edge:${NICETRY_VERSION:-latest}` | `edge/`, `edge/Dockerfile` | `edge` |
| `vector` | `ghcr.io/nicetrybot/vector:${NICETRY_VERSION:-latest}` | `edge/`, `edge/Dockerfile` | `vector` |

`NICETRY_APP_IMAGE`, `NICETRY_EDGE_IMAGE` and `NICETRY_VECTOR_IMAGE` replace an image outright.
`NICETRY_EDGE_SRC` is the edge's source path for local builds (default `../edge` from `deploy/`,
i.e. the repository's `edge/` folder). A release publishes all three images under the same tag (and
`latest`), and attaches `compose.yml`, `compose.addons.yml` and `.env.example` from this folder to the GitHub release
`nicetrybot/nicetrybot` (`install.sh` fetches `<NICETRY_RELEASE_BASE>/latest/download/<file>` or
`<NICETRY_RELEASE_BASE>/download/<tag>/<file>`; that repository doesn't exist yet).

## Network and ports

One compose network (the project's default). The edge publishes `${NICETRY_HTTP_PORT:-80}:80` and
`${NICETRY_HTTPS_PORT:-443}:443` (TCP only; HTTP/3 is off). Nothing else is published by `compose.yml`;
`compose.test.yml` publishes the app on `127.0.0.1:${NICETRY_APP_TEST_PORT:-8080}` for local checks.

The edge and Vector reach the app at `NICETRY_APP_URL=http://app:8080`, fixed in `compose.yml`.

## Volumes

| Volume | Mounted in | Path |
|---|---|---|
| `edge-data` | edge | `/data` (certificates, `nicetry/lockdown.json`) |
| `edge-logs` | edge (rw), vector (ro) | `/var/log/caddy` (`access.log`) |
| `edge-dbip` | edge (rw), vector (ro) | `/opt/dbip` (`dbip-city-lite.mmdb`, `dbip-asn-lite.mmdb`) |
| `vector-data` | vector | `/var/lib/vector` (checkpoints, disk buffer) |
| `postgres-data` | postgres | `/var/lib/postgresql/data` |
| `valkey-data` | valkey | `/data` |
| `app-storage` | app, worker, scheduler | `/var/www/html/storage/app` |

## Environment

One `.env` beside `compose.yml` drives every service. The app, worker and scheduler get the whole file
(`env_file`) plus the stack defaults in `compose.yml`'s `x-app-env`; the edge and Vector get only the
keys below.

| Key | edge | vector | app | Notes |
|---|---|---|---|---|
| `NICETRY_PANEL_HOST` | yes | | yes | The panel's hostname; the app's `APP_URL` is `https://` + it. |
| `NICETRY_EDGE_TOKEN` | yes | yes | yes | Bearer token for `/caddy/*`. The app reads it as `services.nicetry_edge.token` (`App\Http\Middleware\AuthenticateEdge`). 32+ of `[A-Za-z0-9._-]` (the edge refuses others); install.sh makes 64 hex. |
| `NICETRY_APP_URL` | yes | yes | | Fixed: `http://app:8080`. |
| `NICETRY_ACME`, `NICETRY_ACME_EMAIL` | yes | | | `letsencrypt` (default) or `internal`. |
| `NICETRY_CERT_STORAGE`, `NICETRY_CERT_REDIS_URL`, `NICETRY_CERT_REDIS_CA` | yes | | | `file` or `redis`; redis reads the `REDIS_*` below unless `NICETRY_CERT_REDIS_URL` is set. |
| `REDIS_URL`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_DB` | yes | | yes | Same values on both sides. |
| `NICETRY_BLOCK_MULTIPART` | yes | | | `off` (default) or `on`. |
| `EDGE_LOGS_BUCKET` | | yes | yes | Empty: direct mode (every line to `/caddy/ingest`; `edge:import-logs` does nothing). Set: bucket mode. |
| `EDGE_LOGS_REGION`, `EDGE_LOGS_ENDPOINT`, `EDGE_LOGS_KEY`, `EDGE_LOGS_SECRET`, `EDGE_LOGS_PATH_STYLE` | | yes | yes (via `.env`) | Region defaults to `us-east-1`; path style defaults to true with an endpoint, false without; empty key and secret mean the instance role. |
| `NICETRY_EDGE_HOSTNAME`, `NICETRY_EDGE_ADDRESSES` | | | yes | `config/edge.php`. The hostname defaults to `NICETRY_PANEL_HOST` in compose. |
| `NICETRY_PREVIEW_ZONE` | | | yes | `config/edge.php`; empty in compose (previews off). |
| `TRUSTED_PROXIES` | | | yes | `config/trustedproxy.php`; compose sets `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7`. |

## What the app gives the edge

- Every endpoint is in `routes/edge.php`: `GET /caddy/ask?domain=` (no token; 200 or 404), and with
  `Authorization: Bearer $NICETRY_EDGE_TOKEN`: `response-captures`, `gym-triggers`, `gym-check`,
  `gym-report`, `origins`, `resolve-origin`, `site-headers`, `live-tail`, `lockdowns`, and
  `POST /caddy/ingest` (NDJSON in the S3 files' shape; deduplicated by `event_id`; 5xx means retry).
- `/_q/*` on any site is the gym, answered by the app. The edge sends `Host: $NICETRY_PANEL_HOST`,
  `X-Forwarded-Host: <the site>`, the bearer token and `X-NiceTry-Client-Ip`/`X-NiceTry-JA4`. The app
  trusts `X-Forwarded-*` from the private ranges, so it sees the site's host and the visitor's IP.
- `GET /up` is the app's health (200 once migrated and booted); the app image's healthcheck uses it.
- The app's nginx adds no `Strict-Transport-Security` (HSTS is the edge's, per domain); it would
  otherwise reach customers' domains through `/_q/*`.

## What the app needs from the edge

- The panel served at `https://$NICETRY_PANEL_HOST` → `reverse_proxy http://app:8080` with Caddy's
  default `X-Forwarded-For`/`-Proto`/`-Host` (the app builds https URLs and secure cookies from them).
- **Requested:** answer `/caddy/*` on the panel host with a 404 instead of proxying it. The edge
  reaches the app directly at `http://app:8080`, so nothing outside needs those paths; today they are
  public on the panel host behind the token (and `/caddy/ask` without one).

## The first run

`php artisan nicetry:install [--email=] [--password=] [--name=]` (in the `app` container) creates the
first administrator: verified email, a personal team, an `administrators` row. Without `--password`
(or with an empty answer at the prompt) it makes a 24-character one and prints it once. With an
administrator already there it changes nothing and exits 0. Either way it prints the panel URL
(`APP_URL`) and the DNS records from `config/edge.php`. Exit 1 on a missing (with `--no-interaction`),
invalid or taken email, or a password under 12 characters.

## Data stores

Postgres only. `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` may be `redis` (default) or
`database`; the `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` and `sessions` tables
come with the app's migrations, and the database store's locks serve the scheduler's
`withoutOverlapping`.
