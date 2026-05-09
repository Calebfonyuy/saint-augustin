# SaintAugustin — Deployment

Two deployment paths are supported. **Kubernetes is the primary method**;
the Apache-VM path is provided for smaller installs (homelab, single-VPS,
parishes without cluster infrastructure) where a full K8s setup is
overkill.

| Path                       | When to use                                                                 |
| -------------------------- | --------------------------------------------------------------------------- |
| [`kubernetes/`](kubernetes/) (recommended) | Production, multi-replica, autoscaling, rolling updates. Requires a running cluster + ingress controller + container registry. |
| [`apache-VM/`](apache-VM/) | A single Linux VM with Docker + Apache. Quick to stand up; suitable for one-parish installs. |

Both paths bring up the same six components:

- **frontend** — Vue 3 SPA (static, served via nginx in the prod image)
- **auth-service** — Laravel 12 + FrankenPHP (REST + Sanctum tokens)
- **projection-service** — NestJS 10 (Socket.IO + REST for live projection)
- **postgres** — PostgreSQL 16 (auth-service data)
- **redis** — Redis 7 (cache, queue, projection state)
- **minio** — S3-compatible object storage (song-sheet uploads)

See each subdirectory's `README.md` for prerequisites, configuration, and
the apply/teardown flow.

## What's *not* in this directory

- **TLS / certificates** — both paths run plain HTTP. Add `cert-manager`
  (K8s) or `certbot --apache` (VM) when wiring up a real domain.
- **Backups** — neither path snapshots the postgres / minio volumes. Wire
  up a CronJob (K8s) or systemd timer (VM) before going to production.
- **Observability** — no Prometheus / Loki / log shipping. The services
  emit JSON logs to stdout; collect them at the platform layer.
- **CI** — image building is manual via `kubernetes/scripts/build-and-push.sh`.
  Hook it into your CI of choice.
