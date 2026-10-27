# NiceTryBot on DigitalOcean

Two ways onto a Droplet:

- **The 1-Click image** (this folder): Ubuntu 24.04 with Docker, a firewall and NiceTryBot's images
  already pulled. Log in as root and `nicetrybot-setup` asks three questions, then runs the installer.
- **User data on plain Ubuntu**, which works today on any account: paste [`user-data.sh`](user-data.sh)
  into *User data* when you create an Ubuntu 24.04 Droplet, with your email in it.

Either way, the Droplet runs the same stack as every NiceTryBot install: the one in
[`deploy/`](../README.md), set up by [`install.sh`](../install.sh).

## What the image holds

| Step | What it does |
|---|---|
| [`10-docker.sh`](scripts/10-docker.sh) | Updates Ubuntu, then installs Docker Engine and Compose from Docker's apt repository. |
| [`20-images.sh`](scripts/20-images.sh) | Pulls `ghcr.io/nicetrybot/{app,edge,vector}:latest`, `postgres:17`, `valkey/valkey:8` and the Node image the add-ons build uses, so a new Droplet starts in about a minute. |
| [`30-firewall.sh`](scripts/30-firewall.sh) | ufw lets in 22, 80 and 443 only. |
| [`40-first-login.sh`](scripts/40-first-login.sh) | Adds a line to root's `.bashrc` that runs `nicetrybot-setup` at login until the stack is set up. |
| [`nicetrybot-setup`](files/usr/local/bin/nicetrybot-setup) | The welcome. It asks for the panel's hostname (offering `panel.<ip>.sslip.io`, which works without DNS), an email for Let's Encrypt, and an optional Composer token for add-ons, then runs `curl -fsSL https://nicetrybot.com/install.sh \| bash -s -- --unattended …` and shows the panel's address and the first administrator's password. `nicetrybot-setup --help` lists its environment variables for running it without questions, and `--dry-run` shows the installer command it would run. |
| [`99-nicetrybot`](files/etc/update-motd.d/99-nicetrybot) | The login message: the panel's address once it's set up, or how to finish. |
| [`90-marketplace.sh`](scripts/90-marketplace.sh) | DigitalOcean's [Marketplace cleanup and image check](https://github.com/digitalocean/marketplace-partners) (© DigitalOcean LLC, Apache License 2.0), fetched at a pinned commit and checked against their sha256: updates, cleared logs, keys and history, zeroed free space, then `img_check`, which fails the build if anything would be rejected. |

The build Droplet is the smallest with a 25 GB disk (`s-1vcpu-1gb`), because a snapshot only runs on
Droplets with a disk at least as big as the one it was made on. NiceTryBot itself runs on 1 GB; building
add-ons in wants 2 GB, and the installer adds swap on a smaller Droplet.

## Building it

Docker is all it needs: Packer runs from its official image (`hashicorp/packer:1.16.1`, with the
DigitalOcean plugin pinned in [`nicetrybot.pkr.hcl`](nicetrybot.pkr.hcl)).

```sh
cd deploy/digitalocean
DIGITALOCEAN_TOKEN=dop_v1_… ./build.sh 0.1.0
```

It takes about 15 minutes and leaves a snapshot named `nicetrybot-0.1.0-<UTC time>` in the account the
token belongs to. The token needs read and write access to Droplets, images, snapshots, SSH keys, tags,
actions and regions; the build Droplet is deleted when Packer finishes, whether it succeeds or not.

To try the snapshot, create a Droplet from it (any size with 25 GB of disk or more), then
`ssh root@<its ip>`.

## Without questions

The image runs the setup from user data too. Paste this into *User data* when you create a Droplet
from it:

```bash
#!/bin/bash
NICETRY_ACME_EMAIL=you@example.com nicetrybot-setup --unattended >/root/nicetrybot-setup.log 2>&1
```

Add `NICETRY_PANEL_HOST=panel.example.com` for your own hostname, and `NICETRY_COMPOSER_TOKEN=…` for
add-ons. The setup prints the first administrator's password at the end, so it's in
`/root/nicetrybot-setup.log`; delete that file once you've kept the password somewhere safe. (The
installer's own log, `/root/nicetrybot-install.log`, never keeps it.)

## Submitting it to the Marketplace

1. Apply as a vendor at [marketplace.digitalocean.com/vendors](https://marketplace.digitalocean.com/vendors).
   Once accepted, you get access to the [Vendor Portal](https://cloud.digitalocean.com/vendorportal).
2. Build the snapshot in the same DigitalOcean team you use for the Vendor Portal. `img_check` must pass
   with no failures (the build stops otherwise).
3. In the Vendor Portal, create the 1-Click App: pick the snapshot, and give it a name, a summary, a
   description, the software it includes (Docker, Caddy, PHP, Postgres, Valkey, Vector, with versions),
   a logo, support details and the getting-started text (what `nicetrybot-setup` asks for).
4. DigitalOcean reviews the listing and the image, usually by creating a Droplet from it, and replies
   by email. Later releases are new snapshots submitted against the same app, in the portal or through
   their API.
