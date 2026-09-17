# HAWKI Docker Deployment

Deployment configuration for the JLU HAWKI fork: compose files, environment
profiles, nginx templates and the deploy scripts. It is part of the app repository
(`StenSeegel/HAWKI`) since 2026-09-09 — the former `hawki-docker` repo is retired —
and every script is run from this directory. Host-specific files (`env/.env`,
certificates, `nginx/nginx.default.conf`, `storage/` contents) are gitignored, so a
fresh checkout or worktree needs `env/.env` (see `./env/env-init.sh`) before use.

The **image** is not built here. The app repository's root `Dockerfile` and
`docker/php/` are the single source of truth; its CI
(`.github/workflows/build-docker-image.yml`) pushes `ghcr.io/stenseegel/hawki`:

| Trigger in the app repo | Image tag |
|---|---|
| push to `staging` | `:staging`, `:staging-<sha>` |
| tag `v*` | `:<version>` (+ `:latest` unless `-rc`) |
| workflow_dispatch | `:<profile>-<sha>` |

Only dev builds locally (target `app_dev`, live-mounted code).

## Layout

```
_docker/
├── deploy-dev.sh               dev: build app_dev, live-mount ../.., seed, dev overwrites
├── update-staging.sh           staging + prod: --pull / --update the prebuilt image
├── stop.sh                     stop a stack (--dev|--staging|--auto) [--remove]
├── compose/docker-compose.{dev,staging}.yml
├── env/                        .env.example + .env.{dev,staging} profiles → generated .env
│   ├── env-init.sh             generates env/.env (keys, /etc/hosts + certs for dev)
│   ├── dev-overwrites          app_settings applied on every dev deploy
│   └── dev-cmds                artisan commands run after every dev deploy
├── nginx/                      nginx.template.{dev,staging} → generated nginx.default.conf
├── certs/                      dev certificates (*.hawki.dev), manage-certs.sh
├── config/                     model_providers.php + model_lists mounted read-only into the app
├── scripts/
│   ├── auto-deploy-staging.sh  GHCR digest poller (systemd timer on ki-test)
│   ├── systemd/                unit + timer for the poller
│   ├── create-volumes.sh       external mysql/redis volumes for staging/prod
│   ├── import-backup.sh        load a laravel-backup zip or .sql into a stack / scratch MySQL
│   ├── download-staging-backup.sh
│   ├── apply-dev-overwrites.sh / run-post-deployment-commands.sh   (called by deploy-dev.sh)
│   └── certbot/deploy-hook.sh  copies a renewed cert into certs/ and reloads nginx
├── realtime-bridge/            WebRTC → realtime STT bridge; image built by ../.github/workflows/build-realtime-bridge.yml
├── docs/runbooks/              prod cutover runbook
└── storage/                    persistent app storage bind-mounted into the stacks
```

## Development

```bash
./deploy-dev.sh --build        # first run / after Dockerfile changes: builds app_dev
./deploy-dev.sh                # restart + migrate + seed + dev overwrites
./deploy-dev.sh --init         # regenerate env/.env from env/.env.example + env/.env.dev
./stop.sh --dev
```

`deploy-dev.sh` builds `app_dev` from `../../Dockerfile`, mounts the checkout
read-write into app/queue/reverb/scheduler, runs `composer install`, builds the
frontend on the host with the `REVERB_*` values from `env/.env`, migrates, seeds,
applies `env/dev-overwrites` and runs `env/dev-cmds`.

- https://app.hawki.dev — app · https://admin.hawki.dev — admin (separate session)
- https://db.hawki.dev — Adminer · https://mail.hawki.dev — Mailpit
- hot reload: `npm run dev` in the app repo on the host

## Staging (ki-test)

Promoting a build is a push to the app repo's `staging` branch:

```bash
git push origin dev-local:staging      # CI builds :staging (~10 min)
```

`scripts/auto-deploy-staging.sh` runs from a systemd timer every 5 minutes, compares
the registry digest with the running container and, on change, runs
`update-staging.sh --pull --update` followed by an nginx restart.

```bash
# install on the staging host (as root)
cp scripts/systemd/hawki-staging-autodeploy.* /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now hawki-staging-autodeploy.timer

systemctl list-timers hawki-staging-autodeploy.timer   # next run
tail -f /var/log/hawki-autodeploy.log                  # what it did
./scripts/auto-deploy-staging.sh --force               # roll out right now
./update-staging.sh --pull --update                    # manual rollout
```

