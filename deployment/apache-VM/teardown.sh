#!/usr/bin/env bash
# Stop the SaintAugustin stack. Does NOT delete data volumes by
# default — pass `--purge` to also drop postgres / minio data.
#
# Apache vhost files are left in place; remove them manually with
# a2dissite if needed.
set -euo pipefail

: "${INSTALL_DIR:?source config.sh first}"

PURGE=false
[[ "${1:-}" == "--purge" ]] && PURGE=true

cd "$INSTALL_DIR"

if $PURGE; then
  echo "Stopping stack AND removing volumes (postgres + minio data lost)…"
  read -rp "Type 'PURGE' to confirm: " ans
  [[ "$ans" == "PURGE" ]] || { echo "aborted."; exit 1; }
  docker compose down -v
else
  echo "Stopping stack (data volumes preserved)…"
  docker compose down
fi

echo "Done."
