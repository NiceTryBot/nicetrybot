#!/bin/bash
# The images the release's compose.yml runs, pulled now so a new Droplet starts in about a minute. The
# installer still pulls on first run, which only fetches what changed since the image was built. The
# Node image is what compose.addons.yml builds the front end with when add-ons are listed.
set -euo pipefail

for image in \
  ghcr.io/nicetrybot/app:latest \
  ghcr.io/nicetrybot/edge:latest \
  ghcr.io/nicetrybot/vector:latest \
  postgres:17 \
  valkey/valkey:8 \
  node:22-bookworm-slim; do
  docker pull --quiet "$image"
done

docker image ls --format '{{.Repository}}:{{.Tag}}  {{.Size}}'
