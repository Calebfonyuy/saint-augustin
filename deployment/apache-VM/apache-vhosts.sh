#!/usr/bin/env bash
# Generate three Apache vhost files (one per domain) and enable them.
# Idempotent — re-running overwrites the files in place.
#
# Run after `source config.sh` (DOMAIN_*, PORT_* must be set):
#   sudo -E ./apache-vhosts.sh
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "must run as root (use 'sudo -E' so the env survives)" >&2
  exit 1
fi

: "${DOMAIN_APP:?source config.sh first}"
: "${DOMAIN_API:?source config.sh first}"
: "${DOMAIN_PROJECTION:?source config.sh first}"
: "${PORT_FRONTEND:?source config.sh first}"
: "${PORT_AUTH:?source config.sh first}"
: "${PORT_PROJECTION:?source config.sh first}"

VHOST_DIR="/etc/apache2/sites-available"

# ── Frontend vhost (SPA) ────────────────────────────────────────────
cat > "${VHOST_DIR}/saintaugustin-app.conf" <<EOF
# SaintAugustin — Vue SPA (frontend container).
<VirtualHost *:80>
    ServerName ${DOMAIN_APP}

    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass        / http://127.0.0.1:${PORT_FRONTEND}/
    ProxyPassReverse / http://127.0.0.1:${PORT_FRONTEND}/

    ErrorLog  \${APACHE_LOG_DIR}/saintaugustin-app-error.log
    CustomLog \${APACHE_LOG_DIR}/saintaugustin-app-access.log combined
</VirtualHost>
EOF

# ── Auth API vhost ──────────────────────────────────────────────────
cat > "${VHOST_DIR}/saintaugustin-api.conf" <<EOF
# SaintAugustin — Laravel auth-service (REST + Sanctum).
<VirtualHost *:80>
    ServerName ${DOMAIN_API}

    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass        / http://127.0.0.1:${PORT_AUTH}/
    ProxyPassReverse / http://127.0.0.1:${PORT_AUTH}/

    # Bump body size for song-sheet / VideoPsalm bulk uploads.
    LimitRequestBody 104857600

    ErrorLog  \${APACHE_LOG_DIR}/saintaugustin-api-error.log
    CustomLog \${APACHE_LOG_DIR}/saintaugustin-api-access.log combined
</VirtualHost>
EOF

# ── Projection vhost (HTTP + Socket.IO WebSocket upgrade) ───────────
cat > "${VHOST_DIR}/saintaugustin-projection.conf" <<EOF
# SaintAugustin — NestJS projection-service. Socket.IO needs the
# ws/wss tunnel; the RewriteRule swaps protocols on the Upgrade header.
<VirtualHost *:80>
    ServerName ${DOMAIN_PROJECTION}

    ProxyPreserveHost On
    ProxyRequests Off

    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/?(.*) "ws://127.0.0.1:${PORT_PROJECTION}/\$1" [P,L]

    ProxyPass        / http://127.0.0.1:${PORT_PROJECTION}/
    ProxyPassReverse / http://127.0.0.1:${PORT_PROJECTION}/

    # Long-lived Socket.IO connections.
    ProxyTimeout 86400

    ErrorLog  \${APACHE_LOG_DIR}/saintaugustin-projection-error.log
    CustomLog \${APACHE_LOG_DIR}/saintaugustin-projection-access.log combined
</VirtualHost>
EOF

a2ensite saintaugustin-app.conf saintaugustin-api.conf saintaugustin-projection.conf >/dev/null

apache2ctl configtest
systemctl reload apache2

cat <<EOF

Apache vhosts deployed:
  ${DOMAIN_APP}        → 127.0.0.1:${PORT_FRONTEND}
  ${DOMAIN_API}        → 127.0.0.1:${PORT_AUTH}
  ${DOMAIN_PROJECTION} → 127.0.0.1:${PORT_PROJECTION} (with WebSocket upgrade)

DNS: point each A record at this VM's public IP, then run ./deploy.sh.
EOF
