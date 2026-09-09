# Docker

This repository holds both the **image** (root `Dockerfile`, `docker/php/`) and the
**deployment config** ([`_docker/`](_docker/): compose files, env profiles, nginx
templates, deploy scripts). Host-specific files inside `_docker/` — `env/.env`,
certificates, the generated nginx config, `storage/` contents — are gitignored;
`_docker/` as a whole is excluded from the image build context.

## Image

The root [`Dockerfile`](Dockerfile) is the single source of truth. Runtime config
it copies in comes from [`docker/php/`](docker/php/):

| Path | Purpose |
|---|---|
| `docker/php/config/` | PHP-FPM pool, php.ini (common/dev/prod), supervisord |
| `docker/php/bin/entrypoint.sh` | Entrypoint; sources `boot.local.sh` if the target ships one |
| `docker/php/php.entrypoint.{dev,staging,prod}.sh` | Per-target `boot.local.sh` |
| `docker/php/prepareEnvVariables.php` | `app_prod` only: regenerates `.env` from the container environment |

Targets: `app_dev` (xdebug, composer, host UID/GID), `app_staging` (prod
dependencies, `.env` bind-mounted, `VOLUME /var/www/html/public` for nginx) and
`app_prod` (prod dependencies, `.env` regenerated on boot). Staging **and** prod
run `app_staging`.

## CI

[`.github/workflows/build-docker-image.yml`](.github/workflows/build-docker-image.yml)
builds `app_staging` and pushes to `ghcr.io/stenseegel/hawki`:

| Trigger | Tags | Frontend values |
|---|---|---|
| push to `staging` | `:staging`, `:staging-<sha>` | `.github/docker-build.staging.env` |
| tag `v*` | `:<version>` (+ `:latest` unless `-rc`) | `.github/docker-build.prod.env` |
| workflow_dispatch | `:<profile>-<sha>` | chosen profile |

The `VITE_*` values in the `docker-build.<profile>.env` files are baked into the
frontend bundle at build time and must match the target host's `env/.env`.

## Local development

```bash
cd _docker && ./deploy-dev.sh --build   # builds app_dev, live-mounts this checkout
```

Serves https://app.hawki.dev (app), https://admin.hawki.dev, https://db.hawki.dev
(Adminer) and https://mail.hawki.dev (Mailpit). See `_docker/README.md`.
