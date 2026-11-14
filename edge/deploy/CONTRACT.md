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
| `GET /caddy/response-captures` | every 30 s, ETag | `{"captures":[...]}`, optionally `"tags"` and `"response_watch":[{id, hosts, pattern}]` (Flag Watch: RE2 patterns looked for in captured responses, body and headers) |
| `GET /caddy/gym-triggers` | every 30 s, ETag | `{"triggers":[...]}` |
| `GET /caddy/gym-check`, `GET /caddy/gym-report` | per request on a trigger | as now (3 s timeout; the request goes through if the app fails). `X-NiceTry-Uri` is the path the trigger matched: cleaned, or without a leading `*.php` segment (`/index.php/login` is sent as `/login`), when that's what matched. |
| `GET /caddy/live-tail` | every 10 s | `{"hosts":[...]}` |
| `GET /caddy/lockdowns` | every 10 s, ETag | `{"lockdowns":[...]}` (must name the key) |
| `GET /caddy/tarpit-feed` | every 10 s, ETag | `{"tarpits":[...]}` (must name the key) |
| `GET /caddy/blackhole` (the `nicetry-blackhole` agent, with the Blackhole add-on) | every 30 s, ETag | `{"mode":"observe"\|"enforce","entries":[{"address","expires_at"}],"protect":[...]}` (must name `entries`). `expires_at` is always a time: an entry with no end is sent with one about a day away, moved on each hour, so the kernel always holds a timeout. A 404 means the add-on is off: the agent removes its table. |
| `POST /caddy/blackhole/report` (the same agent) | every 30 s | Takes `{"edge","version","mode","entries","error","counters":[{"address","packets","bytes"}]}`, answers 204. |
| `GET /caddy/rewrite-feed` | every 10 s, ETag | `{"rewrites":[...]}` (must name the key) |
| `GET /caddy/honeypot-feed` (with the Honeypots add-on) | every 10 s, ETag | `{"honeypots":[...]}` (must name the key) |
| `GET /caddy/assets-feed` (with the Offload Assets add-on) | every 10 s, ETag | `{"assets":{"domain","pull_host","pull_aliases","secret_sha256","style","extensions","skip_paths","sites":[{"key","site","to","hosts","on"}]}}` (must name `assets`; an empty `domain` means no CDN). `to` (optional) is the site's CDN base URL, no trailing slash: `https://<key>.<domain>` in host style, `https://cdn.<domain>/<key>` in path style; `style` (optional, `host`\|`path`) only describes the `to`s. `pull_aliases` (optional) are other hostnames pulls arrive under (a CDN that can't set the origin Host, e.g. Cloudflare below Enterprise, pulls as its own hostname), treated exactly as `pull_host`. A 404 means the add-on is off: no CDN. |
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

A response from an origin never carries `NiceTryBot-Tag` to the visitor: `nicetry_remote_tag` strips it,
and when it's a well-formed tag (`v1.<payload>.<signature>`, URL-safe base64) sets it as the `_q_tag`
cookie (Path=/, a day, HttpOnly, Secure, SameSite=Lax). `_q_tag` is one of the gym's cookies: the gym
check gets it, and a cookie trigger (`limited`, no clients, no rules) has the edge ask about any request
carrying one on that trigger's hosts. The app checks the tag's signature, not the edge.

Every request to an origin loses any `X-NiceTry-Verdict` the visitor sent. For a client IP NiceTryBot has
flagged (`GET /caddy/client-verdicts`, polled every 30 s with the edge token: `{"verdicts":[{"ip","header"}]}`),
`nicetry_verdict` sets it to NiceTryBot's value, like `fraud; score=15.53; reasons=<URL-encoded, | separated>`.
The value is plain printable ASCII, at most 1024 bytes; anything else in the list is skipped.

