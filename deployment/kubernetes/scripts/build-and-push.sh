#!/usr/bin/env bash
# Build and push the three application images to the configured registry.
#
# Usage:
#   REGISTRY=ghcr.io/your-user TAG=v1.2.3 ./build-and-push.sh
#
# The frontend image embeds API URLs at build time, so pass the public
# hostname when building for a real environment:
#   API_BASE=https://saintaugustin.example.com/api \
#   PROJECTION_BASE=https://saintaugustin.example.com \
#   REGISTRY=... TAG=... ./build-and-push.sh
#
# Without those, the frontend build defaults to localhost — fine for
# kind/minikube smoke tests, useless in production.
set -euo pipefail

: "${REGISTRY:?REGISTRY is required (e.g. ghcr.io/your-user or registry.local:5000)}"
: "${TAG:?TAG is required (e.g. v1.0.0 or git short-sha)}"

API_BASE="${API_BASE:-http://localhost:8001/api}"
PROJECTION_BASE="${PROJECTION_BASE:-http://localhost:3000}"

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"

echo "[1/3] Building auth-service…"
docker build \
  -t "${REGISTRY}/saintaugustin-auth:${TAG}" \
  "${ROOT}/services/auth"

echo "[2/3] Building projection-service…"
docker build \
  --target production \
  -t "${REGISTRY}/saintaugustin-projection:${TAG}" \
  "${ROOT}/services/projection"

echo "[3/3] Building frontend (API_BASE=${API_BASE}, PROJECTION_BASE=${PROJECTION_BASE})…"
docker build \
  --target production \
  --build-arg "VITE_API_BASE_URL=${API_BASE}" \
  --build-arg "VITE_PROJECTION_BASE_URL=${PROJECTION_BASE}" \
  --build-arg "VITE_WS_URL=${PROJECTION_BASE}" \
  -t "${REGISTRY}/saintaugustin-frontend:${TAG}" \
  "${ROOT}/frontend"

echo
echo "Pushing to ${REGISTRY}…"
docker push "${REGISTRY}/saintaugustin-auth:${TAG}"
docker push "${REGISTRY}/saintaugustin-projection:${TAG}"
docker push "${REGISTRY}/saintaugustin-frontend:${TAG}"

echo
echo "Done. Update the overlay's kustomization.yaml \`images:\` block with"
echo "  newName: ${REGISTRY}/saintaugustin-{auth,projection,frontend}"
echo "  newTag:  ${TAG}"