**Why not Watchtower?** A HAWKI rollout also has to run migrations in the new
image, drop the `staging_build` volume so the new Vite assets are unpacked (Docker
fills a named volume from the image only while it is empty), and restart nginx,
which reads the app's `public/` through `volumes_from`. The poller delegates all
of that to `update-staging.sh`.

## Production (ki-chat)

Prod runs the **staging compose profile** (`PROJECT_NAME=hawki-staging`,
target `app_staging`) with a prod host `env/.env` and a pinned image tag:

```bash
sudo ./update-staging.sh --pull        # containers keep running
sudo ./update-staging.sh --update      # down → rm staging_build → up → migrate/seed/caches
```

See [docs/runbooks/prod-cutover-prebuilt-image.md](docs/runbooks/prod-cutover-prebuilt-image.md)
and `env/.env.ki-chat.example` for the host-side keys. There is no separate prod
profile: ki-chat switched to this layout on 2026-09-15 (KI-755, tag v2.3.2.5, hotfix
v2.3.2.6 the same evening), and the never-used `deploy-prod.sh`, `docker-compose.prod.yml`,
`.env.prod` and `nginx.template.prod` were removed on 2026-09-17.

## TLS certificates (certbot)

Hosts with certbot (HARICA ACME, `--standalone`; the domains are pre-validated on the
institutional account, so renewals need no HTTP-01 challenge and nginx keeps port 80)
hand the certificate to nginx through `scripts/certbot/deploy-hook.sh`: it copies
`fullchain.pem`/`privkey.pem` to `certs/cert.pem`/`key.pem` (bind-mounted into the
nginx container) and reloads nginx.

```bash
# once per host, as root
install -m 755 scripts/certbot/deploy-hook.sh /etc/letsencrypt/renewal-hooks/deploy/hawki-nginx
/etc/letsencrypt/renewal-hooks/deploy/hawki-nginx /etc/letsencrypt/live/<cert name>   # switch now
systemctl list-timers certbot.timer                                                   # renewals run from here
```

Previous key pairs are kept in `certs/old_certs/`. Should a renewal ever fail with
"Could not bind to port 80", HARICA's pre-validation has lapsed and the challenge has
to be served: add a `/.well-known/acme-challenge/` webroot to the nginx template.

## Optional service groups (compose profiles)

`docker-compose.staging.yml` (and `.prod.yml`) mark the transcription and realtime
services optional so a stack can come up on a host that lacks their prerequisites:

| `COMPOSE_PROFILES` value | starts additionally | needs |
|---|---|---|
| `transcription` | audio-ingest, queue-transcription, queue-transcription-process, transcription-listener | `S3_*` bucket + credentials |
| `realtime` | realtime-bridge, coturn | `TURN_*` (`update-staging.sh` refuses an empty or `changeme` `TURN_PASSWORD`) |

`env/.env.staging` enables both; narrow it in the host's `env/.env`
(`COMPOSE_PROFILES=realtime`, or `COMPOSE_PROFILES=` for the core stack only).

## Environment files

`env-init.sh` generates `env/.env` from `env/.env.example` merged with
`env/.env.<profile>`, creates missing keys and (dev) the `*.hawki.dev` certs and
`/etc/hosts` entries. The deploy scripts call it when `env/.env` is missing or
`--init` is given. Host-specific values live only in `env/.env`. See
[env/README.md](env/README.md).

The `VITE_*` values baked into the CI image
(`.github/docker-build.<profile>.env` in the app repo) must match
`REVERB_APP_KEY`/`REVERB_HOST` in the host's `env/.env`.

## File uploads

The `file-converter` service decides which file types a chat upload may be: HAWKI
asks it at `GET /` (same bearer key as `/extract`), caches the answer for an hour
and falls back to the static list in `config/file_converter.php` while it is
unreachable — uploads keep working either way, and a format the converter learns
needs no HAWKI release. Converter 3.0.2 reports 119 extensions; HAWKI accepts 107
of them.

Two filters sit on top of that list:

| filter | where | default |
|---|---|---|
| archives, never accepted | hard-coded `never_accept` in `config/file_converter.php` | `zip, tar, gz, tgz, 7z, pst` |
| admin deny list | `FILE_CONVERTER_EXCLUDED_EXTENSIONS` in `env/.env` | `mp3, mpga, m4a, wav, webm, mp4, mpeg` |

