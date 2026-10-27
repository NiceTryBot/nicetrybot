#!/usr/bin/env bash
#
# NiceTryBot installer: runs the self-hosted stack (the edge, Vector, the control panel, Postgres and
# Valkey) with Docker Compose on Ubuntu or Debian.
#
#   curl -fsSL https://nicetrybot.com/install.sh | sudo bash
#
# For cloud-init (DigitalOcean, AWS), nothing is asked:
#
#   curl -fsSL https://nicetrybot.com/install.sh | sudo bash -s -- --unattended \
#     --panel-host=panel.example.com --acme-email=you@example.com
#
# Options:
#   --dir=PATH             where the stack lives (default /opt/nicetrybot)
#   --version=VERSION      the release to run, e.g. v1.2.0 or 1.2.0 (default: the latest release)
#   --compose-only         only write ./nicetrybot/compose.yml and ./nicetrybot/.env, then stop
#   --unattended           ask nothing; needs --panel-host and --acme-email
#   --panel-host=HOST      the control panel's hostname, e.g. panel.example.com
#   --acme-email=EMAIL     where Let's Encrypt sends certificate notices
#   --admin-email=EMAIL    the first administrator (default: the ACME email when unattended)
#   --admin-password=PASS  their password (default: a random one, shown once)
#   --addons=LIST          marketplace add-ons to build in (NICETRY_ADDONS): "nicetry/captchas
#                          nicetry/threats", or "all" for every one the Composer token may install
#   --composer-token=TOKEN the Composer token from your account on nicetrybot.com (NICETRY_COMPOSER_TOKEN)
#   -h, --help             show this
#
# It also installs the nicetrybot command (/usr/local/bin/nicetrybot), which looks after the stack from
# then on: nicetrybot update, rollback, addons add|remove, status, logs. Running this installer again
# on a server that's running NiceTryBot hands over to nicetrybot update (to --version, if given).
#
# With add-ons listed in .env (NICETRY_ADDONS, with NICETRY_COMPOSER_TOKEN), the app's image is built
# here on top of the release's, with them in it (compose.addons.yml), and built again on every run,
# so running it again also brings the add-ons' newest versions.
#
# It talks to: get.docker.com (only when Docker is missing), the release downloads below, and the
# image registry. Nothing else, and nothing about this server is sent anywhere.

set -euo pipefail

# Where each release's compose.yml, compose.addons.yml and env.example (saved here as .env.example:
# GitHub renames assets that start with a dot) are published, as GitHub release assets:
# <base>/latest/download/<file> and <base>/download/v<version>/<file>. The release workflow
# (.github/workflows/release.yml) attaches them. Set NICETRY_RELEASE_BASE in the environment to
# install from somewhere else.
NICETRY_RELEASE_BASE="${NICETRY_RELEASE_BASE:-https://github.com/NiceTryBot/nicetrybot/releases}"

dir=/opt/nicetrybot
dir_given=false
version=
compose_only=false
unattended=false
panel_host=
acme_email=
admin_email=
admin_password=
addons_given=false
addons_arg=
composer_token=

say() { printf '\033[1m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[33mWarning:\033[0m %s\n' "$*" >&2; }
fail() {
  printf '\033[31mError:\033[0m %s\n' "$*" >&2
  exit 1
}

usage() {
  sed -n '2,/^$/{s/^# \{0,1\}//;p;}' "$0" 2>/dev/null || true
  echo "See the options at the top of https://nicetrybot.com/install.sh"
}

# --- Options ------------------------------------------------------------------------------------

for arg in "$@"; do
  case $arg in
    --dir=*) dir=${arg#*=} dir_given=true ;;
    --version=*) version=${arg#*=} ;;
    --compose-only) compose_only=true ;;
    --unattended) unattended=true ;;
    --panel-host=*) panel_host=${arg#*=} ;;
    --acme-email=*) acme_email=${arg#*=} ;;
    --admin-email=*) admin_email=${arg#*=} ;;
    --admin-password=*) admin_password=${arg#*=} ;;
    --addons=*) addons_arg=${arg#*=} addons_given=true ;;
    --composer-token=*) composer_token=${arg#*=} ;;
    -h | --help)
      usage
      exit 0
      ;;
    *) fail "Unknown option: $arg (try --help)" ;;
  esac
done

