#!/bin/bash
# =====================================================
# HAWKI - certbot deploy hook
# =====================================================
# Copies a renewed certificate into _docker/certs (bind-mounted into the nginx
# container as /etc/nginx/certs) and reloads nginx. certbot runs it after every
# successful renewal with RENEWED_LINEAGE set; run it by hand with the lineage
# directory as argument to switch to the certbot certificate the first time.
#
# Install (as root):
#   install -m 755 scripts/certbot/deploy-hook.sh /etc/letsencrypt/renewal-hooks/deploy/hawki-nginx
#
# Test:
#   ./scripts/certbot/deploy-hook.sh /etc/letsencrypt/live/<cert name>
# =====================================================
set -euo pipefail

LINEAGE="${1:-${RENEWED_LINEAGE:-}}"
if [ -z "$LINEAGE" ] || [ ! -f "$LINEAGE/fullchain.pem" ] || [ ! -f "$LINEAGE/privkey.pem" ]; then
    echo "deploy-hook: no lineage with fullchain.pem/privkey.pem (RENEWED_LINEAGE or argument)" >&2
    exit 1
fi

# Resolve _docker from the script location, which also works from the copy
# certbot runs under /etc/letsencrypt/renewal-hooks/deploy when DOCKER_DIR is set.
DOCKER_DIR="${DOCKER_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
if [ ! -d "$DOCKER_DIR/certs" ]; then
    DOCKER_DIR=/root/HAWKI/_docker
fi
CERTS="$DOCKER_DIR/certs"

# The container name follows PROJECT_NAME from env/.env (staging profile default).
PROJECT_NAME=$(grep -E '^PROJECT_NAME=' "$DOCKER_DIR/env/.env" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' || true)
NGINX_CONTAINER="${PROJECT_NAME:-hawki-staging}-nginx"

# Keep the previous pair for a rollback.
mkdir -p "$CERTS/old_certs"
[ -f "$CERTS/cert.pem" ] && cp -p "$CERTS/cert.pem" "$CERTS/old_certs/cert.pem.$(date +%Y%m%d%H%M%S)"
[ -f "$CERTS/key.pem" ]  && cp -p "$CERTS/key.pem"  "$CERTS/old_certs/key.pem.$(date +%Y%m%d%H%M%S)"

# Write in place (same directory, new inodes are fine: the whole directory is bind-mounted).
install -m 644 -o root -g root "$LINEAGE/fullchain.pem" "$CERTS/cert.pem"
install -m 600 -o root -g root "$LINEAGE/privkey.pem"   "$CERTS/key.pem"

if docker ps --format '{{.Names}}' | grep -qx "$NGINX_CONTAINER"; then
    docker exec "$NGINX_CONTAINER" nginx -t >/dev/null
    docker exec "$NGINX_CONTAINER" nginx -s reload
    echo "deploy-hook: installed $(openssl x509 -in "$CERTS/cert.pem" -noout -enddate | cut -d= -f2) certificate from $LINEAGE, reloaded $NGINX_CONTAINER"
else
    echo "deploy-hook: installed certificate from $LINEAGE; $NGINX_CONTAINER is not running, nothing reloaded"
fi
