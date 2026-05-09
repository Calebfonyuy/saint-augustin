# Kubernetes Deployment

Plain manifests + Kustomize overlays. No Helm, no operators — just
`kubectl apply -k`.

```
kubernetes/
├── base/                    # core manifests, shared by every env
│   ├── namespace.yaml
│   ├── configmap.yaml       # non-secret env
│   ├── secrets.example.yaml # template — copy to secrets.yaml
│   ├── postgres.yaml        # StatefulSet + headless Service + PVC
│   ├── redis.yaml           # Deployment + Service + PVC
│   ├── minio.yaml           # Deployment + Service + PVC + bucket Job
│   ├── auth-service.yaml    # Deployment + Service + migration Job
│   ├── projection-service.yaml
│   ├── frontend.yaml
│   ├── ingress.yaml         # /api, /api/projection, /socket.io, /
│   └── kustomization.yaml
├── overlays/
│   ├── dev/                 # single-replica, debug on, dev hostname
│   └── prod/                # multi-replica, real hostname, real tag
└── scripts/
    ├── build-and-push.sh    # builds the three images
    └── apply.sh             # kubectl apply -k <overlay>
```

## Prerequisites

- A reachable Kubernetes cluster (`kubectl config current-context`)
- An ingress controller installed in the cluster
  (manifests assume `ingressClassName: nginx`)
- A container registry the cluster can pull from
- `kubectl` ≥ 1.21 (Kustomize is built in)

## First-time setup

1. **Populate the secrets**
   ```bash
   cp base/secrets.example.yaml base/secrets.yaml
   # generate APP_KEY:
   docker run --rm php:8.3-cli-alpine \
     php -r "echo 'base64:'.base64_encode(random_bytes(32));"
   # generate everything else with `openssl rand -base64 32`
   $EDITOR base/secrets.yaml
   ```
   `base/secrets.yaml` is in `.gitignore` — keep it that way.

2. **Build and push the images**
   ```bash
   REGISTRY=ghcr.io/your-user TAG=v1.0.0 \
   API_BASE=http://saintaugustin.example.com/api \
   PROJECTION_BASE=http://saintaugustin.example.com \
     ./scripts/build-and-push.sh
   ```
   The frontend bakes the API URLs into the bundle at build time, so
   rebuild it whenever the public hostname changes.

3. **Update the overlay**

   Edit `overlays/<env>/kustomization.yaml` and replace `REGISTRY`
   with your actual registry. Edit `overlays/<env>/ingress-patch.yaml`
   and `overlays/<env>/configmap-patch.yaml` to use your hostname.

4. **Apply**
   ```bash
   ./scripts/apply.sh dev    # or prod
   kubectl -n saintaugustin get pods -w
   ```

## Day-2 operations

| Task                                | Command                                                                 |
| ----------------------------------- | ----------------------------------------------------------------------- |
| Roll out a new image tag            | bump `newTag:` in the overlay, `kubectl apply -k overlays/<env>`        |
| Rerun migrations against a new tag  | the auth-migrate Job runs automatically when the tag changes            |
| Tail auth-service logs              | `kubectl -n saintaugustin logs -l app=auth-service -f`                  |
| Open a Laravel shell                | `kubectl -n saintaugustin exec -it deploy/auth-service -- sh`           |
| Run a one-off artisan command       | `kubectl -n saintaugustin exec deploy/auth-service -- php artisan tinker` |
| Shell into Postgres                 | `kubectl -n saintaugustin exec -it postgres-0 -- psql -U saintaugustin saintaugustin_db` |
| MinIO web console (port-forward)    | `kubectl -n saintaugustin port-forward svc/minio 9001:9001`             |

## Tear down

```bash
kubectl delete -k overlays/<env>
kubectl delete namespace saintaugustin
```

The PVCs (postgres, redis, minio data) are deleted with the namespace.
**Snapshot them first** if you care about the data.

## Caveats

- **Migrations**: the `auth-migrate` Job uses the same image tag as the
  Deployment. On `kubectl apply -k`, the Job is recreated only if its
  spec changed; you may need to `kubectl delete job/auth-migrate` to
  re-run migrations against the same tag.
- **Socket.IO + replicas**: scaling `projection-service` past 1 replica
  needs the Redis adapter wired into the NestJS gateway. The Ingress
  has sticky sessions so 2–3 replicas works in practice, but for true
  horizontal scaling, finish the adapter integration first.
- **Postgres / Redis / MinIO HA**: single-replica StatefulSets with
  local PVCs. Fine for one-parish installs; replace with managed
  services or proper operators (CrunchyData / Bitnami) before serving
  multiple parishes from one cluster.
- **TLS**: not configured. Add a `tls:` block to the Ingress and a
  cert-manager `Certificate` once you have a real domain.