if [[ $compose_only == true && $dir_given == false ]]; then
  dir=$PWD/nicetrybot
fi

# --- Helpers ------------------------------------------------------------------------------------

# The name, with "Bot" in coral as in the logo (plain when the output isn't a terminal).
banner() {
  local line bold='' coral='' reset=''
  local lines=(
    ' _   _ _          _____           ____        _   '
    '| \ | (_) ___ ___|_   _| __ _   _| __ )  ___ | |_ '
    "|  \\| | |/ __/ _ \\ | || '__| | | |  _ \\ / _ \\| __|"
    '| |\  | | (_|  __/ | || |  | |_| | |_) | (_) | |_ '
    '|_| \_|_|\___\___| |_||_|   \__, |____/ \___/ \__|'
    '                            |___/                 '
  )
  if [[ -t 1 ]]; then
    bold=$'\033[1m' coral=$'\033[38;5;203m' reset=$'\033[0m'
  fi
  echo
  for line in "${lines[@]}"; do
    printf '  %s%s%s%s%s\n' "$bold" "${line:0:33}" "$coral" "${line:33}" "$reset"
  done
  echo
  echo "  Self-hosted bot defence: the edge, the control panel, Postgres and Valkey, on this server."
  echo
}

# Ask on the terminal even when this script arrives on stdin (curl | bash).
ask() {
  local prompt=$1 answer
  [[ -r /dev/tty ]] || fail "Can't ask \"$prompt\" without a terminal; pass the options instead (see --help)."
  # A closed terminal is an end, not an empty answer: never ask again forever.
  read -r -p "$prompt " answer </dev/tty || fail "No answer to \"$prompt\" (the terminal closed); pass the options instead (see --help)."
  printf '%s' "$answer"
}

