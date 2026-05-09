#!/usr/bin/env bash
# Install Docker, Docker Compose plugin, Apache, and the proxy
# modules SaintAugustin needs. Tested on Debian 12 / Ubuntu 22.04.
#
# Run as root (or via sudo):
#   sudo ./install-prereqs.sh
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "must run as root (use sudo)" >&2
  exit 1
fi

echo "[1/4] apt update + base packages…"
apt-get update
apt-get install -y \
  ca-certificates curl gnupg lsb-release \
  apache2 git

echo "[2/4] Docker…"
if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/$(. /etc/os-release && echo "$ID")/gpg \
    | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
    https://download.docker.com/linux/$(. /etc/os-release && echo "$ID") \
    $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io \
    docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker
fi

echo "[3/4] Apache modules (proxy, headers, websocket, rewrite)…"
a2enmod proxy proxy_http proxy_wstunnel headers rewrite ssl >/dev/null
# Default site ships listening on :80 — leave it; we add per-domain
# vhosts in apache-vhosts.sh.

echo "[4/4] Restart apache to pick up the modules."
systemctl restart apache2

cat <<EOF

Prerequisites installed. Next steps:

  1. cp config.example.sh config.sh   # populate
  2. source config.sh
  3. ./apache-vhosts.sh               # generate Apache vhost files
  4. ./deploy.sh                      # bring up the stack via docker compose
EOF
