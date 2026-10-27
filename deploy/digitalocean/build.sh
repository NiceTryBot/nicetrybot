#!/usr/bin/env bash
# Builds NiceTryBot's DigitalOcean 1-Click snapshot with Packer, run from its official Docker image, so
# there's nothing to install but Docker. Needs a DigitalOcean API token in the environment:
#
#   DIGITALOCEAN_TOKEN=dop_v1_... ./build.sh [release]      (release: default 0.1.0, for the name)
#
# The snapshot is named nicetrybot-<release>-<UTC time> in the account the token belongs to. The build
# Droplet (tagged nicetrybot-image-build) is deleted when Packer finishes.
set -euo pipefail

: "${DIGITALOCEAN_TOKEN:?Set DIGITALOCEAN_TOKEN to a DigitalOcean API token (read and write).}"
release=${1:-0.1.0}
here=$(cd "$(dirname "$0")" && pwd)
packer=hashicorp/packer:1.16.1

packer() {
  docker run --rm -e DIGITALOCEAN_TOKEN -e PACKER_PLUGIN_PATH=/plugins \
    -v nicetrybot-packer-plugins:/plugins -v "$here":/build -w /build "$packer" "$@"
}

packer init .
packer validate -var "version=$release" .
packer build -var "version=$release" .
