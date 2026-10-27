# Contributing to NiceTryBot

Thanks for helping. Bug reports, fixes, docs and new ideas are all welcome.

## Before you start

- **Bugs:** open an issue with what you did, what you expected and what happened. Logs and the
  version you run (`NICETRY_VERSION` in your `.env`) help a lot.
- **Bigger changes:** open an issue first, so we can agree on the approach before you spend time on it.
- **Security problems:** don't open an issue. Email hello@nicetrybot.com with "security" in the
  subject.
- **Paid add-ons** (Captchas, Fingerprints, Origins, Request monitor, Threats) aren't in this
  repository. Report problems with them by email.

## Working on it

You need PHP 8.5, Composer, Node 22 and, for the edge, Go 1.26.

```sh
composer setup          # install, .env, key, migrations, assets
composer dev            # the panel, the queue and Vite, for working on it
composer ci:check       # Pint, PHPStan and the tests, exactly as CI runs them
cd edge/caddy/nicetry-capture && go test ./...
```

The whole stack, edge included, runs with Docker Compose from a checkout; see
[deploy/README.md](deploy/README.md).

## Pull requests

- Keep each one to a single change, with tests for what it fixes or adds.
- `composer ci:check` must pass. New PHPStan errors aren't accepted (the old ones are in
  `phpstan-baseline.neon`).
- Follow the code around you: its naming, its comments, its tests.
- Some tests skip because they need a paid add-on. That's expected.

## The contributor agreement

NiceTryBot is source-available under the [Elastic License 2.0](LICENSE) and sold alongside paid
add-ons, so we ask every contributor to agree to the [Contributor License Agreement](CLA.md) once.
A bot comments on your first pull request; reply with the sentence it gives you and you're done.
You keep the copyright in your work.

## How changes get here

NiceTryBot is developed in a private repository and published here. When we merge your pull request,
it's carried into the private repository with your name on it, and comes back out with the next
release. Your commit stays in this repository's history.