A response from an origin on a hostname with rewrites (`GET /caddy/rewrite-feed`:
`{"rewrites":[{"id","hosts","find","replace","ignore_case","in","headers"}]}`) has every `find` in its body replaced by
`replace`, in the order listed, ignoring case when `ignore_case` is set. `in` is `html` (text/html and
application/xhtml+xml only) or `text` (any text/*, JavaScript, JSON and XML; never text/event-stream). The
origin is asked with `Accept-Encoding: identity` and no `Range`; a compressed response, a 204 or 304, a HEAD or
upgrade, and a body over 4 MB pass untouched. A rewritten response gets its new `Content-Length` and loses
`Accept-Ranges` and `Content-MD5`. With `headers` set, the same replacement also runs on every response's header
values (HEAD included, whatever the body), except `Content-Length`, `Content-Encoding`, `Content-Type`,
`Transfer-Encoding`, `Connection` and `Trailer`; line breaks in `replace` become spaces there.

A request on a hostname with honeypot traps (`GET /caddy/honeypot-feed`:
`{"honeypots":[{"id","honeypot","hosts","origin","secret","timeout","globs","regexes"}]}`) whose path fits one of a
trap's patterns (a glob matches the whole path in any case, `*` is anything; a regex matches anywhere, as written)
and that the origin answers with a 404 is answered by the trap's honeypot instead: the visitor's method, path,
query, headers and body (up to 1 MiB) go to `origin` with `Host` set to the honeypot's hostname,
`X-Forwarded-Host` the visited hostname, `X-Forwarded-For` the visitor and `X-NiceTry-Origin-Secret` the trap's
`secret`, within `timeout` seconds (default 30). Its answer goes out with `X-Robots-Tag: noindex, nofollow`,
without its `Server`, `Date` or framing headers; if it can't answer, the origin's own 404 goes out. Any other
status, an allowed client, an upgrade and a bigger body pass untouched. Logged as `honeypot`, `honeypot_trap`,
`honeypot_status`, `honeypot_ms` and `honeypot_outcome` (`served` or `failed`).

A rewrite with `assets` (`{"to","extensions","skip_paths"}`, the Offload Assets add-on; its `find` is empty) moves
URLs of files with those extensions under the base URL `to` (`https://<key>.<asset domain>` in host style,
`https://cdn.<asset domain>/<key>` in path style; a trailing slash is dropped, and a `to` that isn't `https`, has a
query or fragment, or has a path with anything but `A-Za-z0-9._~%/-` turns the rewrite off) in HTML pages only
(never stylesheets or scripts, so what the CDN pulls is byte for byte the origin's): absolute ones on the entry's hosts (`https://`, `//`, and JSON-escaped `https:\/\/`) and
root-relative ones after a quote, `(`, `=` or a srcset comma. Files under `skip_paths`, and service or web worker
scripts (`sw.js`, `*-sw.js`, `*worker*.js`), stay put, and so does any `<script>` or `<link>` tag with an `integrity`
attribute (moved, a same-origin one without `crossorigin` would fail its check); pages under `skip_paths` aren't
rewritten at all.

Offload Assets pulls (from any pull CDN, in either style): a request to the feed's `pull_host` (or one of its `pull_aliases`) carrying `X-NiceTry-Assets` whose SHA-256 is `secret_sha256`,
for `GET`/`HEAD /<key>/<path>` with a listed extension, is served as `<path>` on that key's `site` (through the
site's origin, lockdown included; past the tarpit, gym and verdict; not logged, without cookies), and its answer
carries `Access-Control-Allow-Origin: *` (no credentials), cached with the file: CloudFront's CORS policy drops the
header when the browser sends `Priority`, as Chrome does. An SVG's answer (a `.svg`/`.svgz` path, or
`Content-Type: image/svg+xml`) also carries `Content-Security-Policy: sandbox`, added to any the origin sent, so an
SVG opened as a document can't run script on the CDN's hostname (shared by every site in path style). Anything
else on the pull host or an alias (no secret, a wrong one, a page, an unknown key) is a 404; ACME HTTP challenges
are still answered first, by Caddy before any handler. A visitor's `GET`/`HEAD` for a listed file on a host of a site that's `on` gets
`302 Location: <to><path>?<query>`, or `https://<key>.<domain><path>?<query>` when the site has no usable `to`
(the same test as the rewrite's) (`Cache-Control: public, max-age=3600`), logged with
`bot_tag` `s3_assets`, unless it's a classic script or stylesheet (`Sec-Fetch-Dest` `script`/`style` with
`Sec-Fetch-Mode: no-cors`), which an integrity attribute may pin: that one is served by the site. A visitor's own `X-NiceTry-Assets` never reaches an origin.

## Ingest body

`Content-Type: application/x-ndjson`: one JSON object per line, the same shape as the bucket files and
`App\Actions\Edge\StoreEdgeLogLines` reads them. Each line has `event_id` (uuid v7), `occurred_at`, `host`,
`client_ip`, `method`, `path` (no query string, PINs masked as `:pin`), `status`, `duration_ms`, `user_agent`,
`action`, `bot_tag`, `ja3`, `ja4`, `country_code`, `region`, `city`, `latitude`, `longitude`, `asn` and `as_org`.
Some lines also carry `ja4h`, `via`, `ch_ua`, `ch_platform`, `lang`, `livewire_component`, and the `capture_id` /
`response_*` / `request_*` / `signals` fields, and on a captured response with a flag, `flags`
(`[{"id": <pattern>, "value": <the match>}]`, at most 20, each cut to 500 bytes).

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