Archives are not negotiable: an archive is an opaque container the converter
unpacks without a per-member type check. Audio and video are off because the
converter advertises them but its API answers `400` — HAWKI transcribes media in
the transcription module instead. Empty the deny list once the converter can
transcribe.

`FILE_CONVERTER_FORMATS_CACHE_TTL` (default 3600) is how long the converter's
list is cached.

### Upload size

The limit shown in the input field and enforced by the upload routes is the
**smallest** of three values, read at request time:

| value | where | app image |
|---|---|---|
| `HAWKI_ATTACHMENT_MAX_MB` | `env/.env` (default 256) | 256 |
| `upload_max_filesize` | php.ini (`conf.d/zzz.app.common.ini`) | 256M |
| `post_max_size` | php.ini | 256M |

A file over the php.ini values never reaches Laravel — `$_FILES` arrives empty
and no validation message fires — so HAWKI reads php.ini itself and shows what
actually applies rather than a number it cannot honour. Raising
`HAWKI_ATTACHMENT_MAX_MB` above php.ini therefore changes nothing; raise the
php.ini values (and `client_max_body_size`, currently 500M) first.

### Slide rendering

The converter never renders a slide: a `.pptx` reaches the model as its text plus
the image files embedded in it, so a model could name the icon on a poster and
not say where it sits. The `page-render` service (`_docker/page-render/`,
LibreOffice + poppler behind a small FastAPI, image
`ghcr.io/stenseegel/hawki-page-render` built by CI) renders each slide to a
PNG. For the formats in `PAGE_RENDER_FORMATS` (slide formats by default) HAWKI
stores those renders **instead of** the extracted figures and heads each
chunk's text with `[Page N of deck.pptx]`, so text and picture line up.

| variable | default | meaning |
|---|---|---|
| `PAGE_RENDER_API_URL` | `http://page-render` | sidecar root; empty disables rendering |
| `PAGE_RENDER_API_KEY` | empty | `RENDER_API_KEY` on the sidecar; empty = no check |
| `PAGE_RENDER_FORMATS` | pptx, ppt, pptm, ppsx, pps, potx, potm, pot, odp, otp, fodp, key | add `pdf` to render PDFs too (the converter already extracts a PDF's figures, so that doubles image cost) |
| `PAGE_RENDER_MAX_PAGES` | 20 | slides rendered and sent per document |
| `PAGE_RENDER_DPI` | 110 | render resolution before the usual downscale |
| `PAGE_RENDER_REPLACE_FIGURES` | true | drop the converter's figures when pages were rendered |

`page-render` must be in `DOCKER_NO_PROXY` like every other compose service the
app reaches. A sidecar that is down or slow leaves the upload exactly as before
(figures only), with a warning in the log.

## Proxy

Outbound HTTP(S) on the JLU hosts goes through the campus proxy. The prebuilt image
ships without proxy settings, so the compose files inject
`DOCKER_HTTP_PROXY`/`DOCKER_HTTPS_PROXY`/`DOCKER_NO_PROXY` from `env/.env` at
runtime. `NO_PROXY` must list every compose service the app reaches over HTTP
(`file-converter`, `realtime-bridge`, …) — the proxy cannot resolve them.

## Nginx

`nginx/generate-nginx-config.sh` renders `nginx/nginx.template.<profile>` into
`nginx/nginx.default.conf` (gitignored) using `NGINX_*` from `env/.env`; every
deploy script runs it. See [nginx/README.md](nginx/README.md).

## Troubleshooting

- **502 after recreating the app container** — nginx still points at the old
  container: `docker restart hawki-<profile>-nginx`.
- **Old JS/CSS after an update (staging/prod)** — the `staging_build` volume was
  not dropped; `./update-staging.sh --update` does it.
- **Port 3306/80/443 already allocated** — another profile is running:
  `./stop.sh --auto`.
- **Permission errors in storage/** — `chown -R 33:33 storage` (staging/prod) or
  rerun `./deploy-dev.sh` (dev, uses `DOCKER_UID`/`DOCKER_GID`).
- Logs: `docker compose -f compose/docker-compose.<profile>.yml --env-file env/.env logs -f app`
