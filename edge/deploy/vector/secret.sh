#!/bin/sh
# Vector exec secret backend (Vector doesn't interpolate environment variables in its config). Hands
# Vector the settings entrypoint.sh left in the environment, as JSON: SECRET[nicetry.<name>].
set -eu
cat >/dev/null

# JSON string escaping for the two characters that need it in these values.
quote() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

sep=
printf '{'
for pair in \
  edge_token=NICETRY_EDGE_TOKEN \
  app_url=NICETRY_APP_URL \
  logs_bucket=EDGE_LOGS_BUCKET \
  logs_region=EDGE_LOGS_REGION \
  logs_endpoint=EDGE_LOGS_ENDPOINT \
  logs_path_style=EDGE_LOGS_PATH_STYLE
do
  name=${pair%%=*}
  value=
  eval "value=\${${pair#*=}:-}"
  printf '%s"%s":{"value":"%s","error":null}' "$sep" "$name" "$(quote "$value")"
  sep=,
done
printf '}'
