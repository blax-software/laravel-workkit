# Blax Software — Laravel Dockerization & Deployment Principles

This document is the single source of truth for how every Blax Software
Laravel **application** (not package) is containerized and deployed. It is
the application-side companion to
[[laravel-composer-packages]] — packages describe a library's contract,
this describes how a host app actually runs in dev and in production.

Two flavours of `deploy.sh` exist in the fleet:

- A **canonical** flavour — minimal, do-the-right-thing script that covers
  composer-install / migrate / cache / restart workers. Use this by
  default.
- An **extended** flavour — same script plus optional version tagging,
  pre-deploy encrypted DB backups, and a hard WebSocket restart with
  liveness verification. Adopt it when the app needs those features.

If you are creating a new Laravel app, copy these conventions verbatim. If
an app deviates, justify it inline (README) and ideally fold the
improvement back here.

---

## 1. Use the shared `blaxsoftware/laravel` image — never a custom Dockerfile

Every Laravel app runs on a tag of `blaxsoftware/laravel`:

```yaml
services:
  app:
    image: blaxsoftware/laravel:laravel13-php8.4
```

The image bakes in nginx + php-fpm + supervisor, the PHP extensions every
Blax project needs, composer, and the supervisor scaffolding that the
`ENABLE_*` flags below switch on. There is **no per-project `Dockerfile`**
and no `build:` block in compose — the image is the contract, every app
gets the same base, upgrades happen by bumping the tag.

### Tag scheme

```
blaxsoftware/laravel:laravel<MAJOR>-php<X.Y>
```

Examples that exist today:

| Tag | Use for |
|---|---|
| `laravel13-php8.4` | New apps (Laravel 13, PHP 8.4) |
| `laravel12-php8.4` | Apps on Laravel 12 |
| `laravel11-php8.4` | Apps on Laravel 11 |
| `laravel10-php8.3` | Legacy Laravel 10 apps |

Always pin a specific framework + PHP tag (`laravel13-php8.4`), never
`latest`. The matrix is rebuilt centrally; bump the tag in your
`docker-compose.yml` when you upgrade Laravel.

### Forbidden

- A `Dockerfile` in the project root that `FROM`s `php:8.x-fpm` and
  re-installs nginx/supervisor/extensions. That re-invents the image, drifts
  away from the fleet, and breaks the "bump one tag, all apps stay current"
  story. A per-project `Dockerfile` plus `build: ./docker` block is the
  anti-pattern; migrate any app still doing this (see §9).
- `build:` keys in compose pointing at a per-project Dockerfile.
- Installing extra system packages via container-side `apt` in deploy
  scripts. If you need an extension that isn't in the base image, raise it
  on `blaxsoftware/laravel` and bake it into the image.

### Why

One image, one supervisor config, one PHP build. Every app upgrade is a
tag change, every fleet-wide CVE patch is one image rebuild. Custom
Dockerfiles in each repo guarantee they will drift — different PHP minor
versions, different ext list, different OS base. We stopped doing that.

---

## 2. Process management via `ENABLE_*` env flags

The image's supervisor reads a small set of env vars at boot and starts
the corresponding workers. **Don't write your own supervisor entries for
these** — flip the flag and the image does it:

