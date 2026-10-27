#!/bin/bash
# The welcome on the first root login: a line at the end of root's .bashrc runs nicetrybot-setup while
# the stack isn't set up yet (no /opt/nicetrybot/.env), and the setup removes the line once it is. Only
# interactive logins get it: Ubuntu's .bashrc stops early for anything else (scp, ssh host command).
set -euo pipefail

chmod 0755 /usr/local/bin/nicetrybot-setup /etc/update-motd.d/99-nicetrybot

cat >>/root/.bashrc <<'BASHRC'

# NiceTryBot: finish setting up this Droplet (nicetrybot-setup removes this once it's set up).
[ -t 0 ] && [ ! -f /opt/nicetrybot/.env ] && /usr/local/bin/nicetrybot-setup
BASHRC
