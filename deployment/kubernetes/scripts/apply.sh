#!/usr/bin/env bash
# Apply an overlay to the current kubectl context.
#
# Usage:
#   ./apply.sh dev
#   ./apply.sh prod
#
# Pre-flight:
#   - secrets.yaml must exist in deployment/kubernetes/base/ (copy from
#     secrets.example.yaml and fill in real values)
#   - the current kubectl context must point at the right cluster
#   - the images referenced in the overlay's kustomization.yaml must
#     already be pushed to the registry (run build-and-push.sh first)
set -euo pipefail

OVERLAY="${1:-}"
if [[ -z "$OVERLAY" ]]; then
  echo "usage: $0 <dev|prod>" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OVERLAY_DIR="${ROOT}/overlays/${OVERLAY}"
SECRETS="${ROOT}/base/secrets.yaml"

if [[ ! -d "$OVERLAY_DIR" ]]; then
  echo "no such overlay: ${OVERLAY_DIR}" >&2
  exit 1
fi

if [[ ! -f "$SECRETS" ]]; then
  echo "missing ${SECRETS}" >&2
  echo "copy base/secrets.example.yaml to base/secrets.yaml, then edit." >&2
  exit 1
fi

CTX="$(kubectl config current-context)"
echo "Applying overlay '${OVERLAY}' to context '${CTX}'."
read -rp "Continue? [y/N] " ans
[[ "$ans" =~ ^[Yy]$ ]] || { echo "aborted."; exit 1; }

# Namespace + secrets first so the rest can resolve their refs.
kubectl apply -f "${ROOT}/base/namespace.yaml"
kubectl apply -f "${SECRETS}"

# Then everything else through kustomize.
kubectl apply -k "${OVERLAY_DIR}"

echo
echo "Applied. Watch rollout with:"
echo "  kubectl -n saintaugustin get pods -w"