| Env var | Effect | Default |
|---|---|---|
| `ENABLE_QUEUE` | Starts `php artisan queue:work` under supervisor | off |
| `ENABLE_SCHEDULER` | Runs `php artisan schedule:run` every minute | off |
| `ENABLE_HORIZON` | Starts `php artisan horizon` (use instead of `ENABLE_QUEUE` when you've adopted Horizon) | off |
| `ENABLE_LARAVEL_PERMS` | On boot, chowns `storage/` + `bootstrap/cache/` to `www-data` so Laravel can write logs/sessions/caches | off |
| `PUSHER_PORT` | Port for the websockets server when you publish a custom supervisor entry that needs it (see §3) | unset |

Canonical app block:

```yaml
app:
  image: blaxsoftware/laravel:laravel13-php8.4
  environment:
    ENABLE_QUEUE: "true"
    ENABLE_SCHEDULER: "true"
    ENABLE_HORIZON: "false"
    ENABLE_LARAVEL_PERMS: "1"
    PUSHER_PORT: "6001"
```

Set `ENABLE_HORIZON: "true"` **instead of** `ENABLE_QUEUE` — never both.

---

## 3. Custom supervisor configs: WebSocket server

The one process the image does NOT start for you is your app's WebSocket
server (it lives in your app, not the base image). Drop the config in
`docker/supervisor/` and mount it to `/etc/supervisor/custom.d`:

```yaml
app:
  volumes:
    - ./:/var/www/html
    - ./docker/supervisor:/etc/supervisor/custom.d
```

`docker/supervisor/websocket.conf`:

```ini
[program:websocket]
command=/usr/local/bin/php -d variables_order=EGPCS /var/www/html/artisan websockets:serve --host=0.0.0.0 --port=6001
autostart=true
autorestart=true
user=www-data
priority=30
startsecs=5
startretries=100
stopsignal=TERM
stopwaitsecs=15
stdout_logfile=/proc/1/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/proc/1/fd/2
stderr_logfile_maxbytes=0
```

Notes:

- `user=www-data` is non-negotiable — Laravel's storage permissions are
  set up for `www-data` by `ENABLE_LARAVEL_PERMS`.
- Logs go to `/proc/1/fd/{1,2}` so `docker logs app` shows the WS output
  inline with nginx/php-fpm.
- `priority=30` runs the WS after php-fpm/nginx (priority 10/20) so the
  app routes that the WS auth callback hits are already serving.

Anything else you need (custom workers, one-off daemons) follows the same
pattern: one file per program in `docker/supervisor/`.

---

## 4. Compose file split: `docker-compose.yml` (prod-shape) + override (local-only)

Two compose files, each with one job:

- **`docker-compose.yml`** — the production deployment shape. HTTP only,
  no port exposes, traefik on the plain `web` entrypoint. Committed.
  This is the *only* file the deploy script loads.
- **`docker-compose.override.yml`** — local-dev additions: mkcert TLS labels
  on traefik's `websecure` entrypoint, a `mysql ports: 3306` expose so you
  can connect with TablePlus. Auto-loaded by `docker compose up`, **not**
  loaded by `docker compose -f docker-compose.yml …`. Committed.

The production deploy script always uses `-f docker-compose.yml` explicitly
to ensure the override is skipped on the server. Local devs run `docker
compose up` (no `-f`) and get both files merged automatically.

> Alternative pattern: a third file `docker-compose.local.yml` exists in
> place of the auto-loaded override, and developers opt in via
> `COMPOSE_FILE` env or explicit `-f` flags. This works but is
> friction-heavy; **prefer the override.yml auto-load pattern** unless you
> have a specific reason (e.g. CI needs a clean compose without dev labels).

### Reference: `docker-compose.yml`

```yaml
networks:
  web:
    external: true
  internal:
    driver: bridge

# YAML anchor — all production traefik labels declared once, reused across
# services. Each Host() rule accepts BOTH the prod hostname AND the local
# *.localhost.at dev alias, so this file works on both sides; the
# override.yml adds the websecure (mkcert TLS) routers on top for local.
#
# In production the app sees plain HTTP only — upstream nginx terminates
# TLS and proxies through to traefik, and traefik's web entrypoint owns
# the HTTP→HTTPS redirect logic. So declaring entrypoints: web here is
# sufficient end-to-end on the prod host.
x-traefik-labels: &traefik-labels
  traefik.enable: "true"
  traefik.docker.network: "web"

  # App HTTP
  traefik.http.routers.<app>.rule: "Host(`<prod-hostname>`) || Host(`<app>.localhost.at`)"
  traefik.http.routers.<app>.entrypoints: "web"
  traefik.http.routers.<app>.service: "<app>-http"
  traefik.http.services.<app>-http.loadbalancer.server.port: "80"

  # App WebSocket
  traefik.http.routers.<app>-ws.rule: "Host(`ws-<prod-hostname>`) || Host(`ws-<app>.localhost.at`)"
  traefik.http.routers.<app>-ws.entrypoints: "web"
  traefik.http.routers.<app>-ws.service: "<app>-ws"
  traefik.http.services.<app>-ws.loadbalancer.server.port: "6001"

services:
  app:
    image: blaxsoftware/laravel:laravel13-php8.4
    container_name: <app>-app
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - ./:/var/www/html
      - ./docker/supervisor:/etc/supervisor/custom.d
    environment:
      ENABLE_QUEUE: "true"
      ENABLE_SCHEDULER: "true"
      ENABLE_HORIZON: "false"
      ENABLE_LARAVEL_PERMS: "1"
      PUSHER_PORT: "6001"
    networks: [web, internal]
    depends_on:
      mysql: { condition: service_healthy }
      redis: { condition: service_healthy }
    labels:
      <<: *traefik-labels

  mysql:
    image: mysql:8.0
    container_name: <app>-mysql
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: "${DB_PASSWORD:-secret}"
      MYSQL_DATABASE: "${DB_DATABASE:-<app>}"
    volumes:
      - ./docker-data/mysql:/var/lib/mysql
    networks: [internal]
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-p${DB_PASSWORD:-secret}"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    container_name: <app>-redis
    restart: unless-stopped
    volumes:
      - ./docker-data/redis:/data
    networks: [internal]
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5
```

### Reference: `docker-compose.override.yml` (local dev only)

```yaml
# Local-dev only — mkcert TLS on traefik's websecure entrypoint, mysql
# port expose. Auto-loaded by `docker compose up`; deploy.sh uses
# `-f docker-compose.yml` explicitly so this file is ignored in prod.
services:
  app:
    labels:
      traefik.http.routers.<app>-tls.rule: "Host(`<app>.localhost.at`)"
      traefik.http.routers.<app>-tls.entrypoints: "websecure"
      traefik.http.routers.<app>-tls.tls: "true"
      traefik.http.routers.<app>-tls.service: "<app>-https"
      traefik.http.services.<app>-https.loadbalancer.server.port: "80"

      traefik.http.routers.<app>-wss.rule: "Host(`ws-<app>.localhost.at`)"
      traefik.http.routers.<app>-wss.entrypoints: "websecure"
      traefik.http.routers.<app>-wss.tls: "true"
      traefik.http.routers.<app>-wss.service: "<app>-wss"
      traefik.http.services.<app>-wss.loadbalancer.server.port: "6001"

  mysql:
    ports:
      - "3387:3306"   # pick a unique host port per app to avoid collisions
```

---

## 5. Traefik conventions, hostnames, and TLS

**Routing fronts every app.** No app ever publishes 80/443 directly —
traefik does, on the shared `web` Docker network. Apps just declare
labels.

### The shared `web` network

`web` is an externally-created bridge network on each host (dev laptop +
prod server) where traefik listens for containers with
`traefik.enable=true`. Every app's `app` service joins it; `mysql` /
`redis` / other backing services stay on the per-app `internal` network.

If `web` doesn't exist on a fresh box, create it once:

```bash
docker network create web
```

### Hostname conventions

- **Local development** uses subdomains of `localhost.at`. The domain has
  a wildcard A record pointing at `127.0.0.1`, so anything like
  `<app>.localhost.at` or `ws-<app>.localhost.at` resolves locally
  without `/etc/hosts` edits. Devs install a mkcert-signed wildcard cert
  for `*.localhost.at` once and traefik terminates TLS on the
  `websecure` entrypoint using it.
- **Production hostnames are app-defined** in the `Host()` rule on each
  router — typically a subdomain of `blax.at` (e.g.
  `api-<thing>.blax.at`, `ws-api-<thing>.blax.at`), but the rule is the
  only source of truth. Use whatever public hostname the app is
  reachable at.

The same router rule accepts both the prod hostname and the local
`localhost.at` alias with an `||`, so one set of labels works in both
environments:

```
Host(`<prod-hostname>`) || Host(`<app>.localhost.at`)
```

### Two entrypoints, two roles

- `web` — plain HTTP, port 80.
- `websecure` — TLS, port 443.

Every router in `docker-compose.yml` declares `entrypoints: web`. The
`websecure` routers live in the override and only matter on a dev box
(see "TLS termination" below for why this is OK in prod).

A third `mobile` entrypoint exists on some hosts for Android-emulator
access (`10.0.2.2`, no HTTPS redirect). Add this only if you genuinely
need emulator traffic; for normal apps the two-entrypoint setup is
enough.

### TLS termination

The TLS termination point is different between environments, and that's
intentional:

- **Local dev**: traefik terminates TLS itself using the mkcert wildcard
  for `*.localhost.at`. The `websecure` routers from
  `docker-compose.override.yml` carry the `tls: "true"` label and traefik
  serves the cert. The `web` entrypoint also exists for the plain-HTTP
  alias if you need it.
- **Production**: an upstream nginx terminates TLS using the real
  certificate, then proxy-passes plain HTTP to traefik. Traefik in turn
  manages the HTTP-to-HTTPS redirect for any client that arrived over
  HTTP. The result, viewed from the app container: incoming traffic is
  always plain HTTP on port 80 regardless of how the client connected,
  and the redirect logic lives in the traefik entrypoint configuration
  on the prod host, not in the app's compose file.

The practical consequence for your compose files: `docker-compose.yml`
only declares `web`-entrypoint routers, and that's enough for prod
because the entire `https → http → traefik → app` chain is handled
upstream. `docker-compose.override.yml` adds the `websecure` routers so
that local dev gets working HTTPS without an extra nginx hop.

### Router naming scheme

| Router suffix | What it serves | Where declared |
|---|---|---|
| `<app>` | App HTTP on port 80 | `docker-compose.yml` |
| `<app>-ws` | WebSocket plain on port 6001 | `docker-compose.yml` |
| `<app>-tls` | App HTTPS (mkcert, local only) | `docker-compose.override.yml` |
| `<app>-wss` | WebSocket secure (mkcert, local only) | `docker-compose.override.yml` |

The router *names* must be globally unique across all apps on a host —
that's why each one starts with the app's slug.

### Forbidden

- An app service publishing `ports: [80:80]` or `ports: [443:443]`. Use
  traefik labels instead.
- Putting TLS / `websecure` labels in `docker-compose.yml`. TLS in this
  file would force traefik to attempt cert handshakes in prod, which is
  upstream nginx's job. TLS labels live in the override (local dev) only.
- Hardcoding `loadbalancer.server.port: 6001` for the app's HTTP router.
  Port 80 for HTTP, port 6001 for the WS service — don't mix them up.

---

## 6. Persistent data: `./docker-data/` in the repo, gitignored

Bind-mount mysql and redis storage to a `docker-data/` folder right next
to the source:

```
docker-data/
  mysql/        # mysql:8.0 datadir
  redis/        # redis dump.rdb
```

The folder is gitignored (`docker-data/` in `.gitignore`). It survives
`docker compose down`. The deploy script `mkdir -p`s it on first run so
fresh boxes Just Work.

### Why not named docker volumes?

- Trivially backed up — `tar -caf data.tar.xz docker-data/` from the
  repo root is the entire prod state.
- Survives container/image churn including accidental
  `docker volume prune -af`.
- Discoverable — anyone with the repo can see where the data lives.
- The repo path identifies which app owns the data when you have ten
  apps on one host.

---

## 7. The `deploy.sh` script — canonical pattern

Every app has a `deploy.sh` at the repo root that runs end-to-end deploys.
Use the **canonical** flavour below as the default; use the **extended**
flavour when you also need version tagging, a pre-deploy DB backup, or a
hard WebSocket restart with liveness verification (see §7.3).

### 7.1 Canonical deploy.sh

```bash
#!/bin/bash

set -e

SELF="$0"
COMPOSE_CMD=(docker compose -f docker-compose.yml)

# ── Parse arguments ──────────────────────────────────────────────────

FLAG=""

for arg in "$@"; do
    case "$arg" in
        --after-pull|--force-recreate)
            FLAG="$arg" ;;
        *) ;;
    esac
done

echo "==> Starting deploy script..."

# ── Self-update check (first run only) ───────────────────────────────

if [ "$FLAG" != "--after-pull" ] && [ "$FLAG" != "--force-recreate" ]; then
    echo "==> Checking for updates..."

    ORIGINAL_HASH=$(md5sum "$SELF" | cut -d' ' -f1)

    git pull --rebase --stat

    UPDATED_HASH=$(md5sum "$SELF" | cut -d' ' -f1)

    PASSTHROUGH_ARGS=("--after-pull")

    if [ "$ORIGINAL_HASH" != "$UPDATED_HASH" ]; then
        echo "==> Script updated — re-running..."
        echo
        "$SELF" "${PASSTHROUGH_ARGS[@]}"
        exit $?
    fi

    echo "==> No script changes detected."
    echo
    "$SELF" "${PASSTHROUGH_ARGS[@]}"
    exit $?
fi

echo "==> Running deployment steps..."

# ── Deployment steps ─────────────────────────────────────────────────

REPO_UID=$(stat -c '%u' . 2>/dev/null || id -u)
REPO_GID=$(stat -c '%g' . 2>/dev/null || id -g)

DEPLOY_UID=${DEPLOY_UID:-$REPO_UID}
DEPLOY_GID=${DEPLOY_GID:-$REPO_GID}

APP_EXEC=("${COMPOSE_CMD[@]}" exec -T -u "${DEPLOY_UID}:${DEPLOY_GID}" app)

echo "==> Using production compose file only (docker-compose.yml)."
echo "==> Deployment user in app container: ${DEPLOY_UID}:${DEPLOY_GID}"

echo "==> Preparing writable directories..."
mkdir -p \
    vendor \
    bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    docker-data/mysql \
    docker-data/redis

if [ "$(id -u)" -eq 0 ]; then
    echo "==> Fixing ownership on writable directories (root mode)..."
    chown -R "${DEPLOY_UID}:${DEPLOY_GID}" \
        vendor \
        bootstrap/cache \
        storage \
        docker-data \
        2>/dev/null || true
else
    echo "==> Skipping host chown (not root)."
fi

echo "==> Ensuring containers are up before exec..."
"${COMPOSE_CMD[@]}" up -d mysql redis
"${COMPOSE_CMD[@]}" up -d --no-deps app

echo "==> Preparing git/composer environment in container..."
"${APP_EXEC[@]}" bash -c "export HOME=/tmp; git config --global --add safe.directory /var/www/html || true"
"${APP_EXEC[@]}" bash -c "mkdir -p /var/www/html/vendor /var/www/html/bootstrap/cache /var/www/html/storage"

echo "==> Installing composer dependencies..."
"${APP_EXEC[@]}" bash -c \
    "export HOME=/tmp; git config --global --add safe.directory /var/www/html || true; COMPOSER_HOME=/tmp/composer-home COMPOSER_CACHE_DIR=/tmp/composer-cache XDG_CACHE_HOME=/tmp composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev"

echo "==> Running migrations..."
"${APP_EXEC[@]}" bash -c "php artisan migrate --force"

echo "==> Caching config/routes/views..."
"${APP_EXEC[@]}" bash -c "php artisan config:cache && php artisan route:cache && php artisan view:cache"

echo "==> Rebuilding/starting containers..."

if [ "$FLAG" == "--force-recreate" ]; then
    "${COMPOSE_CMD[@]}" up -d --force-recreate --build app
else
    "${COMPOSE_CMD[@]}" up -d --no-deps --build app

    echo "==> Restarting queue worker (picks up new code)..."
    "${APP_EXEC[@]}" php artisan queue:restart

    echo "==> Sending hard restart signal to WebSocket server..."
    "${APP_EXEC[@]}" php artisan websocket:steer restart || true

    echo "==> WebSocket will restart within ~5 seconds (supervisor auto-restarts)."
fi

echo "==> Deployment complete!"
```

### 7.2 What each step does (and why)

1. **Self-update via md5sum + `--after-pull`** — the script `git pull`s
   the repo, hashes itself before and after, and if the script file
   changed, *re-executes its new copy* with `--after-pull`. This means
   improvements to deploy.sh are picked up on the next deploy without a
   "remember to re-run after the pull" footgun. The new copy gets the
   flag so it skips the pull step.
2. **Production compose only** — `COMPOSE_CMD=(docker compose -f
   docker-compose.yml)`. No override.yml on prod.
3. **UID/GID detection** — uses the repo owner's UID/GID by default
   (overridable via `DEPLOY_UID`/`DEPLOY_GID` env). Container processes
   that touch the bind-mounted source run as that UID so file ownership
   stays consistent. Prevents the classic "host can't edit a file that
   container wrote as UID 33" problem.
4. **`mkdir -p` writable dirs** — ensures the bind-mount points exist
   *before* the container starts. Docker would otherwise create them as
   root.
5. **Conditional chown** — only runs when the script is invoked as root
   (e.g. by the deploy webhook). When a developer runs it manually as
   their own user, the chown is skipped (it would fail anyway).
6. **Up `mysql` + `redis` first, then `app`** — `--no-deps` on `app`
   means we don't restart the DB just because the app code changed.
7. **`composer install --no-dev`** inside the container with
   `HOME=/tmp` and isolated composer dirs — avoids touching anything
   under `/var/www/html/.composer` (which would be on the bind-mounted
   host filesystem) and avoids "dubious ownership" git errors.
8. **`migrate --force`** — `--force` is required because the artisan
   prompt detects non-TTY and would otherwise abort.
9. **`config:cache && route:cache && view:cache`** — production
   optimizations. Note these run *after* `migrate` so any migration-
   triggered config change is captured.
10. **`--no-deps --build app`** — rebuild the app container but don't
    touch mysql/redis. Switches the running container atomically.
11. **`queue:restart`** — sets the `illuminate:queue:restart` cache
    timestamp; running workers see it on their next loop and exit, and
    supervisor restarts them with the new code.
12. **`websocket:steer restart`** — Blax's `laravel-websockets` package
    polls the cache for a restart sentinel. The new WS process picks up
    the new code; old clients reconnect automatically.

### 7.3 Optional add-ons (extended flavour)

Add these if the app needs them. They sit between the composer-install
and the rebuild steps:

- **Pre-deploy DB backup** via `php artisan workkit:db:backup` (encrypted
  dump to `storage/backups/`). `set -e` means a failed backup aborts the
  deploy — we'd rather block than push migrations forward without a
  recovery point. Restore via `php artisan workkit:db:restore`.
- **Version tagging** — `--patch` / `--minor` / `--major` / `--version=X.Y.Z`
  flags bump a `vX.Y.Z` git tag and push it before the deploy steps run.
  Also maintains a moving `deploy` tag that always points at the last
  successful deploy.
- **WebSocket hard-restart with verify** — `php artisan
  websocket:restart-hard` (sends SIGTERM directly to the
  `websockets:serve` PID, escalates to SIGKILL if needed) followed by a
  `pgrep` check that the process came back. Fails the deploy loudly if
  the WS server isn't running afterwards.

Don't adopt these unless the app has the matching infrastructure — the
`workkit:db:backup` artisan command (from `blax-software/laravel-workkit`)
and the `websocket:restart-hard` command (from
`blax-software/laravel-websockets`).

### 7.4 Calling deploy.sh from anywhere

The first execution does `git pull` and re-execs itself, so the script
doesn't care which commit it starts on — old or new. Just:

```bash
ssh prod cd /srv/<app> && bash deploy.sh
```

Or wire it to a webhook / CI job. The `--after-pull` flag is internal —
don't pass it manually.

---

## 8. Local dev: same compose, traefik + mkcert

Once a developer has a single host-level setup in place (traefik on the
`web` network, mkcert wildcard for `*.localhost.at`), every Blax Laravel
app behaves identically:

```bash
# First time on a new repo
docker compose up -d           # picks up docker-compose.yml + override.yml
docker compose exec app composer install
docker compose exec app php artisan migrate

# Browse at https://<app>.localhost.at
# WebSocket at wss://ws-<app>.localhost.at
```

`docker compose up` auto-loads the override.yml because the file exists
in the repo root. No flags needed.

### Updating to a newer image

Bump the tag in `docker-compose.yml` (e.g. `laravel12-php8.4 →
laravel13-php8.4`), then:

```bash
docker compose pull app
docker compose up -d --force-recreate --build app
```

No rebuild step, no Dockerfile edits — the image is the only source of
binary changes.

---

## 9. Migration path: existing apps still on a custom Dockerfile

If you find an app with a per-project `Dockerfile` and a `build:` block,
migrate it to the shared image in this order:

1. Replace the `build: ./docker` block with `image:
   blaxsoftware/laravel:laravel<N>-php<X.Y>`.
2. Delete the `Dockerfile` and the `./docker/Dockerfile` build directory
   (keep `./docker/supervisor/` — the supervisor configs still apply).
3. Add the `ENABLE_*` env vars to the `app` service (§2). Remove any
   supervisor entries for queue/scheduler that are now redundant.
4. Split the existing compose into `docker-compose.yml` (prod-shape) +
   `docker-compose.override.yml` (local TLS + port exposes). Move
   `websecure` / `wss` routers into the override.
5. Replace the existing deploy script with the canonical one from §7.1.
6. Move data volumes onto `./docker-data/*` bind mounts (§6) if they
   aren't already. Use `docker cp` from the old volume into the new
   bind-mount path before tearing down.
7. `docker compose down && docker compose up -d --force-recreate`.

The data survives because mysql/redis storage is now a bind-mount on
disk, not a named volume tied to the old image.

---

## Checklist for a new Blax Laravel app

- [ ] App service uses `image: blaxsoftware/laravel:laravel<N>-php<X.Y>`
      — no `build:`, no per-project Dockerfile.
- [ ] `ENABLE_QUEUE`, `ENABLE_SCHEDULER`, `ENABLE_LARAVEL_PERMS` are set
      explicitly on the `app` service (true/false strings).
- [ ] WebSocket process declared in `docker/supervisor/websocket.conf`,
      mounted at `/etc/supervisor/custom.d`. No supervisor entries
      duplicated for queue/scheduler/horizon (the image owns those).
- [ ] `docker-compose.yml` describes the **production** shape — HTTP only,
      traefik labels on `web` entrypoint, no port exposes, mysql/redis
      data bind-mounted under `./docker-data/`.
- [ ] `docker-compose.override.yml` adds **local-only** TLS routers on
      `websecure` and any host-port exposes (e.g. `mysql 3306`). No
      duplicated production labels.
- [ ] Traefik labels declared once via `&traefik-labels` YAML anchor;
      each `Host()` rule accepts both the prod hostname AND the
      `<app>.localhost.at` dev alias.
- [ ] External `web` network referenced for traefik traffic; internal
      `internal` bridge network for mysql/redis. Backing services are
      NOT on `web`.
- [ ] `docker-data/` is in `.gitignore`. The deploy script `mkdir -p`s
      it on first run.
- [ ] `deploy.sh` is the canonical script from §7.1 plus any extended
      add-ons from §7.3 you actually need.
- [ ] deploy.sh self-update via md5sum + `--after-pull` is intact —
      don't strip it.
- [ ] deploy.sh runs `composer install --no-dev`, `migrate --force`,
      `config:cache && route:cache && view:cache`, then `queue:restart`
      and `websocket:steer restart`.
- [ ] No app service publishes ports 80/443. Routing goes through
      traefik labels exclusively.
- [ ] Bumping Laravel version is a one-line tag change in
      `docker-compose.yml` + `docker compose pull && up -d
      --force-recreate --build app` — never a Dockerfile edit.
