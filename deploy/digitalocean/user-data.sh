#!/bin/bash
# NiceTryBot on a plain Ubuntu 24.04 Droplet, with no questions: paste this file into "User data"
# (under Advanced options) when you create the Droplet, with your email below. About five minutes after
# it boots, the control panel answers at https://panel.<the Droplet's IP, dashes for dots>.sslip.io,
# or at PANEL_HOST if you set one (point its A record at the Droplet first).
#
# Log in as root then to read the first administrator's password, which is in the install log:
#   grep 'Their password' /root/nicetrybot-install.log
# and delete that log once you've kept the password somewhere safe.

EMAIL=you@example.com   # Let's Encrypt's notices, and the first administrator's login
PANEL_HOST=             # e.g. panel.example.com; empty for panel.<ip>.sslip.io
COMPOSER_TOKEN=         # optional: from nicetrybot.com/account, to build in the add-ons you own

ip=$(curl -fsS http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address)
args=(--unattended "--panel-host=${PANEL_HOST:-panel.${ip//./-}.sslip.io}" "--acme-email=$EMAIL")
[[ -n $COMPOSER_TOKEN ]] && args+=(--addons=all "--composer-token=$COMPOSER_TOKEN")

umask 077
curl -fsSL https://nicetrybot.com/install.sh | bash -s -- "${args[@]}" >/root/nicetrybot-install.log 2>&1
