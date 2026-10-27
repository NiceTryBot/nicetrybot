# Self-hosting NiceTryBot

Everything NiceTryBot needs, on one server, with Docker Compose: the edge in front of your sites and the
control panel behind it. Nothing in the stack calls home. The only calls out are for certificates (Let's
Encrypt, or ZeroSSL when it refuses), the monthly DB-IP Lite databases, image pulls, and, only if you
enter a paid add-on's licence key, a daily check of that key.

## What runs

| Service     | Image                         | What it does |
|-------------|-------------------------------|--------------|
| `edge`      | `ghcr.io/nicetrybot/edge`        | Caddy with the NiceTryBot modules: TLS, fingerprints, rules, the gym. Publishes ports 80 and 443. Serves the panel at `https://$NICETRY_PANEL_HOST` and every verified domain, with certificates on demand. |
| `vector`    | `ghcr.io/nicetrybot/vector`      | Tails the edge's access log, adds DB-IP places, redacts, and sends the lines to the app (or to a bucket). |
| `app`       | `ghcr.io/nicetrybot/app`         | The control panel and the edge's API (PHP-FPM + nginx on 8080, reachable only inside the stack). Runs the database migrations each time it starts. |
| `worker`    | same as `app`                 | `php artisan queue:work` |
| `scheduler` | same as `app`                 | `php artisan schedule:work` |
| `postgres`  | `postgres:17`                 | The database (Compose profile `postgres`). |
| `valkey`    | `valkey/valkey:8`             | Cache, queue, sessions and locks (Compose profile `valkey`). |

