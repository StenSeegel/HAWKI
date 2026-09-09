# Runbook: ki-chat (prod) → prebuilt CI image, parity with staging

Card KI-699. Host `sv90022` / `ki-chat.uni-giessen.de`, checkout `/root/HAWKI2`.
Prod runs the **staging** compose profile (`hawki-staging-*` containers, target
`app_staging`) and keeps doing so — only the image source changes: from a
15 GB image built on the host in May to `ghcr.io/stenseegel/hawki:<version>`
built by CI. Same procedure ki-test went through on 2026-09-01.

Everything in *Prepare* happens off-prod and can be done any time. *Cutover*
starts only once the DB is backed up and HAWKI is in maintenance mode.

---

## 0. Prepare (off-prod)

### 0.1 Commit + push the prep changes

| Repo | Files |
|------|-------|
| StenSeegel/HAWKI (`dev-local`) | `.github/workflows/build-docker-image.yml`, `.github/docker-build.prod.env` |
| StenSeegel/hawki-docker (`main`) | `compose/docker-compose.{staging,prod}.yml` (profiles), `env/.env.{staging,prod}` (`COMPOSE_PROFILES`), `update-staging.sh` (TURN guard), `env/.env.ki-chat.example`, `scripts/import-backup.sh`, this runbook |

CI checks out hawki-docker `main` at build time, so **push hawki-docker first**.
ki-test's poller will roll the compose change out on its next `:staging` digest
change; `COMPOSE_PROFILES` defaults to both groups there, so nothing changes.

### 0.2 Tag the release → CI builds the prod image

Version scheme: `v<upstream base>.<fork revision>`; upstream base is `2.3.2`
(`config/hawki_version.json`), so the first prod tag is **`v2.3.2.1`**. Tag the
commit that ki-test is running (`staging` is a pointer into `dev-local`):

```bash
git fetch origin
git rev-parse --short origin/staging origin/dev-local   # pick the tested one
git tag -a v2.3.2.1 -m "ki-chat release 1 on upstream 2.3.2" <sha>
git push origin v2.3.2.1
```

The workflow builds target `app_staging` with `.github/docker-build.prod.env`
(`VITE_REVERB_HOST=ki-chat.uni-giessen.de`) and pushes `:2.3.2.1` and `:latest`.
Takes ~10 min; watch it under Actions → "Build app image". An `-rc` suffix
(`v2.3.2.2-rc1`) builds `:2.3.2.2-rc1` only, without moving `:latest`.

A build from any branch without tagging: Actions → Build app image →
Run workflow → profile `prod` → pushes `:prod-<sha>`.

### 0.3 Rehearse the migrations against a real prod dump (on ki-test)

Prod dumps land on ki-test nightly (`/root/HAWKI/_docker/storage/app/prod-backups/`).
The scratch import loads one into a throwaway MySQL and runs `migrate --force`
from the image that will go to prod — nothing on ki-test is touched.

```bash
ssh gz488@ki-test.hrz.uni-giessen.de
cd /root/HAWKI/_docker
sudo docker pull ghcr.io/stenseegel/hawki:2.3.2.1
sudo PROJECT_HAWKI_IMAGE=ghcr.io/stenseegel/hawki:2.3.2.1 \
  ./scripts/import-backup.sh storage/app/prod-backups/$(ls storage/app/prod-backups | tail -1) \
  --profile=staging --scratch --migrate
```

Expected: 18 new migrations run, exit 0. Loading 1.3 GB takes several minutes.

### 0.4 Prepare the host `.env` values

Work through `env/.env.ki-chat.example`: section A = keys to change, section B
= the 17 keys missing on prod. Decide `APP_ENV`/`APP_DEBUG`/`LOG_LEVEL` and
`COMPOSE_PROFILES` (no `transcription` until the prod S3 bucket exists).
Check `REDIS_PASSWORD` is not `password` and `REVERB_APP_KEY=hawki-reverb`.

---

## 1. Pre-flight on prod (read-only)

```bash
ssh <you>@sv90022
cd /root/HAWKI2
sudo git status --short; sudo git -C _docker status --short   # hand-made host fixes? keep them
sudo docker ps --format '{{.Names}} {{.Image}} {{.Status}}'
sudo docker image inspect hawki:prod-rollback-20260904 --format '{{.Id}}'  # rollback image present
sudo docker volume ls | grep hawki-staging                                  # mysql_data / redis_data / staging_build
df -h /                                                                      # image pull needs ~5 GB
sudo grep -E '^(PROJECT_NAME|PROJECT_HAWKI_IMAGE|REVERB_APP_KEY|REDIS_PASSWORD|APP_ENV|TURN_PASSWORD|COMPOSE_PROFILES)=' _docker/env/.env
```

Abort if `PROJECT_NAME` is anything other than `hawki-staging`, or the rollback
image is missing.

---

## 2. Cutover (only after: DB backed up, maintenance mode on)

### 2.1 Freeze a restore point

