#!/usr/bin/env bash
# Build the caddy-nicetry binary: Caddy plus Redis cert storage, JA3/JA4 fingerprinting and the
# nicetry-capture module (nicetry_capture for response captures, nicetry_gym for the Bot gym hand-off,
# nicetry_headers for per-domain Server headers, nicetry_ja4h for header fingerprints, nicetry_tail
# for live tail, nicetry_origins for routing each domain to its origin, nicetry_php_guard for refusing
# PHP's session upload progress field, nicetry_guard for proxy loops and dot-segment paths, the
# nicetry_origin transport that only dials public addresses, and nicetry_ask for on-demand TLS).
# Run on the edge node (arm64) from this folder. Then validate with the env loaded and swap the binary:
#
#   sudo bash -c 'set -a; . /etc/caddy/nicetry.env; set +a; ./caddy-nicetry validate --config Caddyfile --adapter caddyfile'
#   sudo cp -p /usr/bin/caddy /usr/bin/caddy.bak-$(date +%Y%m%d%H%M%S)
#   sudo install -m 0755 ./caddy-nicetry /usr/bin/caddy && sudo systemctl restart caddy
set -euo pipefail
cd "$(dirname "$0")"

export PATH=/usr/local/go/bin:$HOME/go/bin:$PATH

(cd nicetry-capture && go test ./...)

xcaddy build v2.11.7 \
  --with github.com/pberkel/caddy-storage-redis@v1.8.2 \
  --with github.com/josuebrunel/caddy-ja3ja4@v0.2.0 \
  --with nicetrybot.com/caddy/capture="$PWD/nicetry-capture" \
  --output ./caddy-nicetry
