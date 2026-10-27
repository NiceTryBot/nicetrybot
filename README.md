# NiceTryBot

NiceTryBot puts an edge in front of your websites and gives you a control panel to see and handle the
bots that reach them.

- **The edge** is [Caddy](https://caddyserver.com) with NiceTryBot's modules. It serves your sites over
  HTTPS (certificates on demand), proxies each domain to its origin, takes TLS and header fingerprints
  (JA3, JA4, JA4H) of every request, applies your rules, and can send a visitor to the "gym": a page of
  checks (proof of work, a browser check, a waiting room, a code on screen) before the site.
- **The control panel** is a Laravel app. It stores every request the edge logs and shows them on
  Activity, Analytics and a 404 report. You write rules there that tag or ban clients, build gym
  routines, manage domains, set how long visitor data is kept, and invite your team. AI agents can use it
  too, through its MCP endpoint, with an API token an administrator makes.

Nothing in the stack calls home. The only outbound calls are for certificates (Let's Encrypt, or ZeroSSL
when it refuses), the monthly DB-IP Lite databases, image pulls, and, only if you enter a paid add-on's
licence key, a daily check of that key.

## Install

On a fresh Ubuntu or Debian server, with DNS for the panel's hostname pointing at it:

```sh
curl -fsSL https://nicetrybot.com/install.sh | sudo bash
```

The installer sets up Docker if needed, writes `/opt/nicetrybot/compose.yml` and `.env` with new secrets,
starts the stack, creates the first administrator and prints the DNS records to set. The script is
[`deploy/install.sh`](deploy/install.sh) in this repository.

## Docker Compose

The whole stack (edge, Vector, the panel, a queue worker, the scheduler, Postgres and Valkey) is one
Compose project in [`deploy/`](deploy). To run it yourself, or from a checkout for development:

```sh
cd deploy
cp .env.example .env          # then fill in the settings it describes
docker compose up -d          # published images
docker compose up --build -d  # or build the images from this checkout
docker compose exec app php artisan nicetry:install
```

[`deploy/README.md`](deploy/README.md) covers the settings, managed Postgres and Valkey, local runs
and updates. [`edge/deploy/README.md`](edge/deploy/README.md) covers the edge and Vector images.

**Docker, through the installer or Compose, is the supported way to run NiceTryBot.** It sets everything a
self-hosted panel needs. If you run the app another way, set `NICETRY_EDGE_HOSTNAME`, `NICETRY_EDGE_ADDRESSES`,
`NICETRY_PREVIEW_ZONE`, `NICETRY_REGISTRATION` and the `EDGE_LOGS_*` keys yourself; unset, they're safe but
bare (no A record target, no previews, sign-up by invitation). See [Without Docker](deploy/README.md#without-docker).

## Releases

Each release is a `vX.Y.Z` tag here, with its notes and the installer's files on the
[releases page](https://github.com/NiceTryBot/nicetrybot/releases). Its images are
`ghcr.io/nicetrybot/app`, `ghcr.io/nicetrybot/edge` and `ghcr.io/nicetrybot/vector`, tagged `X.Y.Z` (and
`latest`), for amd64 and arm64. `NICETRY_VERSION` in `.env` picks one (`1.2.0`, or `latest`); running the
installer again updates to it. Maintainers cut a release by pushing an annotated tag, whose message becomes
the release notes:

```sh
git tag -a v1.2.0 -m "What changed" && git push origin v1.2.0
```

[`.github/workflows/release.yml`](.github/workflows/release.yml) runs the tests on the tag, builds and pushes
the images, then creates the release. A tag like `v1.2.0-beta.1` makes a pre-release, which `latest` skips.

## What's in this repository

| Path | What it is |
|---|---|
| `/` | The control panel: a Laravel 13 app with Livewire 4 (`app/`, `resources/`, `routes/`, `database/`, `tests/`). |
| `gym/obstacles/` | The gym's built-in checks. |
| `packages/` | The free add-ons (below), as Composer path packages. |
| `edge/` | The edge: the Caddy modules in Go (`edge/caddy/nicetry-capture`), the `Dockerfile` for the edge and Vector images, and the templated Caddyfile and Vector config (`edge/deploy`). |
| `deploy/` | The self-host stack: `compose.yml`, `.env.example`, the app's `Dockerfile` and `install.sh`. |

Development needs PHP 8.5, Composer, Node 22 and, for the edge, Go 1.26:

```sh
composer setup                # install, .env, key, migrations, assets
composer ci:check             # Pint, PHPStan and the tests, as CI runs them
cd edge/caddy/nicetry-capture && go test ./...
```

## Add-ons

Add-ons are Composer packages the panel finds on its own.

- **Free, included here:**
  - Lockdown: put sites behind a maintenance page at the edge in an emergency.
  - Privacy mode: swap real hostnames for stand-ins while you share your screen.
  - Rule packs: curated sets of rules and gym routines.
- **Paid, not in this repository:** Captchas, Fingerprints, Origins (one backend serving many hostnames),
  Request monitor and Threats, from [nicetrybot.com/marketplace](https://nicetrybot.com/marketplace).
  - They install with Composer from NiceTryBot's private repository and need a licence key. List them in
    `NICETRY_ADDONS`, with the Composer token from your account, in `.env`: the installer then builds
    them into the app's image. Without Docker, `php artisan addons:install` does the same. See
    [Add-ons](deploy/README.md#add-ons).
  - Without a key, a paid add-on runs for 14 days and then turns off until a key is entered.
  - The core works without any of them.

## How many domains

An installation protects 10 domains for free. Each More domains pack ($50 at
[nicetrybot.com/marketplace](https://nicetrybot.com/marketplace/more-domains)) adds 100 more: paste its
key in Admin → Add-ons. Packs stack, and they don't expire. Hostnames behind an Origins origin don't
count. It's a simple limit and we'd rather trust you than lock it down; please keep to it.

## Contributing

Issues and pull requests are welcome. [CONTRIBUTING.md](CONTRIBUTING.md) says how, including the
one-time contributor agreement ([CLA.md](CLA.md)) a bot asks for on your first pull request.

Found a security problem? Please don't open an issue. Email hello@nicetrybot.com with "security" in
the subject, and give us a chance to fix it before it's public.

## Licence

NiceTryBot is available under the [Elastic License 2.0](LICENSE). In plain English:

- You can use it, copy it, change it and run it on your own servers, for yourself or your business.
- You can't offer it to others as a hosted or managed service.
- You can't remove, disable or get around the licence-key checks for paid add-ons.
- You have to keep the licence and copyright notices, and pass the licence on with any copy you share.

This summary isn't the licence. [`LICENSE`](LICENSE) has the full terms.
