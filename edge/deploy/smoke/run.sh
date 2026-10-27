#!/bin/sh
# Smoke test for the two images on their own, no app needed. It builds them, then runs a stub app
# (stub_app.py), a whoami origin, the edge (NICETRY_ACME=internal) and Vector (direct mode) on a private
# network. It passes when the panel and a site answer through the edge with Server: nginx, and their lines
# reach the stub's /caddy/ingest, redacted, after two refused batches. It cleans up after itself.
#
#   deploy/smoke/run.sh [https-port]     default 18443, on 127.0.0.1
set -eu

here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../.." && pwd)
port=${1:-18443}
p=nts-smoke
token=smoke-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')

cleanup() {
  docker rm -f "$p-vector" "$p-edge" "$p-app" "$p-whoami" >/dev/null 2>&1 || true
  docker volume rm "$p-data" "$p-logs" "$p-dbip" "$p-vector" >/dev/null 2>&1 || true
  docker network rm "$p" >/dev/null 2>&1 || true
}
fail() {
  echo "FAIL: $*" >&2
  docker logs "$p-edge" 2>&1 | tail -20 >&2 || true
  exit 1
}
step() { echo "== $*"; }
trap cleanup EXIT

step "building nicetry-edge:smoke and nicetry-vector:smoke"
docker build -q -t nicetry-edge:smoke "$repo" >/dev/null
docker build -q --target vector -t nicetry-vector:smoke "$repo" >/dev/null

cleanup
docker network create "$p" >/dev/null
docker run -d --name "$p-whoami" --network "$p" --network-alias whoami traefik/whoami:v1.11 >/dev/null
docker run -d --name "$p-app" --network "$p" --network-alias app -e NICETRY_EDGE_TOKEN="$token" \
  -e STUB_FAIL_FIRST_INGESTS=2 -v "$here:/stub:ro" python:3.13-alpine python -u /stub/stub_app.py >/dev/null
docker run -d --name "$p-edge" --network "$p" -p "127.0.0.1:$port:443" \
  -e NICETRY_ACME=internal -e NICETRY_PANEL_HOST=panel.localhost -e NICETRY_APP_URL=http://app:8080 \
  -e NICETRY_EDGE_TOKEN="$token" -v "$p-data:/data" -v "$p-logs:/var/log/caddy" -v "$p-dbip:/opt/dbip" \
  nicetry-edge:smoke >/dev/null

step "waiting for the edge (a first start downloads DB-IP, about 65 MB)"
i=0
until [ "$(docker inspect -f '{{.State.Health.Status}}' "$p-edge")" = healthy ]; do
  [ "$(docker inspect -f '{{.State.Status}}' "$p-edge")" = running ] || fail "the edge stopped"
  i=$((i + 1))
  [ "$i" -lt 200 ] || fail "the edge isn't healthy after 10 minutes"
  sleep 3
done
docker logs "$p-edge" 2>&1 | grep -E '^(nicetry-edge|dbip-fetch):'

step "curl -sk https://panel.localhost:$port/"
out=$(curl -sk --resolve "panel.localhost:$port:127.0.0.1" "https://panel.localhost:$port/" -D -)
echo "$out"
echo "$out" | grep -qi '^server: nginx' || fail "the panel's answer doesn't say Server: nginx"
echo "$out" | grep -q 'panel stub' || fail "the panel's answer didn't come from the app"

step "curl -sk https://panel.localhost:$port/caddy/ask?domain=site.localhost (only for the edge itself)"
out=$(curl -sk --resolve "panel.localhost:$port:127.0.0.1" "https://panel.localhost:$port/caddy/ask?domain=site.localhost" -D -)
echo "$out"
echo "$out" | grep -q '^HTTP/2 404' || fail "the panel's /caddy/* isn't a 404"
echo "$out" | grep -qi '^server: nginx' || fail "the panel's 404 doesn't say Server: nginx"

step "curl -sk https://site.localhost:$port/smoke?pin=1234 (routed by nicetry_origins to whoami)"
out=$(curl -sk --resolve "site.localhost:$port:127.0.0.1" "https://site.localhost:$port/smoke?pin=1234" -D -)
echo "$out"
echo "$out" | grep -qi '^server: nginx' || fail "the site's answer doesn't say Server: nginx"
echo "$out" | grep -q '^Host: site.localhost' || fail "site.localhost didn't reach its origin"

step "Vector, direct mode: the lines reach /caddy/ingest"
docker run -d --name "$p-vector" --network "$p" -e NICETRY_EDGE_TOKEN="$token" \
  -v "$p-logs:/var/log/caddy:ro" -v "$p-dbip:/opt/dbip:ro" -v "$p-vector:/var/lib/vector" \
  nicetry-vector:smoke >/dev/null
i=0
until docker logs "$p-app" 2>&1 | grep -q '"path":"/smoke"'; do
  i=$((i + 1))
  [ "$i" -lt 60 ] || fail "the lines didn't reach /caddy/ingest within a minute"
  sleep 1
done
docker logs "$p-app" 2>&1 | grep -E '^stub-app: (ingest|line)'
lines=$(docker logs "$p-app" 2>&1)
echo "$lines" | grep -q '"host":"panel.localhost"' || fail "no line for the panel"
echo "$lines" | grep '"host":"site.localhost"' | grep -q '"path":"/smoke"' || fail "no redacted line for the site"
if echo "$lines" | grep -q 'pin=1234'; then fail "a query string reached the app"; fi

echo "PASS"
