#!/usr/bin/env bash
# =============================================================================
# SaintAugustin – Debian Development Server Setup
#
# Installs Docker Engine, Docker Compose v2, Node.js 20, PHP 8.3, Composer,
# and other prerequisites on a Debian 12 (bookworm) server.
#
# Usage:
#   chmod +x scripts/setup-debian.sh
#   sudo ./scripts/setup-debian.sh
#
# After running, log out and back in (or run `newgrp docker`) for the docker
# group to take effect.
#
# References:
#   Docker Engine on Debian:  https://docs.docker.com/engine/install/debian/
#   Node.js via NodeSource:   https://github.com/nodesource/distributions
#   PHP 8.3 via sury.org:     https://packages.sury.org/php/
#   Composer:                 https://getcomposer.org/download/
# =============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn() { echo -e "${YELLOW}[!]${NC} $*"; }
err()  { echo -e "${RED}[✗]${NC} $*"; exit 1; }

# Must run as root
[[ $EUID -eq 0 ]] || err "Run this script with sudo or as root."

REAL_USER="${SUDO_USER:-$USER}"

echo "============================================="
echo " SaintAugustin – Dev Environment Setup"
echo " Target: Debian $(cat /etc/debian_version)"
echo "============================================="
echo ""

# ─── 1. System packages ─────────────────────────────────────────────────────

log "Updating package index..."
apt-get update -qq

log "Installing base dependencies..."
apt-get install -y -qq \
    ca-certificates \
    curl \
    gnupg \
    lsb-release \
    git \
    unzip \
    jq \
    make \
    build-essential \
    apt-transport-https \
    software-properties-common

# ─── 2. Docker Engine + Compose v2 ──────────────────────────────────────────

if command -v docker &>/dev/null; then
    warn "Docker already installed: $(docker --version)"
else
    log "Installing Docker Engine..."

    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
      https://download.docker.com/linux/debian \
      $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
      > /etc/apt/sources.list.d/docker.list

    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    # Add non-root user to docker group
    usermod -aG docker "$REAL_USER"
    log "Docker installed. User '$REAL_USER' added to docker group."
fi

# Verify
docker --version
docker compose version

# ─── 3. Node.js 20 (LTS) ────────────────────────────────────────────────────

if command -v node &>/dev/null && node --version | grep -q "^v20"; then
    warn "Node.js 20 already installed: $(node --version)"
else
    log "Installing Node.js 20 via NodeSource..."

    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y -qq nodejs

    # Set npm global prefix for non-root installs
    sudo -u "$REAL_USER" mkdir -p "/home/$REAL_USER/.npm-global"
    sudo -u "$REAL_USER" npm config set prefix "/home/$REAL_USER/.npm-global"

    # Add to PATH if not already there
    PROFILE="/home/$REAL_USER/.bashrc"
    if ! grep -q '.npm-global/bin' "$PROFILE" 2>/dev/null; then
        echo 'export PATH="$HOME/.npm-global/bin:$PATH"' >> "$PROFILE"
    fi

    log "Node.js installed."
fi

node --version
npm --version

# ─── 4. PHP 8.3 + Extensions ────────────────────────────────────────────────

if command -v php &>/dev/null && php -v | grep -q "8.3"; then
    warn "PHP 8.3 already installed."
else
    log "Installing PHP 8.3 via sury.org repository..."

    curl -sSLo /tmp/debsuryorg-archive-keyring.deb \
        https://packages.sury.org/debsuryorg-archive-keyring.deb
    dpkg -i /tmp/debsuryorg-archive-keyring.deb

    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] \
        https://packages.sury.org/php/ $(lsb_release -sc) main" \
        > /etc/apt/sources.list.d/sury-php.list

    apt-get update -qq
    apt-get install -y -qq \
        php8.3-cli \
        php8.3-common \
        php8.3-pgsql \
        php8.3-redis \
        php8.3-mbstring \
        php8.3-xml \
        php8.3-zip \
        php8.3-intl \
        php8.3-curl \
        php8.3-opcache \
        php8.3-pcov

    log "PHP 8.3 installed."
fi

php --version

# ─── 5. Composer ────────────────────────────────────────────────────────────

if command -v composer &>/dev/null; then
    warn "Composer already installed: $(composer --version 2>/dev/null | head -1)"
else
    log "Installing Composer..."

    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
        rm composer-setup.php
        err "Composer installer checksum mismatch!"
    fi

    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm composer-setup.php

    log "Composer installed."
fi

composer --version

# ─── 6. Summary ─────────────────────────────────────────────────────────────

echo ""
echo "============================================="
echo " Setup complete!"
echo "============================================="
echo ""
echo " Docker:   $(docker --version)"
echo " Compose:  $(docker compose version)"
echo " Node.js:  $(node --version)"
echo " npm:      $(npm --version)"
echo " PHP:      $(php --version | head -1)"
echo " Composer: $(sudo -u $USER composer --version 2>/dev/null | head -1)"
echo ""
echo " IMPORTANT: Log out and back in (or run 'newgrp docker')"
echo " for the docker group to take effect."
echo ""
echo " Next steps:"
echo "   cd saint-augustin"
echo "   cp .env.example .env"
echo "   docker compose up -d"
echo ""
