#!/bin/sh
# NiceTryBot's Vector: picks the log mode, links the DB-IP files, then runs Vector.
#
#   EDGE_LOGS_BUCKET empty  vector.yaml + direct.yaml (every line to the app's caddy/ingest)
#   EDGE_LOGS_BUCKET set    vector.yaml + bucket.yaml (the bucket, plus live-tail lines to the app)
#
# Arguments are passed on to Vector. (`vector validate` can't check these files: it doesn't fetch
# secrets, so SECRET[...] stays text where a boolean belongs. Starting Vector is the check.)
set -eu

conf=/etc/vector/nicetry
dbip=/opt/dbip
links=/run/nicetry/dbip

say() { echo "nicetry-vector: $*" >&2; }

# Settings handed to Vector by secret.sh.
NICETRY_APP_URL=${NICETRY_APP_URL:-http://app:8080}
export NICETRY_APP_URL="${NICETRY_APP_URL%/}"
if [ -z "${NICETRY_EDGE_TOKEN:-}" ]; then
  say "NICETRY_EDGE_TOKEN is not set (the token the app's /caddy/* endpoints expect)"
  exit 1
fi
# Secrets land in the config as text, so keep to what the edge accepts too.
case $NICETRY_EDGE_TOKEN in
  *[!A-Za-z0-9._-]*)
    say "NICETRY_EDGE_TOKEN may only hold letters, digits, dots, dashes and underscores"
    exit 1
    ;;
esac

if [ -n "${EDGE_LOGS_BUCKET:-}" ]; then
  mode=bucket
  export EDGE_LOGS_REGION="${EDGE_LOGS_REGION:-us-east-1}"
  # AWS when no endpoint is given: its regional endpoint, bucket in the hostname. Anything else
  # (Spaces, MinIO, R2) takes the bucket in the path unless EDGE_LOGS_PATH_STYLE says otherwise.
  if [ -n "${EDGE_LOGS_ENDPOINT:-}" ]; then
    export EDGE_LOGS_PATH_STYLE="${EDGE_LOGS_PATH_STYLE:-true}"
  else
    export EDGE_LOGS_ENDPOINT="https://s3.$EDGE_LOGS_REGION.amazonaws.com"
    export EDGE_LOGS_PATH_STYLE="${EDGE_LOGS_PATH_STYLE:-false}"
  fi
  # Static keys, or with none the AWS default chain (the instance's or task's role).
  if [ -n "${EDGE_LOGS_KEY:-}" ]; then
    export AWS_ACCESS_KEY_ID="$EDGE_LOGS_KEY" AWS_SECRET_ACCESS_KEY="${EDGE_LOGS_SECRET:-}"
  fi
  say "logs go to bucket $EDGE_LOGS_BUCKET ($EDGE_LOGS_ENDPOINT), live-tail lines to $NICETRY_APP_URL"
else
  mode=direct
  say "logs go to $NICETRY_APP_URL/caddy/ingest"
fi

# An empty MaxMind DB: one search-tree node whose records both mean "not found", the 16-byte data
# separator, then the metadata map (format 2.0, IPv6 tree, 1 node, 24-bit records). Stands in for a
# DB-IP file the edge hasn't fetched yet, so Vector runs and places are simply unknown.
empty_mmdb() {
  printf '\000\000\001\000\000\001'
  printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000'
  printf '\253\315\357MaxMind.com\351'
  printf '\133binary_format_major_version\241\002'
  printf '\133binary_format_minor_version\240'
  printf '\113build_epoch\000\002'
  printf '\115database_type\105Empty'
  printf '\113description\340'
  printf '\112ip_version\241\006'
  printf '\111languages\000\004'
  printf '\112node_count\301\001'
  printf '\113record_size\241\030'
}

# Points each link at the edge's file when there is one, else at the empty database.
link_dbip() {
  for kind in city asn; do
    file=$dbip/dbip-$kind-lite.mmdb
    [ -s "$file" ] || file=$links/empty.mmdb
    ln -sfn "$file" "$links/dbip-$kind-lite.mmdb"
  done
}

dbip_ready() { [ -s "$dbip/dbip-city-lite.mmdb" ] && [ -s "$dbip/dbip-asn-lite.mmdb" ]; }
dbip_stamp() { stat -c %Y "$dbip/dbip-city-lite.mmdb" "$dbip/dbip-asn-lite.mmdb" 2>/dev/null | tr '\n' ' ' || true; }

mkdir -p "$links"
empty_mmdb > "$links/empty.mmdb"
# Older than any real file, so Vector sees the edge's file as newer when the link moves to it.
touch -t 197001020000 "$links/empty.mmdb"

# The edge fetches DB-IP on its first start; give it a moment.
wait=${NICETRY_DBIP_WAIT:-60}
while ! dbip_ready && [ "$wait" -gt 0 ]; do
  sleep 5
  wait=$((wait - 5))
done
dbip_ready || say "no DB-IP files in $dbip yet; places stay empty until the edge fetches them"
link_dbip

# When the edge replaces the files (monthly) or they first appear, move the links and have Vector
# reload: a reload reopens enrichment tables whose files changed. $$ is Vector once exec runs.
(
  seen=$(dbip_stamp)
  while sleep "${NICETRY_DBIP_RECHECK:-300}"; do
    now=$(dbip_stamp)
    if [ "$now" != "$seen" ]; then
      link_dbip
      say "DB-IP files changed; reloading"
      kill -HUP $$ 2>/dev/null || exit 0
      seen=$now
    fi
  done
) &

exec vector --config "$conf/vector.yaml" --config "$conf/$mode.yaml" "$@"
