# TO-FIX

Tracker for incoherencies / drift discovered while building features. None of these
are blockers — the workarounds are documented next to each item — but they should
be reconciled when there's a quiet moment.

## 1. `services/auth/.env` drift from `.env.example` and root `.env`

Discovered while seeding song sheets (Phase 2). The local `services/auth/.env`
has two values that don't match anything else in the stack:

| Key                 | Local `.env` (wrong)         | Should be                          | Source of truth                |
| ------------------- | ---------------------------- | ---------------------------------- | ------------------------------ |
| `MINIO_ENDPOINT`    | `http://localhost:9000`      | `http://minio:9000`                | `services/auth/.env.example`   |
| `MINIO_SECRET_KEY`  | `minioadmin_changeme`        | `saintaugustin_secure_password`    | root `.env` (`MINIO_ROOT_PASSWORD`) |

**Symptom:** `php artisan db:seed` inside the `auth-service` container fails with
either a connection error (localhost unreachable from the container) or a
`SignatureDoesNotMatch` from MinIO.

**Workaround currently in use:** override inline on the command —
`docker compose exec -e MINIO_ENDPOINT=http://minio:9000 -e MINIO_SECRET_KEY=saintaugustin_secure_password auth-service php artisan db:seed`.

**Fix:** edit `services/auth/.env` to match `.env.example`. The repo's example file
is correct; only the local copy drifted. Do not commit `.env`.

## 2. Frontend ESLint 9 missing flat config

Discovered while wrapping Phase 3. `frontend/package.json` declares a `lint` script
and ships `eslint@^9.0.0` plus `eslint-plugin-vue` and `@vue/eslint-config-typescript`,
but there is **no `eslint.config.js`** (or `.eslintrc.*`) anywhere in `frontend/`.
Running `npm run lint` therefore fails immediately with:

> ESLint couldn't find an eslint.config.(js|mjs|cjs) file.

**Symptom:** the `lint` script has been broken since at least Phase 1; nothing in
CI runs against it and the `--ext` flags it passes are also no longer recognized
in ESLint 9 flat-config mode.

**Workaround currently in use:** none — type-check (`vue-tsc`) is the only
static-analysis gate today, and it's enough for catching the kinds of mistakes
ESLint would have flagged.

**Fix:** create a `frontend/eslint.config.js` using the v9 flat-config format,
wiring up `eslint-plugin-vue`'s flat presets and the typescript preset. Drop the
`--ext` flags from `package.json` once it works (flat config picks up file
extensions from the config itself).
