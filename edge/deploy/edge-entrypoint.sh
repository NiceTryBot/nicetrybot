#!/bin/sh
# NiceTryBot edge container: checks the settings, fetches DB-IP, validates the Caddyfile, runs Caddy.
# Nothing is rendered: Caddy reads the settings from the environment itself ({$VAR} and {env.VAR}).
#
#   run       the default
#   validate  check the settings and the Caddyfile, then stop
#   reload    apply an edited (mounted) Caddyfile to the running Caddy, with the settings worked out
#             here: docker compose exec edge nicetry-edge reload
#
# Anything else runs as given (sh, caddy version). Started as root, it hands the volumes to the caddy
# user and carries on as caddy.
set -eu

caddyfile=/etc/caddy/Caddyfile

say() { echo "nicetry-edge: $*" >&2; }
fail() {
  say "$*"
  exit 1
}

case "${1:-run}" in
  run | validate | reload) ;;
  *) exec "$@" ;;
esac

if [ "$(id -u)" = 0 ]; then
  for dir in /data /config /var/log/caddy /opt/dbip; do
    mkdir -p "$dir"
    [ "$(stat -c %u "$dir")" = "$(id -u caddy)" ] || chown -R caddy:caddy "$dir"
  done
  exec su-exec caddy "$0" "${1:-run}"
fi

# Laravel's .env writes "no value" as null; the same .env feeds this container.
blank() {
  case "${1:-}" in
    '' | null | '(null)' | empty | '(empty)') return 0 ;;
    *) return 1 ;;
  esac
}

# Percent-decoding for the user and password in a Redis URL.
urldecode() {
  printf '%b' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/%\([0-9A-Fa-f][0-9A-Fa-f]\)/\\x\1/g')"
}