```bash
sudo docker exec hawki-staging-app php artisan backup:run --only-db
sudo docker cp hawki-staging-app:/var/www/html/storage/app/HAWKI2/$(sudo docker exec hawki-staging-app sh -c 'ls -t storage/app/HAWKI2/*.zip | head -1 | xargs basename') /root/pre-cutover-$(date +%F).zip
sudo cp _docker/env/.env /root/env.pre-cutover-$(date +%F)
sudo docker inspect hawki-staging-app --format '{{.Image}}'   # note the old image id
```

### 2.2 Update the checkouts

```bash
cd /root/HAWKI2/_docker
sudo git stash push -m "host fixes pre-cutover" || true     # only if status was dirty
sudo git fetch origin && sudo git checkout main && sudo git reset --hard origin/main
sudo git stash pop || true                                    # re-apply host fixes, resolve if needed

cd /root/HAWKI2
sudo git fetch origin --tags
sudo git checkout v2.3.2.1        # app checkout is only needed for the mounted config/
```

### 2.3 Edit `_docker/env/.env`

Apply `env/.env.ki-chat.example`: `PROJECT_HAWKI_IMAGE=ghcr.io/stenseegel/hawki:2.3.2.1`,
`COMPOSE_PROFILES=…`, `REVERB_APP_KEY=hawki-reverb`, the env decision, and
append section B with real values. `PROJECT_NAME` stays `hawki-staging`.

### 2.4 Pull, then switch

```bash
cd /root/HAWKI2/_docker
sudo ./update-staging.sh --pull          # containers keep running; ~5 GB download via the proxy
sudo ./update-staging.sh --update        # down → rm staging_build volume → up → migrate/seed/caches/texts:seed
sudo docker restart hawki-staging-nginx  # nginx pins the old app IP and 502s otherwise
```

`--update` runs `php artisan migrate --force` — the 18 migrations from 0.3.
The script aborts before touching containers if `realtime` is enabled with an
empty `TURN_PASSWORD`.

### 2.5 Verify

```bash
sudo docker ps --format '{{.Names}} {{.Image}} {{.Status}}'         # all Up, app/nginx/mysql/redis healthy
sudo docker exec hawki-staging-app php artisan migrate:status | grep -c Pending   # 0
sudo docker exec hawki-staging-app php artisan about | grep -iE 'environment|debug|version'
curl -sk -o /dev/null -w '%{http_code}\n' https://ki-chat.uni-giessen.de/          # 200/302, not 502
sudo docker logs --since 5m hawki-staging-app | tail -20
```

In the browser: login, open a chat, stream a reply (Reverb websocket connects
to `ki-chat.uni-giessen.de` — check the console for 4xx on `/app/hawki-reverb`),
admin page shows the new commit hash. Then leave maintenance mode.

---

## 3. Rollback

Roll back if migrations fail or the app does not come up. Everything needed is
still on the host.

```bash
cd /root/HAWKI2/_docker
sudo docker compose -f compose/docker-compose.staging.yml stop app queue queue-transcription queue-transcription-process transcription-listener scheduler reverb 2>/dev/null

# 1. database — only if migrations ran (schema changed)
sudo ./scripts/import-backup.sh /root/pre-cutover-$(date +%F).zip --profile=staging --yes

# 2. config + image
sudo cp /root/env.pre-cutover-$(date +%F) env/.env           # restores PROJECT_HAWKI_IMAGE=hawki:staging etc.
sudo docker tag hawki:prod-rollback-20260904 hawki:staging    # the old local image under its old name
sudo git checkout dbb021d                                     # _docker as before (compose without profiles)
cd /root/HAWKI2 && sudo git checkout jlu-translate-2.0        # 5596c59

# 3. up
cd /root/HAWKI2/_docker
sudo ./update-staging.sh --update
sudo docker restart hawki-staging-nginx
```

The old `update-staging.sh` at `dbb021d` still accepts `--update`. `--pull`
would try to pull `hawki:staging` from Docker Hub — don't pass it.

---

## Traps (from the inventory)

- `PROJECT_NAME=hawki-staging` owns the data volumes. Renaming → empty database.
- `TURN_PASSWORD` was a hard `:?` requirement in compose and prod lacked it; it is
  now a deploy-script check that only fires when `COMPOSE_PROFILES` has `realtime`.
- Never the `app_prod` target: nginx serves `public/` via `volumes_from`, which
  needs the `VOLUME` only `app_staging` declares. CI builds `app_staging` for
  every profile.
- `hawki-staging_staging_build` must go on every image change (the script does
  it) or the browser loads old Vite assets against new Blade templates.
- Restart nginx after any `up` that recreated the app container.
- Prod's `.env` overrides `.env.staging`: an old `PROJECT_HAWKI_IMAGE=hawki:staging`
  line there silently restarts the stale local image (bit ki-test on 09-01).
- No poller on prod. Updates stay manual: bump the tag, edit `PROJECT_HAWKI_IMAGE`,
  `--pull --update`, restart nginx.