valid_hostname() { [[ $1 =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$ ]]; }
valid_email() { [[ $1 =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; }

# Random secrets, from the kernel's generator (no openssl needed).
random_hex() { head -c "$1" /dev/urandom | od -An -vtx1 | tr -d ' \n'; }
random_base64() { head -c "$1" /dev/urandom | base64 | tr -d '\n'; }

# Set KEY=VALUE in an env file, in place of an existing KEY= line or at the end.
env_set() {
  local file=$1 key=$2 value=$3
  KEY=$key VALUE=$value awk '
    BEGIN { key = ENVIRON["KEY"]; value = ENVIRON["VALUE"]; done = 0 }
    index($0, key "=") == 1 { print key "=" value; done = 1; next }
    { print }
    END { if (!done) print key "=" value }
  ' "$file" >"$file.tmp"
  cat "$file.tmp" >"$file"
  rm -f "$file.tmp"
}

env_get() { sed -n "s/^$2=//p" "$1" | tail -n 1; }

# The public IPv4 address visitors will reach this server on, if it can be found without asking
# anyone outside: the address the server routes from (no packet is sent to 1.1.1.1; it only picks
# the route), or, behind a cloud's 1:1 NAT, the cloud's own metadata service on the host.
is_public_ipv4() {
  [[ $1 =~ ^([0-9]+)\.([0-9]+)\.[0-9]+\.[0-9]+$ ]] || return 1
  local a=${BASH_REMATCH[1]} b=${BASH_REMATCH[2]}
  ((a == 10 || a == 127 || a == 0)) && return 1
  ((a == 172 && b >= 16 && b <= 31)) && return 1
  ((a == 192 && b == 168)) && return 1
  ((a == 169 && b == 254)) && return 1
  ((a == 100 && b >= 64 && b <= 127)) && return 1
  return 0
}

detect_public_ipv4() {
  local ip='' token=''
  if command -v ip >/dev/null 2>&1; then
    ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{ for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit } }')
    if is_public_ipv4 "$ip"; then
      printf '%s' "$ip"
      return
    fi
  fi
  # AWS (IMDSv2), then DigitalOcean.
  if token=$(curl -fsS -m 2 -X PUT -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' http://169.254.169.254/latest/api/token 2>/dev/null); then
    ip=$(curl -fsS -m 2 -H "X-aws-ec2-metadata-token: $token" http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || true)
  fi
  if ! is_public_ipv4 "$ip"; then
    ip=$(curl -fsS -m 2 http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address 2>/dev/null || true)
  fi
  if is_public_ipv4 "$ip"; then
    printf '%s' "$ip"
  fi
}

# $version is bare (1.2.0), as NICETRY_VERSION and the image tags are; the release's tag is v1.2.0.
release_name() {
  if [[ -z $version || $version == latest ]]; then printf 'latest'; else printf 'v%s' "$version"; fi
}

release_url() {
  if [[ -z $version || $version == latest ]]; then
    printf '%s/latest/download/%s' "$NICETRY_RELEASE_BASE" "$1"
  else
    printf '%s/download/v%s/%s' "$NICETRY_RELEASE_BASE" "$version" "$1"
  fi
}

fetch() {
  local name=$1 target=$2
  curl -fsSL --retry 3 -o "$target.download" "$(release_url "$name")" ||
    fail "Couldn't download $name for release $(release_name) from $(release_url "$name")"
  mv -f "$target.download" "$target"
}

# The nicetrybot command, for the release, in /usr/local/bin. It's told where the stack is when that
# isn't /opt/nicetrybot (or the releases come from elsewhere), in /etc/default/nicetrybot.
install_cli() {
  fetch nicetrybot /usr/local/bin/nicetrybot
  chmod 755 /usr/local/bin/nicetrybot
  if [[ $dir != /opt/nicetrybot || $NICETRY_RELEASE_BASE != https://github.com/NiceTryBot/nicetrybot/releases ]]; then
    printf 'NICETRY_DIR=%q\nNICETRY_RELEASE_BASE=%q\n' "$dir" "$NICETRY_RELEASE_BASE" >/etc/default/nicetrybot
  fi
}

# Add-ons given on the command line (a cloud image's first login, or user data) go into .env, on a new
# install or a running one. The token is never echoed.
apply_addon_options() {
  if [[ $addons_given == true ]]; then
    env_set "$dir/.env" NICETRY_ADDONS "\"$addons_arg\""
    say "Add-ons set in .env: ${addons_arg:-none}"
  fi
  if [[ -n $composer_token ]]; then
    env_set "$dir/.env" NICETRY_COMPOSER_TOKEN "$composer_token"
    say "Composer token set in .env"
  fi
}

# --- The server ---------------------------------------------------------------------------------

check_server() {
  [[ $(uname -s) == Linux ]] || fail "This installer sets up Ubuntu or Debian servers. Elsewhere, use --compose-only and Docker Compose."
  local id='' id_like=''
  if [[ -r /etc/os-release ]]; then
    id=$(sed -n 's/^ID=//p' /etc/os-release | tr -d '"')
    id_like=$(sed -n 's/^ID_LIKE=//p' /etc/os-release | tr -d '"')
  fi
  case " $id $id_like " in
    *" ubuntu "* | *" debian "*) ;;
    *) fail "This installer supports Ubuntu and Debian (found ${id:-an unknown system}). Use --compose-only and Docker Compose instead." ;;
  esac
  [[ $EUID -eq 0 ]] || fail "Run it as root: curl -fsSL https://nicetrybot.com/install.sh | sudo bash"
}

install_docker() {
  if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    say "Installing Docker Engine and the Compose plugin (get.docker.com)"
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker >/dev/null 2>&1 || true
    docker compose version >/dev/null 2>&1 || fail "Docker was installed, but 'docker compose' doesn't work."
  fi

  # Older engines can't give the stack's network IPv6 without a subnet, and don't forward IPv6 with
  # ip6tables, so every IPv6 visitor would reach the edge as the same internal address.
  local engine
  engine=$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)
  if ! [[ ${engine%%.*} =~ ^[0-9]+$ ]] || ((${engine%%.*} < 27)); then
    fail "Docker Engine 27 or newer is needed (this server has ${engine:-none running}). Upgrade it: https://docs.docker.com/engine/install/"
  fi
}

# Is version $1 (like 2.24.4, or v2.40.3-desktop.1) at least $2?
version_at_least() {
  local IFS=. i
  local -a have want
  read -r -a have <<<"${1#v}"
  read -r -a want <<<"$2"
  for i in 0 1 2; do
    local h=${have[i]:-0} w=${want[i]:-0}
    h=${h%%[^0-9]*}
    ((${h:-0} > w)) && return 0
    ((${h:-0} < w)) && return 1
  done
  return 0
}

# Add-ons from the marketplace (NICETRY_ADDONS): the app's image is built here with them in it, by
# compose.addons.yml, which COMPOSE_FILE in .env brings in. Without any, COMPOSE_FILE goes back to
# compose.yml alone, so the stack is exactly the release's.
addons=
setup_addons() {
  local env=$dir/.env compose_file
  addons=$(env_get "$env" NICETRY_ADDONS | tr -d '"'"'")
  compose_file=$(env_get "$env" COMPOSE_FILE)

  if [[ -z ${addons// /} ]]; then
    addons=
    if [[ $compose_file == compose.yml:compose.addons.yml ]]; then
      env_set "$env" COMPOSE_FILE compose.yml
    fi
    return
  fi

  [[ -n $(env_get "$env" NICETRY_COMPOSER_TOKEN) ]] ||
    fail "NICETRY_ADDONS lists add-ons, but NICETRY_COMPOSER_TOKEN in $env is empty. Make a Composer token on your account at nicetrybot.com and put it there."

  local compose
  compose=$(docker compose version --short 2>/dev/null || true)
  version_at_least "${compose:-0}" 2.24.4 ||
    fail "Add-ons need Docker Compose 2.24.4 or newer (this server has ${compose:-none}). Upgrade it: https://docs.docker.com/compose/install/linux/"

  case $compose_file in
    '' | compose.yml) env_set "$env" COMPOSE_FILE compose.yml:compose.addons.yml ;;
    *compose.addons.yml*) ;;
    *) warn "COMPOSE_FILE in $env is $compose_file; add :compose.addons.yml to it, or the add-ons won't be built in." ;;
  esac
  [[ ${addons,,} == all ]] && say "Add-ons: every one your Composer token may install" || say "Add-ons: $addons"
  say "The app's image is built here with them"
}

# The whole stack wants about 2 GB of memory, and building add-ons into the image about as much again
# for a moment. A smaller server (a 1 GB droplet, say) gets a 2 GB swap file, so nothing is killed for
# want of memory; it runs, slower. Kept across reboots in /etc/fstab.
ensure_memory() {
  [[ -r /proc/meminfo ]] || return 0
  local mem swap
  mem=$(awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo)
  swap=$(awk '/^SwapTotal:/ {print int($2 / 1024)}' /proc/meminfo)
  ((mem + swap >= 1900)) && return 0
  if [[ -e /swapfile ]]; then
    warn "This server has ${mem} MB of memory and ${swap} MB of swap, and NiceTryBot wants about 2 GB. /swapfile exists but isn't on: swapon /swapfile, then run this again."
    return 0
  fi
  say "This server has ${mem} MB of memory and NiceTryBot wants about 2 GB, so adding a 2 GB swap file (/swapfile). 2 GB of memory or more is better."
  fallocate -l 2G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
}

# The release's app image, which the add-ons' image is built on.
base_image() {
  local image version
  image=$(env_get "$dir/.env" NICETRY_APP_IMAGE)
  version=$(env_get "$dir/.env" NICETRY_VERSION)
  printf '%s' "${image:-ghcr.io/nicetrybot/app:${version:-latest}}"
}

# Before a first start: something else answering on 80 or 443 would keep the edge from starting.
check_ports() {
  command -v ss >/dev/null 2>&1 || return 0
  local port
  for port in 80 443; do
    if ss -Hltn "sport = :$port" 2>/dev/null | grep -q .; then
      warn "Something on this server already listens on port $port. The edge needs ports 80 and 443; stop it first."
    fi
  done
}

# --- The settings -------------------------------------------------------------------------------

# On a new server, everything the installer needs is asked before anything is installed, so the rest
# (Docker, the images, the first start) runs on its own and nobody misses a question in the scroll.
# The panel's hostname defaults to this server's sslip.io name, which works before any DNS exists.
asked_up_front=false
ask_settings() {
  [[ $unattended == false && -r /dev/tty ]] || return 0
  [[ -n $panel_host && -n $acme_email && -n $admin_email ]] && return 0

  local address suggestion=''
  address=$(detect_public_ipv4 || true)
  [[ -n $address ]] && suggestion=panel.${address//./-}.sslip.io

  echo "  A few questions first. After them it runs on its own for a few minutes."
  if [[ -z $panel_host ]]; then
    echo
    echo "  1. The control panel's hostname. Its DNS must point at this server${address:+ ($address)}."
    if [[ -n $suggestion ]]; then
      echo "     Press Enter for $suggestion: it works right away, no DNS to set up."
      panel_host=$(ask "     Hostname [$suggestion]:")
      panel_host=${panel_host:-$suggestion}
    else
      panel_host=$(ask "     Hostname, e.g. panel.example.com:")
    fi
    until valid_hostname "$panel_host"; do
      panel_host=$(ask "     \"$panel_host\" isn't a hostname like panel.example.com. Hostname:")
    done
  fi
  if [[ -z $acme_email ]]; then
    echo
    echo "  2. An email for Let's Encrypt's certificate notices."
    acme_email=$(ask "     Email:")
    until valid_email "$acme_email"; do
      acme_email=$(ask "     That isn't an email address. Email:")
    done
  fi
  if [[ -z $admin_email ]]; then
    echo
    echo "  3. Your login for the panel: you'll be its first administrator."
    admin_email=$(ask "     Email [$acme_email]:")
    admin_email=${admin_email:-$acme_email}
    until valid_email "$admin_email"; do
      admin_email=$(ask "     That isn't an email address. Email:")
    done
  fi
  asked_up_front=true
  echo
  say "That's everything. Installing now, with no more questions: your panel's address and password come at the end."
}

# A fresh .env from .env.example: the secrets, the panel's hostname, the ACME email, the release and
# this server's address. Everything else keeps the bundled defaults.
write_env() {
  local env=$dir/.env address

  if [[ -z $panel_host ]]; then
    [[ $unattended == true ]] && fail "--unattended needs --panel-host=HOST"
    panel_host=$(ask "The control panel's hostname (its DNS must point here), e.g. panel.example.com:")
  fi
  valid_hostname "$panel_host" || fail "\"$panel_host\" isn't a hostname like panel.example.com"
  panel_host=$(printf '%s' "$panel_host" | tr '[:upper:]' '[:lower:]')

  if [[ -z $acme_email ]]; then
    [[ $unattended == true ]] && fail "--unattended needs --acme-email=EMAIL"
    acme_email=$(ask "An email for Let's Encrypt's certificate notices:")
  fi
  valid_email "$acme_email" || fail "\"$acme_email\" isn't an email address"

  cp "$dir/.env.example" "$env.new"
  chmod 600 "$env.new"
  env_set "$env.new" NICETRY_VERSION "${version:-latest}"
  env_set "$env.new" NICETRY_PANEL_HOST "$panel_host"
  env_set "$env.new" NICETRY_ACME_EMAIL "$acme_email"
  env_set "$env.new" NICETRY_EDGE_TOKEN "$(random_hex 32)"
  env_set "$env.new" APP_KEY "base64:$(random_base64 32)"
  env_set "$env.new" DB_PASSWORD "$(random_hex 24)"

  address=$(detect_public_ipv4 || true)
  if [[ -n $address ]]; then
    env_set "$env.new" NICETRY_EDGE_ADDRESSES "$address"
  else
    warn "Couldn't find this server's public IPv4 address. Set NICETRY_EDGE_ADDRESSES in $env to it, then run: docker compose up -d"
  fi

  mv -f "$env.new" "$env"
  say "Wrote $env (keep it: it holds the stack's secrets)"
}

# --- Main ---------------------------------------------------------------------------------------

# A release is v1.2.0 (or v1.2.0-beta.1): kept bare (1.2.0), which is what NICETRY_VERSION and the
# images' tags hold. "latest" follows the newest release.
if [[ -n $version && $version != latest ]]; then
  [[ $version =~ ^v?[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z][0-9A-Za-z.-]*)?$ ]] ||
    fail "--version must be a release like v1.2.0 (got $version)"
  version=${version#v}
fi
if [[ -n $panel_host ]] && ! valid_hostname "$panel_host"; then
  fail "\"$panel_host\" isn't a hostname like panel.example.com"
fi
for email in "$acme_email" "$admin_email"; do
  if [[ -n $email ]] && ! valid_email "$email"; then
    fail "\"$email\" isn't an email address"
  fi
done

command -v curl >/dev/null 2>&1 || fail "curl is needed (apt-get install -y curl)"

banner

if [[ $compose_only == false ]]; then
  check_server
  [[ -f $dir/.env ]] || ask_settings
  ensure_memory
  install_docker
fi

mkdir -p "$dir"
installed=false
[[ -f $dir/.env ]] && installed=true

# A server that's running NiceTryBot (it finished installing, or its app is up) is updated by the
# nicetrybot command instead: a backup first, a short summary, and nicetrybot rollback to undo it.
if [[ $installed == true && $compose_only == false ]] &&
  { [[ -f $dir/.installed ]] || [[ -n $(cd "$dir" && docker compose ps -q app 2>/dev/null) ]]; }; then
  apply_addon_options
  install_cli
  say "NiceTryBot is installed in $dir; updating it (next time: nicetrybot update)"
  exec /usr/local/bin/nicetrybot update ${version:+"--version=$version"}
fi

say "Downloading compose.yml, compose.addons.yml and .env.example for release $(release_name)"
if [[ -f $dir/compose.yml ]]; then
  cp -f "$dir/compose.yml" "$dir/compose.yml.previous"
fi
fetch compose.yml "$dir/compose.yml"
fetch compose.addons.yml "$dir/compose.addons.yml"
fetch env.example "$dir/.env.example"

if [[ $installed == true ]]; then
  say "Keeping $dir/.env"
  if [[ -n $version && $(env_get "$dir/.env" NICETRY_VERSION) != "$version" ]]; then
    env_set "$dir/.env" NICETRY_VERSION "$version"
    say "Release set to $version"
  fi
else
  write_env
fi

apply_addon_options

if [[ $compose_only == true ]]; then
  say "Done. Start it with: cd $dir && docker compose up -d"
  exit 0
fi

install_cli

cd "$dir"

[[ $installed == false ]] && check_ports

setup_addons

say "Pulling images"
docker compose pull --quiet || warn "Couldn't pull every image; starting with the ones this server already has."
if [[ -n $addons ]]; then
  # The add-ons' image is built on the release's, which no service runs itself, so compose doesn't pull it.
  docker pull --quiet "$(base_image)" >/dev/null || warn "Couldn't pull $(base_image); building on the copy this server already has."
fi

if [[ -n $addons ]]; then
  say "Building the app's image with the add-ons, then starting the stack"
  docker compose up -d --build --remove-orphans
else
  say "Starting the stack"
  docker compose up -d --remove-orphans
fi

say "Waiting for the app (it runs any new database migrations as it starts)"
healthy=false
for _ in $(seq 1 120); do
  app=$(docker compose ps -q app 2>/dev/null || true)
  if [[ -n $app && $(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$app" 2>/dev/null) == healthy ]]; then
    healthy=true
    break
  fi
  sleep 5
done
if [[ $healthy == false ]]; then
  docker compose logs --tail 50 app >&2 || true
  fail "The app didn't become healthy within 10 minutes. See: cd $dir && docker compose logs app"
fi

# The first administrator (on a server that has one, this only shows the panel and DNS records).
install_args=(php artisan nicetry:install)
[[ -z $admin_email && $unattended == true ]] && admin_email=$acme_email
[[ -n $admin_email ]] && install_args+=("--email=$admin_email")
[[ -n $admin_password ]] && install_args+=("--password=$admin_password")

if [[ $unattended == true || $asked_up_front == true ]]; then
  # Everything was asked up front: a random password, shown once below.
  docker compose exec -T app "${install_args[@]}" --no-interaction
elif [[ -r /dev/tty ]]; then
  docker compose exec app "${install_args[@]}" </dev/tty
else
  docker compose exec -T app "${install_args[@]}" --no-interaction
fi

touch "$dir/.installed"

panel_host=$(env_get "$dir/.env" NICETRY_PANEL_HOST)
echo
# A bell, for whoever walked away while it ran.
[[ -t 1 ]] && printf '\a'
say "NiceTryBot is running in $dir"
echo "    The panel answers at https://$panel_host once its DNS points here (the certificate follows on its own)."
echo "    Settings: $dir/.env (then: cd $dir && docker compose up -d)"
echo "    Update:   nicetrybot update   (nicetrybot rollback undoes it)"
echo "    Add-ons:  nicetrybot addons add captchas   (browse them at https://nicetrybot.com/marketplace)"
echo "    More:     nicetrybot help"
