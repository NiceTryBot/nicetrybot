#!/bin/bash
# A firewall that lets in SSH and the web, and nothing else. (Docker publishes only the edge's 80 and
# 443; Postgres, Valkey and the app stay on the stack's own network.)
set -euo pipefail

ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'NiceTryBot edge: HTTP and Lets Encrypt'
ufw allow 443/tcp comment 'NiceTryBot edge: HTTPS'
ufw --force enable
ufw status verbose