# Where certificates go with NICETRY_CERT_STORAGE=redis: NICETRY_CERT_REDIS_URL, else the app's own
# REDIS_URL, else REDIS_HOST/PORT/USERNAME/PASSWORD/DB. TLS for rediss://, a tls:// host or REDIS_SCHEME=tls.
redis_settings() {
  tls=false
  url=${NICETRY_CERT_REDIS_URL:-}
  blank "$url" && url=${REDIS_URL:-}
  if ! blank "$url"; then
    case $url in
      rediss://* | tls://*) tls=true ;;
      redis://* | tcp://*) ;;
      *) fail "REDIS_URL must start with redis:// or rediss:// (got ${url%%://*}://...)" ;;
    esac
    # [user[:password]@]host[:port][/db][?options]
    rest=${url#*://}
    userinfo=
    case $rest in *@*)
      userinfo=${rest%@*}
      rest=${rest##*@}
      ;;
    esac
    rest=${rest%%\?*}
    db=${rest#*/}
    [ "$db" = "$rest" ] && db=
    hostport=${rest%%/*}
    case $userinfo in
      *:*) user=${userinfo%%:*} pass=${userinfo#*:} ;;
      *) user=$userinfo pass= ;;
    esac
    user=$(urldecode "$user")
    pass=$(urldecode "$pass")
  else
    blank "${REDIS_HOST:-}" && fail "NICETRY_CERT_STORAGE=redis needs REDIS_URL or REDIS_HOST"
    hostport=$REDIS_HOST
    case $hostport in
      rediss://* | tls://*) tls=true hostport=${hostport#*://} ;;
      redis://* | tcp://*) hostport=${hostport#*://} ;;
    esac
    case "${REDIS_SCHEME:-}" in tls | rediss) tls=true ;; esac
    port=${REDIS_PORT:-}
    blank "$port" || hostport="$hostport:$port"
    user=${REDIS_USERNAME:-}
    pass=${REDIS_PASSWORD:-}
    db=${REDIS_DB:-}
  fi

  blank "$user" && user=
  blank "$pass" && pass=
  blank "$db" && db=0
  case $hostport in
    *\]:* | [!\[]*:*) ;;
    *) hostport="$hostport:6379" ;;
  esac
  case $db in *[!0-9]* | '') fail "the Redis database must be a number (got $db)" ;; esac
  case "${NICETRY_CERT_REDIS_TLS:-}" in true | false) tls=$NICETRY_CERT_REDIS_TLS ;; esac

  export NICETRY_CERT_REDIS_ADDRESS="$hostport" NICETRY_CERT_REDIS_USERNAME="$user" \
    NICETRY_CERT_REDIS_PASSWORD="$pass" NICETRY_CERT_REDIS_DB="$db" NICETRY_CERT_REDIS_TLS="$tls" \
    NICETRY_CERT_REDIS_CA="${NICETRY_CERT_REDIS_CA:-}"
  [ "$tls" = true ] && how="over TLS" || how="without TLS"
  say "certificates in Redis at $hostport, db $db, $how"
}

settings() {
  host=${NICETRY_PANEL_HOST:-}
  [ -n "$host" ] || fail "NICETRY_PANEL_HOST is not set (the control panel's hostname, e.g. panel.example.com)"
  case $host in
    *[!A-Za-z0-9.-]*) fail "NICETRY_PANEL_HOST must be a bare hostname like panel.example.com (got $host)" ;;
  esac

  NICETRY_APP_URL=${NICETRY_APP_URL:-http://app:8080}
  export NICETRY_APP_URL="${NICETRY_APP_URL%/}"
  case $NICETRY_APP_URL in
    http://* | https://*) ;;
    *) fail "NICETRY_APP_URL must be the app's URL inside the network, like http://app:8080" ;;
  esac

  token=${NICETRY_EDGE_TOKEN:-}
  [ -n "$token" ] || fail "NICETRY_EDGE_TOKEN is not set (the token the app's /caddy/* endpoints expect)"
  case $token in
    *[!A-Za-z0-9._-]*) fail "NICETRY_EDGE_TOKEN may only hold letters, digits, dots, dashes and underscores" ;;
  esac
  [ "${#token}" -ge 32 ] || say "NICETRY_EDGE_TOKEN is short; use 32 characters or more"

  export NICETRY_ACME="${NICETRY_ACME:-letsencrypt}"
  case $NICETRY_ACME in
    letsencrypt) [ -n "${NICETRY_ACME_EMAIL:-}" ] || fail "NICETRY_ACME=letsencrypt needs NICETRY_ACME_EMAIL (or NICETRY_ACME=internal)" ;;
    internal) ;;
    *) fail "NICETRY_ACME must be letsencrypt or internal (got $NICETRY_ACME)" ;;
  esac

  export NICETRY_CERT_STORAGE="${NICETRY_CERT_STORAGE:-file}"
  case $NICETRY_CERT_STORAGE in
    file) ;;
    redis) redis_settings ;;
    *) fail "NICETRY_CERT_STORAGE must be file or redis (got $NICETRY_CERT_STORAGE)" ;;
  esac

  export NICETRY_BLOCK_MULTIPART="${NICETRY_BLOCK_MULTIPART:-off}"
  case $NICETRY_BLOCK_MULTIPART in
    on | off) ;;
    *) fail "NICETRY_BLOCK_MULTIPART must be on or off (got $NICETRY_BLOCK_MULTIPART)" ;;
  esac
}

settings

case "${1:-run}" in
  validate) exec caddy validate --config "$caddyfile" --adapter caddyfile ;;
  reload) exec caddy reload --config "$caddyfile" --adapter caddyfile ;;
esac

# DB-IP: fetched when missing or stale, then looked at daily, or hourly while it can't be fetched.
# Caddy waits for the first try (up to NICETRY_DBIP_WAIT seconds), so a first start has places from
# its first request; without them the edge still runs and visitors' places are unknown.
if [ "${NICETRY_DBIP:-on}" = on ]; then
  tried=/tmp/dbip-tried
  rm -f "$tried"
  (
    while :; do
      if dbip-fetch; then next=86400; else
        next=3600
        say "no DB-IP for now; trying again in an hour"
      fi
      : >"$tried"
      sleep "$next"
    done
  ) &
  waited=0
  while [ ! -e "$tried" ] && [ "$waited" -lt "${NICETRY_DBIP_WAIT:-300}" ]; do
    sleep 1
    waited=$((waited + 1))
  done
  [ -e "$tried" ] || say "DB-IP is still downloading; starting without it"
fi

out=$(mktemp)
if ! caddy validate --config "$caddyfile" --adapter caddyfile >"$out" 2>&1; then
  cat "$out" >&2
  fail "$caddyfile doesn't validate"
fi
rm -f "$out"

say "panel https://$NICETRY_PANEL_HOST -> $NICETRY_APP_URL, certificates: $NICETRY_ACME, storage: $NICETRY_CERT_STORAGE"
exec caddy run --config "$caddyfile" --adapter caddyfile
