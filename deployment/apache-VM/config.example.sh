# SaintAugustin — Apache VM deployment config.
#
# Copy to `config.sh` (which is gitignored), fill in real values, then
# `source config.sh` before running the other scripts. All scripts in
# this directory expect these variables to be exported.

# ── Domains (one per service) ───────────────────────────────────────
export DOMAIN_APP="app.example.com"          # Vue SPA
export DOMAIN_API="api.example.com"          # Laravel auth-service
export DOMAIN_PROJECTION="projection.example.com"  # NestJS + Socket.IO

# ── Container registry / image tag ──────────────────────────────────
# Where the prebuilt images live. Leave as REGISTRY/... for a local
# build (the deploy script will build from source if pull fails).
export REGISTRY="ghcr.io/REPLACE_ME"
export TAG="latest"

# ── Filesystem layout ───────────────────────────────────────────────
# Where the project is checked out / will be deployed on the VM.
# `deploy.sh` clones into INSTALL_DIR if it's empty, otherwise pulls.
export INSTALL_DIR="/opt/saintaugustin"
export GIT_REMOTE="git@github.com:REPLACE_ME/saint-augustin.git"
export GIT_BRANCH="main"

# ── Service-side ports (loopback only — Apache proxies to these) ────
export PORT_FRONTEND="5173"
export PORT_AUTH="8001"
export PORT_PROJECTION="3000"

# ── Postgres / Redis / MinIO credentials ────────────────────────────
# Generate strong values with `openssl rand -base64 32` before going
# into production.
export POSTGRES_USER="saintaugustin"
export POSTGRES_PASSWORD="changeme"
export REDIS_PASSWORD=""   # empty disables auth (loopback-only is OK)
export MINIO_ROOT_USER="minioadmin"
export MINIO_ROOT_PASSWORD="changeme"

# ── Laravel ─────────────────────────────────────────────────────────
# Generate APP_KEY once, then keep it stable across redeploys.
# docker run --rm php:8.3-cli-alpine \
#   php -r "echo 'base64:'.base64_encode(random_bytes(32));"
export APP_KEY="base64:REPLACE_ME"
export JWT_SECRET="REPLACE_ME"

# ── SMTP (optional — leave MAIL_MAILER=log for no-op) ───────────────
export MAIL_MAILER="log"
export MAIL_HOST=""
export MAIL_PORT=""
export MAIL_USERNAME=""
export MAIL_PASSWORD=""
export MAIL_FROM_ADDRESS="noreply@${DOMAIN_APP}"
export MAIL_FROM_NAME="SaintAugustin"
