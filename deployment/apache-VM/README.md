# Apache-VM Deployment

Bash scripts for putting SaintAugustin on a single Linux VM behind
Apache. Use this when a Kubernetes cluster is overkill — for example,
a single-parish install on a 2 vCPU / 4 GB VPS.

> **Heads up**: Kubernetes is the supported production path. This route
> is documented because it's pragmatic, not because it's preferred.
> See [`../README.md`](../README.md).

## Architecture

```
        ┌──────────────────────────── VM ─────────────────────────────┐
        │                                                             │
   :80 ─┤  Apache   ┌─→ DOMAIN_APP        → 127.0.0.1:5173 (frontend) │
        │           ├─→ DOMAIN_API        → 127.0.0.1:8001 (auth)     │
        │           └─→ DOMAIN_PROJECTION → 127.0.0.1:3000 (projection│
        │                                   + WebSocket upgrade)      │
        │                                                             │
        │  Docker Compose (from repo root):                           │
        │    frontend  • auth-service  • projection-service           │
        │    postgres  • redis         • minio                        │
        └─────────────────────────────────────────────────────────────┘
```

The application services bind to **loopback only** (the deploy script
generates a `docker-compose.override.yml` that does this). Apache is
the single entrypoint exposed to the network.

## Files

| File                     | Purpose                                           |
| ------------------------ | ------------------------------------------------- |
| `config.example.sh`      | Template — copy to `config.sh` and edit.          |
| `install-prereqs.sh`     | One-shot: installs Docker + Apache + modules.     |
| `apache-vhosts.sh`       | Writes the three vhost files and reloads Apache.  |
| `deploy.sh`              | Pulls code, writes `.env` + override, runs compose up. |
| `teardown.sh`            | `docker compose down` (add `--purge` to drop volumes). |

## First-time setup

Order matters: prerequisites → DNS → vhosts → deploy.

```bash
# 1. Bootstrap the VM (run as root)
sudo ./install-prereqs.sh

# 2. Configure
cp config.example.sh config.sh
$EDITOR config.sh             # set DOMAIN_*, APP_KEY, passwords
source config.sh

# 3. Point DNS at the VM
#    A  app.example.com         → <VM IP>
#    A  api.example.com         → <VM IP>
#    A  projection.example.com  → <VM IP>

# 4. Generate Apache vhosts
sudo -E ./apache-vhosts.sh    # -E so DOMAIN_* survive sudo

# 5. Deploy the stack (run as the user that owns INSTALL_DIR)
./deploy.sh
```

After the deploy script finishes, hit:

- `http://app.example.com/`             — Vue SPA
- `http://api.example.com/health`       — Laravel health probe
- `http://projection.example.com/health` — NestJS health probe

## Day-2 operations

```bash
# Update to latest commit on the deployment branch
source config.sh && ./deploy.sh

# Tail container logs
cd $INSTALL_DIR && docker compose logs -f

# Run an ad-hoc artisan command
cd $INSTALL_DIR && docker compose exec auth-service php artisan tinker

# Inspect the database
cd $INSTALL_DIR && docker compose exec postgres \
  psql -U "$POSTGRES_USER" saintaugustin_db
```

## Tear down

```bash
source config.sh
./teardown.sh           # stop containers, keep data
./teardown.sh --purge   # stop AND drop postgres + minio volumes
```

Apache vhost files remain in `/etc/apache2/sites-available`; disable
with `sudo a2dissite saintaugustin-*` and reload Apache.

## Caveats

- **Plain HTTP**: no TLS. For a real domain, run `certbot --apache -d
  $DOMAIN_APP -d $DOMAIN_API -d $DOMAIN_PROJECTION` after step 4.
- **Backups**: not automated. The `postgres_data` and `minio_data`
  Docker volumes hold the only copies — snapshot them on a schedule.
- **Single point of failure**: one VM, one Apache, one Postgres
  container. Acceptable for parish-scale traffic; not for multi-tenant.
- **Firewall**: open 80 (and 443 once TLS is on) on the VM. The
  application services listen on loopback only, so they don't need
  external rules.
