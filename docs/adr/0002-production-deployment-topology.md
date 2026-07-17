# ADR-0002: Production deployment topology (K8s manifests + standalone Compose)

**Status:** Accepted
**Date:** 2026-07-17

## Context

Through Stage 7, the only committed orchestration was the repo-root
`docker-compose.yml`, written for **local development** (build contexts,
permissive inline defaults). Stage 8's goal is "production-ready deployment on
both targets, plus a regression pass." Two questions had to be settled:

1. **How is Kubernetes expressed?** `deployment/kubernetes/` already holds one
   numbered manifest per workload (`00_namespace` … `08_frontend_service`),
   including a queue-worker Deployment, migrate/minio-init Jobs, and the
   ConfigMap/Secret split. `charts/` exists but is empty. The v0.2
   implementation plan suggested "folding the manifests toward a Helm chart."
2. **What is the second, Compose-based target?** The plan called for a new
   `deployment/compose/docker-compose.prod.yml`, but the repo had meanwhile
   grown a `deployment/apache-VM/` path that reuses the *root dev* compose via a
   generated loopback override.

Both the numbered manifests and the dev compose were already close to the
plan's intent, so the decision was about shape, not from-scratch construction.

## Decision

SaintAugustin v0.2 supports **three** deployment paths, and **Helm is
deferred**:

| Path | Directory | Shape |
| --- | --- | --- |
| Kubernetes (recommended) | `deployment/kubernetes/` | Numbered, apply-in-order manifests. NodePort (no Ingress). **No Helm chart.** |
| Production Docker Compose | `deployment/compose/docker-compose.prod.yml` | Standalone single-host stack: pinned image tags (no build contexts), fail-closed required secrets (`${VAR:?}`), one-shot `migrate` service, health checks + `restart: unless-stopped`, named volumes. |
| Apache-VM | `deployment/apache-VM/` | Single VM; Apache terminates TLS in front of the **root dev** compose + a loopback override. |

- **The numbered manifests remain the K8s source of truth.** `charts/` stays a
  placeholder; a Helm chart is a future ADR if/when multi-environment
  templating is actually needed.
- **The production compose is separate from the dev compose**, not a
  refactor of it — the dev compose keeps its build contexts and friendly
  defaults for `docker compose up` on a laptop.
- **Parity is a requirement across paths:** all deploy the same three app
  images (`calebfonyuy/staug-{api,projection,frontend}` at `APP_VERSION`) plus
  PostgreSQL/Redis/MinIO, run the queue worker, and apply the MinIO `exports/`
  48h lifecycle rule (FR-DF-3) in their `minio-init` step.

## Consequences

- New deployment-affecting env (`STAUG_SIGNING_KEY`, `MAIL_*`,
  `BIBLE_API_BASE`, `QUEUE_CONNECTION`) is represented in every env layer:
  the K8s ConfigMap/Secret, `deployment/compose/.env.prod.example`, the root
  `.env.example`, and `services/auth/.env.example`.
- Production images are pinned; a private-registry `imagePullSecrets` entry is
  documented as a commented stub rather than wired, since the images are public
  today.
- The `exports/` lifecycle rule is now identical across dev compose, prod
  compose, and the K8s `minio-init` Job; `docs/staug-format.md` no longer
  describes the K8s rule as pending.
- If the v1.2 microservice split (see [ADR-0001](0001-unified-api-as-content-service.md))
  is ever revisited, or a Helm chart is introduced, each is its own ADR — this
  document only records the v0.2 topology.