Volumes: `edge-data` (certificates, lockdown state), `edge-logs` (the access log, shared with Vector),
`edge-dbip` (DB-IP files, shared with Vector), `vector-data` (Vector's checkpoints and buffer),
`postgres-data`, `valkey-data`, `app-storage` (the app's `storage/app`).

The images are built from this repository: the app's from `deploy/Dockerfile` (with the repository
root as the context), the edge's and Vector's from `edge/Dockerfile` (an `edge` and a `vector` target, with `edge/` as the context).

## Installing on a server

On a fresh Ubuntu or Debian server, with DNS for the panel's hostname pointing at it:

```sh
curl -fsSL https://nicetrybot.com/install.sh | sudo bash
```

It installs Docker if needed, writes `/opt/nicetrybot/compose.yml` and `.env` (with new secrets), starts
the stack, creates the first administrator and prints the DNS records to set. For cloud-init:

```sh
curl -fsSL https://nicetrybot.com/install.sh | sudo bash -s -- --unattended \
  --panel-host=panel.example.com --acme-email=you@example.com \
  --admin-email=you@example.com
```

`--admin-password=` sets the first administrator's password; without it a random one is printed once.
`--version=v1.2.0` runs a given release; `--dir=` installs somewhere other than `/opt/nicetrybot`;
`--compose-only` only writes `./nicetrybot/compose.yml` and `.env`, for running them yourself.

Running it yourself needs Docker Engine 27 or newer with the Compose plugin. Older engines hand every
IPv6 visitor to the edge as the same internal address.

To create the first administrator by hand (it does nothing once one exists, besides showing the DNS
records again):

```sh
docker compose exec app php artisan nicetry:install
```

## Running it locally, for development

From a checkout of this repository (the edge's source is `edge/`, which `compose.yml` builds from by
default; set `NICETRY_EDGE_SRC` in `.env` to build it from somewhere else):

```sh
cd deploy
cp .env.example .env
```

Then set in `.env`: `NICETRY_PANEL_HOST=panel.localhost`, `NICETRY_ACME=internal` (Caddy's own CA; use
`curl -k`), `NICETRY_EDGE_ADDRESSES=127.0.0.1`, and the three secrets install.sh would make:

```sh
sed -i.bak \
  -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" \
  -e "s|^NICETRY_EDGE_TOKEN=.*|NICETRY_EDGE_TOKEN=$(openssl rand -hex 32)|" \
  .env && rm .env.bak
```

Build and start everything, with a test origin and the app's port on 127.0.0.1:8080:

```sh
docker compose -f compose.yml -f compose.test.yml up --build -d
docker compose exec app php artisan nicetry:install
curl -sk https://panel.localhost/up
```

`*.localhost` resolves to 127.0.0.1 on its own in curl and browsers. If ports 80/443 (or 8080) are taken
on your machine (Laravel Herd, Valet), set `NICETRY_HTTP_PORT`, `NICETRY_HTTPS_PORT` (and
`NICETRY_APP_TEST_PORT`) in `.env` and add the port to the URLs.

To see a site through the edge: set `NICETRY_VERIFY_DNS=false` in `.env` (`site.localhost` has no public
DNS to hold the ownership TXT record) and run the `up` again, then in the panel add the domain
`site.localhost` with the origin `http://whoami` and verify it. `curl -skI https://site.localhost/` then answers with `Server: nginx`,
and the request shows on Activity a few seconds later.

`docker compose down -v` removes the containers and every volume (the database too).

## Settings

Everything is in `.env` next to `compose.yml`; `.env.example` explains each key. The main ones:

| Key | What it's for |
|-----|---------------|
| `NICETRY_PANEL_HOST` | The panel's hostname. The edge serves it with its own certificate. |
| `NICETRY_ACME`, `NICETRY_ACME_EMAIL` | `letsencrypt` (with ZeroSSL as the fallback; needs the email) or `internal`. |
| `NICETRY_EDGE_TOKEN` | The bearer token the edge and Vector show the app's `/caddy/*` endpoints. |
| `NICETRY_EDGE_HOSTNAME`, `NICETRY_EDGE_ADDRESSES` | What the Domains page tells people to point DNS at (CNAME target; A record addresses). The hostname defaults to the panel's. |
| `NICETRY_PREVIEW_ZONE` | A wildcard zone for preview hostnames; empty turns previews off. |
| `NICETRY_CERT_STORAGE` | `file`, or `redis` for several edge nodes sharing certificates through Valkey. |
| `EDGE_LOGS_BUCKET` and `EDGE_LOGS_*` | Empty: every log line goes straight to the app. Set: an S3-compatible bucket, imported every minute. |
| `COMPOSE_PROFILES` | Which data stores run here: `postgres,valkey` by default. |
| `APP_KEY`, `DB_*`, `REDIS_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `FILESYSTEM_DISK`, `AWS_*`, `MAIL_*` | The usual Laravel settings. Any other Laravel or `PHP_*` setting can be added too. |
| `NICETRY_VERSION` | The release to run, without the v (`1.2.0` for v1.2.0; also the images' tag), or `latest`. |
| `NICETRY_ADDONS`, `NICETRY_COMPOSER_TOKEN` | Add-ons from the marketplace, built into the app's image here (see [Add-ons](#add-ons)). |

After editing `.env`, apply it with `nicetrybot apply` (or `docker compose up -d`).

## Add-ons

Lockdown, Privacy mode and Rule packs come with NiceTryBot. Add-ons from
[nicetrybot.com/marketplace](https://nicetrybot.com/marketplace) install with Composer from its private
repository, built into the app's image on the server:

```sh
nicetrybot addons add captchas threats     # asks for the Composer token from your account the first time
nicetrybot addons remove threats
nicetrybot addons                          # what's built in
```

They're kept in `.env` as `NICETRY_ADDONS="nicetry/captchas nicetry/threats"` with
`NICETRY_COMPOSER_TOKEN=ntbc_…`. Or `NICETRY_ADDONS=all` (`nicetrybot addons add all`): every add-on the token may install, as the marketplace's repository lists them,
so a later purchase comes in on the next run and a refunded one goes. Building the image wants about 2 GB
of memory; on a smaller server the installer adds a 2 GB swap file first.

With any listed, the installer sets `COMPOSE_FILE=compose.yml:compose.addons.yml` and the app's image is
built on this server, on top of the release's: `php artisan addons:install` requires the add-ons, and
the front end is built again with their styles. The app, worker and scheduler run that image
(`nicetrybot-app-addons`). The token is a build secret, so it isn't in the image, its layers or its
history, and the running containers don't get it. Each `nicetrybot update` builds it again, which also
brings the add-ons' newest versions your token may have. Taking an add-on off the list removes it; emptying the list puts the stack back on the
release's image. Paid add-ons then need their licence keys, in Admin → Add-ons.

Without the installer, the same by hand (Docker Compose 2.24.4 or newer):

```sh
echo "COMPOSE_FILE=compose.yml:compose.addons.yml" >> .env
docker pull ghcr.io/nicetrybot/app:latest     # the release's image ($NICETRY_APP_IMAGE, if set)
docker compose up -d --build
```

From a checkout, build the release's image first and point `NICETRY_APP_IMAGE` at it:

```sh
docker build -f deploy/Dockerfile -t nicetrybot-app:dev .
```

## Without Docker

Docker, through the installer or `compose.yml`, is the supported way to run NiceTryBot, and it sets all of
the keys below for a self-hosted panel. Run the app any other way and set them yourself. Left unset, the
app's defaults are safe but bare: the CNAME target is the panel's own host, apex domains get no A record to
point at, there are no previews, sign-up is by invitation only, and there's no logs bucket.

| Key | Set it to |
|-----|-----------|
| `NICETRY_EDGE_HOSTNAME` | The hostname your sites' CNAME records point at: your edge's (the panel's own when unset). |
| `NICETRY_EDGE_ADDRESSES` | Your edge's public IPv4 addresses, comma-separated, for apex domains' A records. |
| `NICETRY_PREVIEW_ZONE` | A zone of yours whose wildcard record points at your edge, or empty (the default) for no previews. |
| `NICETRY_REGISTRATION` | `invitations` (the default: only invited emails), `closed` (accounts only from `php artisan nicetry:install`) or `open`. |
| `EDGE_LOGS_BUCKET`, `EDGE_LOGS_REGION`, `EDGE_LOGS_ENDPOINT`, `EDGE_LOGS_KEY`, `EDGE_LOGS_SECRET`, `EDGE_LOGS_PATH_STYLE` | Your own bucket's, or `EDGE_LOGS_BUCKET=` (empty) for none, so the edge sends every line straight to the app. |

The edge and Vector still have to run in front of the app, as `compose.yml` runs them.

Add-ons from the marketplace: set `NICETRY_ADDONS` and `NICETRY_COMPOSER_TOKEN` in the app's `.env` (see
[Add-ons](#add-ons)) and run `php artisan addons:install`. It adds the marketplace's Composer repository
to `composer.json`, requires the listed add-ons, removes ones taken off the list, and leaves the
token out of every file. Then run the migrations and build the front end.

`addons:install` changes `composer.json` and `composer.lock`, so put them back before each update and run
it again after, and a `git pull` never conflicts:

```sh
git checkout composer.json composer.lock
git pull
composer install --no-dev --optimize-autoloader
php artisan addons:install
php artisan migrate --force
npm ci && npm run build
php artisan optimize
```

## Managed Postgres and Valkey

Each data store is a choice. To use a managed one, point its settings at it and leave its container out
of `COMPOSE_PROFILES`; the presets at the bottom of `.env.example` show DigitalOcean (Managed Postgres,
Managed Valkey, Spaces) and AWS (RDS, ElastiCache, S3).

- **Postgres** (DigitalOcean, RDS/Aurora, Neon, your own): `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD`, and `DB_SSLMODE=require`, or a single `DB_URL`. Postgres only.
- **Redis-compatible** (DigitalOcean Managed Valkey, ElastiCache, Upstash, your own): `REDIS_URL`
  (`rediss://user:password@host:port` for TLS) or `REDIS_HOST`/`REDIS_PORT`/`REDIS_USERNAME`/
  `REDIS_PASSWORD`. A service with a single database needs `REDIS_CACHE_DB=0`.
- **No Redis at all**: `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`,
  and `COMPOSE_PROFILES=postgres`. The cache, its locks, the queue and sessions then live in Postgres.

For example, keeping Postgres here and renting Valkey:

```sh
COMPOSE_PROFILES=postgres
REDIS_URL=rediss://default:secret@cache.example.net:25061
```

then `docker compose up -d --remove-orphans` (which also stops the container that's no longer wanted).
Moving existing data between stores is up to you (`pg_dump` / `pg_restore` for Postgres).

## Updating

```sh
nicetrybot update                   # to the newest release
nicetrybot update --version=v1.2.0  # to (or back to) a given one
nicetrybot rollback                 # undo the last update, database included
```

`update` says which release it goes from and to, backs up the bundled Postgres to
`/opt/nicetrybot/backups` (the newest three are kept; `--no-backup` skips it), downloads the release's
`compose.yml`, keeps `.env` and pins `NICETRY_VERSION` to the release, pulls the images, builds the add-ons
in again, and restarts what changed (the app runs any new migrations as it starts). If the app doesn't
come up healthy it goes back to the release it came from on its own, with the database too when
migrations had run. Docker's output goes to `/opt/nicetrybot/.updates/update.log`.

`rollback` puts back the release, `compose.yml` and add-ons from before the last update, and the database
from its backup: what changed in the panel since the update is lost, so it asks first (`--yes` doesn't).
A managed database isn't backed up or restored here; use your provider's backups.

Running the installer again on a running server does the same as `nicetrybot update`. After changing
`.env` by hand, `nicetrybot apply` starts the stack again with it. Administrators see a notice in Admin when
a newer release is out (`NICETRY_UPDATE_CHECK=false` turns the daily check off).

The rest of `nicetrybot`: `status`, `version`, `logs [service]`, and `artisan …` for an Artisan command in
the app (`nicetrybot artisan admin:grant you@example.com`). `nicetrybot help` lists them.

By hand, without the command:

```sh
cd /opt/nicetrybot
docker compose pull
docker compose up -d --remove-orphans
```

With add-ons, also pull the release's app image and build again: `docker pull ghcr.io/nicetrybot/app:latest`,
then `docker compose up -d --build --remove-orphans`.

`NICETRY_VERSION` in `.env` picks the release (`1.2.0`, or `latest` to follow the newest); `nicetrybot
update` and `--version=v1.2.0` on the installer set it. Releases, their notes and their files are at
[github.com/NiceTryBot/nicetrybot/releases](https://github.com/NiceTryBot/nicetrybot/releases).
