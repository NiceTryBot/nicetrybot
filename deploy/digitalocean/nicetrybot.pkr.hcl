# NiceTryBot's DigitalOcean 1-Click image: Ubuntu 24.04 with Docker, a firewall, the release's images
# already pulled, and nicetrybot-setup, which asks three questions on the first root login and runs the
# installer. Build it with ./build.sh (Packer in Docker); see README.md.

packer {
  required_plugins {
    digitalocean = {
      version = "= 1.4.1"
      source  = "github.com/digitalocean/digitalocean"
    }
  }
}

variable "do_token" {
  type      = string
  sensitive = true
  default   = env("DIGITALOCEAN_TOKEN")
}

variable "version" {
  type        = string
  default     = "0.1.0"
  description = "The NiceTryBot release whose images the snapshot carries (its name says so)."
}

variable "region" {
  type    = string
  default = "sfo3"
}

# The snapshot needs a disk at least as big as the build Droplet's, so the build uses the smallest disk
# that fits (25 GB): the image then runs on any Droplet from 1 GB up.
variable "size" {
  type    = string
  default = "s-1vcpu-1gb"
}

# DigitalOcean's Marketplace cleanup and image check (Apache-2.0), from
# github.com/digitalocean/marketplace-partners at this commit, each checked against its sha256.
variable "marketplace_commit" {
  type    = string
  default = "b70878804ca27c01d5f5e882d26485defbaba210"
}

locals {
  stamp = formatdate("YYYYMMDD-hhmm", timestamp())
}

source "digitalocean" "nicetrybot" {
  api_token     = var.do_token
  image         = "ubuntu-24-04-x64"
  region        = var.region
  size          = var.size
  ssh_username  = "root"
  droplet_name  = "nicetrybot-image-build-${local.stamp}"
  snapshot_name = "nicetrybot-${var.version}-${local.stamp}"
  tags          = ["nicetrybot-image-build"]
}

build {
  sources = ["source.digitalocean.nicetrybot"]

  # Let first boot finish (apt, keys) before anything else runs.
  provisioner "shell" {
    inline = ["cloud-init status --wait >/dev/null || true"]
  }

  provisioner "file" {
    source      = "${path.root}/files/"
    destination = "/"
  }

  provisioner "shell" {
    environment_vars = ["DEBIAN_FRONTEND=noninteractive", "LC_ALL=C"]
    scripts = [
      "${path.root}/scripts/10-docker.sh",
      "${path.root}/scripts/20-images.sh",
      "${path.root}/scripts/30-firewall.sh",
      "${path.root}/scripts/40-first-login.sh",
    ]
  }

  # Last: DigitalOcean's cleanup (updates, logs, keys, history, zeroed free space), then their image
  # check, which fails the build on any failed test.
  provisioner "shell" {
    environment_vars = ["DEBIAN_FRONTEND=noninteractive", "LC_ALL=C", "TERM=dumb", "MARKETPLACE_COMMIT=${var.marketplace_commit}"]
    script           = "${path.root}/scripts/90-marketplace.sh"
  }
}
