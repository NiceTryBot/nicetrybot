#!/bin/bash
# DigitalOcean's Marketplace cleanup and image check, from github.com/digitalocean/marketplace-partners
# (© DigitalOcean LLC, Apache License 2.0) at a pinned commit, each script checked against its sha256
# before it runs. The cleanup installs updates, clears logs, SSH keys and history, and zeroes free space;
# the check fails (and so fails the build) on anything the Marketplace would reject.
set -euo pipefail

commit=${MARKETPLACE_COMMIT:?}
declare -A sha256=(
  [90-cleanup.sh]=5e2fe7ce30892a26ed2731238d9f26c40ae8b1084b070ce095f3b99ab1a2cc81
  [99-img-check.sh]=91ff2b1880439c97ccdc49554c5ed8901b89ef250c8ac5fffe52811c68abac49
)

# Not under /tmp: the cleanup empties /tmp, and the check runs after it.
work=/root/.marketplace-check
rm -rf "$work"
mkdir -p "$work"
for script in 90-cleanup.sh 99-img-check.sh; do
  curl -fsSL "https://raw.githubusercontent.com/digitalocean/marketplace-partners/$commit/scripts/$script" -o "$work/$script"
  echo "${sha256[$script]}  $work/$script" | sha256sum --check --quiet
  chmod +x "$work/$script"
done

# The image check rejects DigitalOcean's own droplet agent (it's installed per Droplet, not in images),
# and its apt source would fail apt's signature check once its keyring is gone.
apt-get -qq -y purge droplet-agent droplet-agent-keyring 2>/dev/null || true
rm -rf /opt/digitalocean
rm -f /etc/apt/sources.list.d/droplet-agent*.list /etc/apt/sources.list.d/droplet-agent*.sources

"$work/90-cleanup.sh"
# The cleanup's last step fills the disk with zeros, and the kernel logs that it ran out of space: clear
# what was written since, so the check finds no log content.
find /var/log -type f -newer "$work/90-cleanup.sh" -exec truncate -s 0 {} +
"$work/99-img-check.sh"
rm -rf "$work"
