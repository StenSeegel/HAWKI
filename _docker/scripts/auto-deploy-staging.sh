#!/bin/bash

# =====================================================
# HAWKI - Staging auto-deploy poller
# =====================================================
# Pull-based deployment, same idea as Watchtower: poll the registry for the
# `staging` app image and roll it out when its digest changes, so CI never
# needs inbound access to this host.
#
# Watchtower itself is deliberately NOT used for this stack. It only pulls and
# recreates containers, while a HAWKI rollout additionally has to:
#   - run migrations/seeders and rebuild the caches inside the new image
#   - drop the staging_build volume, otherwise the new Vite assets never
#     appear: Docker copies image content into a named volume only while that
#     volume is still empty, so the browser keeps loading the old JS/CSS
#     against new Blade templates
#   - restart nginx, which serves the app's public/ through volumes_from and
#     otherwise keeps reading the replaced container's filesystem (502)
# So this script only detects the new image and delegates the rollout to
# update-staging.sh, which already does all of the above.
#
# Usage:
#   ./auto-deploy-staging.sh [--once] [--force]
#
# Options:
#   --once     Check and exit (default; the systemd timer calls it this way)
#   --force    Deploy even when the digest is unchanged
#
# Installed via scripts/systemd/ - see that directory's README section in
# ../README.md. Logs to stdout (journal) and /var/log/hawki-autodeploy.log.
# =====================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(dirname "$SCRIPT_DIR")"
LOCK_FILE="/var/run/hawki-autodeploy.lock"

FORCE=false
for arg in "$@"; do
    case $arg in
        --force) FORCE=true ;;
        --once)  ;;  # default behaviour, accepted for readability
    esac
done

log() {
    echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] $*"
}

# Serialize against a still-running rollout: a deploy takes minutes and the
# timer keeps firing meanwhile.
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "Another auto-deploy run is still active, skipping this tick."
    exit 0
fi

# Load configuration the same way the deploy scripts do: profile defaults
# first, host overrides second.
set -a
# shellcheck disable=SC1091
[ -f "$DOCKER_DIR/env/.env.staging" ] && source "$DOCKER_DIR/env/.env.staging"
# shellcheck disable=SC1091
[ -f "$DOCKER_DIR/env/.env" ] && source "$DOCKER_DIR/env/.env"
set +a

IMAGE="${PROJECT_HAWKI_IMAGE:-ghcr.io/stenseegel/hawki:staging}"
APP_CONTAINER="${PROJECT_NAME:-hawki-staging}-app"
NGINX_CONTAINER="${PROJECT_NAME:-hawki-staging}-nginx"

# Outbound HTTPS needs the campus proxy, and a systemd unit inherits no login
# shell environment - fall back to the values the Docker daemon already uses.
export HTTPS_PROXY="${HTTPS_PROXY:-${DOCKER_HTTPS_PROXY:-}}"
export HTTP_PROXY="${HTTP_PROXY:-${DOCKER_HTTP_PROXY:-}}"
export NO_PROXY="${NO_PROXY:-${DOCKER_NO_PROXY:-localhost,127.0.0.1,reverb,file-converter,realtime-bridge,audio-ingest,redis,mysql,nginx,host.docker.internal,.uni-giessen.de}}"

# Split "ghcr.io/owner/name:tag" into registry, repository and tag.
image_ref="${IMAGE%%:*}"
image_tag="${IMAGE##*:}"
[ "$image_tag" = "$IMAGE" ] && image_tag="latest"
registry="${image_ref%%/*}"
repository="${image_ref#*/}"

if [ "$registry" != "ghcr.io" ]; then
    log "ERROR: only ghcr.io is supported for digest polling (image: $IMAGE)."
    exit 1
fi

# --- remote digest -------------------------------------------------------
# The package is public, so an anonymous pull token is enough. REGISTRY_USER /
# REGISTRY_TOKEN (read:packages) are honoured should it ever go private.
token_url="https://ghcr.io/token?scope=repository:${repository}:pull&service=ghcr.io"
if [ -n "${REGISTRY_USER:-}" ] && [ -n "${REGISTRY_TOKEN:-}" ]; then
    token_json=$(curl -fsS -u "${REGISTRY_USER}:${REGISTRY_TOKEN}" "$token_url")
else
    token_json=$(curl -fsS "$token_url")
fi
token=$(printf '%s' "$token_json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["token"])')

# A HEAD request returns the manifest digest in a header - no need to download
# the manifest itself. Accept both OCI and Docker media types, otherwise the
# registry answers with a different (and wrong) digest for multi-arch images.
remote_digest=$(curl -fsSI \
    -H "Authorization: Bearer $token" \
    -H "Accept: application/vnd.oci.image.index.v1+json" \
    -H "Accept: application/vnd.oci.image.manifest.v1+json" \
    -H "Accept: application/vnd.docker.distribution.manifest.list.v2+json" \
    -H "Accept: application/vnd.docker.distribution.manifest.v2+json" \
    "https://ghcr.io/v2/${repository}/manifests/${image_tag}" \
    | awk 'tolower($1) == "docker-content-digest:" { print $2 }' | tr -d '\r')

if [ -z "$remote_digest" ]; then
    log "ERROR: could not resolve the remote digest for $IMAGE."
    exit 1
fi

# --- local digest --------------------------------------------------------
# Compare against what the RUNNING container uses, not against the local tag:
# that way a rollout which failed halfway is retried on the next tick.
local_digest=""
if running_image=$(docker inspect --format '{{.Image}}' "$APP_CONTAINER" 2>/dev/null); then
    local_digest=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$running_image" 2>/dev/null \
        | grep "^${image_ref}@" | head -1 | cut -d'@' -f2 || true)
fi

if [ -z "$local_digest" ]; then
    log "No digest for the running $APP_CONTAINER (container missing or built locally) - deploying."
elif [ "$local_digest" = "$remote_digest" ]; then
    if [ "$FORCE" = false ]; then
        log "Up to date ($IMAGE at ${remote_digest:0:19})."
        exit 0
    fi
    log "Digest unchanged but --force given - deploying."
else
    log "New image detected: ${local_digest:0:19} -> ${remote_digest:0:19}"
fi

# --- rollout -------------------------------------------------------------
log "Starting rollout of $IMAGE ..."
cd "$DOCKER_DIR"
if DEPLOY_PROFILE=staging ./update-staging.sh --pull --update; then
    # nginx serves the app's public/ via volumes_from and keeps pointing at the
    # replaced container until it is restarted.
    docker restart "$NGINX_CONTAINER" >/dev/null
    log "Rollout complete, nginx restarted."
else
    log "ERROR: rollout failed - see the output above. Containers were left as they are."
    exit 1
fi
