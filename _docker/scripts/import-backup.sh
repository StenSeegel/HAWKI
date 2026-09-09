#!/bin/bash

# =====================================================
# HAWKI - Import a database backup
# =====================================================
# Loads a HAWKI database dump — a spatie/laravel-backup zip as produced by
# `php artisan backup:run --only-db`, or a bare .sql / .sql.gz — into MySQL.
#
# Two targets:
#   default    the mysql container of a running stack (DESTRUCTIVE: the
#              database is dropped and recreated from the dump)
#   --scratch  a throwaway mysql:8.0 container, e.g. to rehearse the pending
#              migrations against a production dump without touching any stack
#
# Usage:
#   ./import-backup.sh <backup.zip|dump.sql|dump.sql.gz> [options]
#
# Options:
#   --profile=dev|staging|prod  Stack whose env/.env.<profile> + env/.env to
#                               read (container name, database, image). Default: dev
#   --scratch                   Load into a throwaway container instead of the stack
#   --migrate                   Afterwards run `php artisan migrate --force`
#                               (stack: inside the running app container;
#                               scratch: from the stack's app image)
#   --keep                      Keep the scratch container running afterwards
#   --yes                       Skip the confirmation prompt (stack target)
#
# Examples:
#   # rehearse the 18 pending migrations against last night's prod dump
#   ./import-backup.sh ../storage/app/prod-backups/2026-09-07-04-00-01.zip \
#       --profile=staging --scratch --migrate
#
#   # restore prod's database during a rollback (stack must be up, app stopped)
#   ./import-backup.sh /root/backups/pre-cutover.zip --profile=staging --yes
#
# Encrypted archives (BACKUP_ARCHIVE_PASSWORD set at backup time) use AES,
# which Info-ZIP's unzip cannot open — 7z is used for those when available.
# =====================================================

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="${DOCKER_DIR:-$(dirname "$SCRIPT_DIR")}"   # override to run a copy from outside the checkout

PROFILE=dev
SCRATCH=false
MIGRATE=false
KEEP=false
YES=false
BACKUP=""

for arg in "$@"; do
    case $arg in
        --profile=*) PROFILE="${arg#*=}" ;;
        --scratch)   SCRATCH=true ;;
        --migrate)   MIGRATE=true ;;
        --keep)      KEEP=true ;;
        --yes|-y)    YES=true ;;
        -h|--help)   sed -n '3,40p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*)          echo -e "${RED}Unknown option: $arg${NC}"; exit 1 ;;
        *)           BACKUP="$arg" ;;
    esac
done

if [ -z "$BACKUP" ]; then
    echo -e "${RED}❌ Usage: $0 <backup.zip|dump.sql|dump.sql.gz> [--profile=dev|staging|prod] [--scratch] [--migrate] [--keep] [--yes]${NC}"
    exit 1
fi
if [ ! -f "$BACKUP" ]; then
    echo -e "${RED}❌ File not found: $BACKUP${NC}"
    exit 1
fi
if [[ ! "$PROFILE" =~ ^(dev|staging|prod)$ ]]; then
    echo -e "${RED}❌ Invalid profile: $PROFILE (dev|staging|prod)${NC}"
    exit 1
fi

# Load configuration the same way the deploy scripts do: profile defaults
# first, host overrides second.
set -a
# shellcheck disable=SC1090
[ -f "$DOCKER_DIR/env/.env.$PROFILE" ] && source "$DOCKER_DIR/env/.env.$PROFILE"
# shellcheck disable=SC1091
[ -f "$DOCKER_DIR/env/.env" ] && source "$DOCKER_DIR/env/.env"
set +a

PROJECT_NAME="${PROJECT_NAME:-hawki-$PROFILE}"
DB_DATABASE="${DB_DATABASE:-hawki}"
IMAGE="${PROJECT_HAWKI_IMAGE:-hawki:$PROFILE}"

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/hawki-import.XXXXXX")"
SCRATCH_NAME="hawki-scratch-mysql"
SCRATCH_NET="hawki-scratch"
SCRATCH_PW="scratch"
cleanup() {
    rm -rf "$WORK_DIR"
    if [ "$SCRATCH" = true ] && [ "$KEEP" = false ]; then
        docker rm -f "$SCRATCH_NAME" >/dev/null 2>&1 || true
        docker network rm "$SCRATCH_NET" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}HAWKI Backup Import${NC}"
echo -e "${GREEN}========================================${NC}"
echo "   Backup:   $BACKUP"
echo "   Profile:  $PROFILE"
echo "   Database: $DB_DATABASE"
if [ "$SCRATCH" = true ]; then
    echo "   Target:   scratch container $SCRATCH_NAME (mysql:8.0)"
else
    echo "   Target:   ${PROJECT_NAME}-mysql  (DATABASE WILL BE REPLACED)"
fi
echo ""

# -----------------------------------------------------
# 1. Extract the SQL dump
# -----------------------------------------------------
DUMP="$WORK_DIR/dump.sql"
case "$BACKUP" in
    *.zip)
        echo -e "${YELLOW}📦 Extracting SQL dump from archive...${NC}"
        if [ -n "${BACKUP_ARCHIVE_PASSWORD:-}" ]; then
            command -v 7z >/dev/null || { echo -e "${RED}❌ Encrypted archive: 7z is required (apt install p7zip-full)${NC}"; exit 1; }
            entry=$(7z l -slt -p"$BACKUP_ARCHIVE_PASSWORD" "$BACKUP" | awk -F' = ' '$1=="Path" && $2 ~ /^db-dumps\/.*\.sql$/ {print $2; exit}')
            [ -n "$entry" ] || { echo -e "${RED}❌ No db-dumps/*.sql entry in the archive${NC}"; exit 1; }
            7z e -so -p"$BACKUP_ARCHIVE_PASSWORD" "$BACKUP" "$entry" > "$DUMP"
        else
            entry=$(unzip -Z1 "$BACKUP" | grep -E '^db-dumps/.*\.sql$' | head -1 || true)
            [ -n "$entry" ] || { echo -e "${RED}❌ No db-dumps/*.sql entry in the archive (encrypted? set BACKUP_ARCHIVE_PASSWORD)${NC}"; exit 1; }
            unzip -p "$BACKUP" "$entry" > "$DUMP"
        fi
        echo "   $entry"
        ;;
    *.sql.gz)
        echo -e "${YELLOW}📦 Decompressing dump...${NC}"
        gunzip -c "$BACKUP" > "$DUMP"
        ;;
    *.sql)
        DUMP="$BACKUP"
        ;;
    *)
        echo -e "${RED}❌ Unsupported file type (expected .zip, .sql or .sql.gz)${NC}"
        exit 1
        ;;
esac
echo -e "${GREEN}✅ Dump ready: $(du -h "$DUMP" | cut -f1)${NC}"
echo ""

# -----------------------------------------------------
# 2. Prepare the target
# -----------------------------------------------------
if [ "$SCRATCH" = true ]; then
    echo -e "${YELLOW}🐬 Starting scratch MySQL...${NC}"
    docker rm -f "$SCRATCH_NAME" >/dev/null 2>&1 || true
    docker network inspect "$SCRATCH_NET" >/dev/null 2>&1 || docker network create "$SCRATCH_NET" >/dev/null
    docker run -d --name "$SCRATCH_NAME" --network "$SCRATCH_NET" \
        -e MYSQL_ROOT_PASSWORD="$SCRATCH_PW" \
        -e MYSQL_DATABASE="$DB_DATABASE" \
        mysql:8.0 --default-authentication-plugin=mysql_native_password --max_allowed_packet=256M >/dev/null
    MYSQL_CONTAINER="$SCRATCH_NAME"
    for i in $(seq 1 60); do
        # A real query over TCP: mysqladmin ping reports "alive" even when access is
        # denied, and the image's temporary init server only listens on the socket.
        if docker exec "$MYSQL_CONTAINER" mysql -h127.0.0.1 -uroot -p"$SCRATCH_PW" -e "SELECT 1" >/dev/null 2>&1; then
            break
        fi
        sleep 2
        [ "$i" = 60 ] && { echo -e "${RED}❌ Scratch MySQL did not become ready${NC}"; exit 1; }
    done
    echo -e "${GREEN}✅ Scratch MySQL ready${NC}"
else
    MYSQL_CONTAINER="${PROJECT_NAME}-mysql"
    if ! docker ps --format '{{.Names}}' | grep -qx "$MYSQL_CONTAINER"; then
        echo -e "${RED}❌ Container $MYSQL_CONTAINER is not running${NC}"
        exit 1
    fi
    echo -e "${RED}⚠️  This DROPS database '$DB_DATABASE' in $MYSQL_CONTAINER and replaces it with the dump.${NC}"
    echo -e "${YELLOW}   Stop the app/queue containers first if the stack is live, or they will write into the half-loaded schema.${NC}"
    if [ "$YES" = false ]; then
        read -r -p "   Type the database name to continue: " confirm
        [ "$confirm" = "$DB_DATABASE" ] || { echo "Aborted."; exit 1; }
    fi
fi
echo ""

# root credentials: the official mysql image exposes them in the container env
mysql_root() {
    docker exec -i "$MYSQL_CONTAINER" sh -c 'exec mysql -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" "$@"' -- "$@"
}

# -----------------------------------------------------
# 3. Load the dump
# -----------------------------------------------------
echo -e "${YELLOW}🗄️  Recreating database '$DB_DATABASE'...${NC}"
mysql_root -e "DROP DATABASE IF EXISTS \`$DB_DATABASE\`; CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo -e "${YELLOW}⬆️  Loading dump (this takes a while for large databases)...${NC}"
start=$(date +%s)
if command -v pv >/dev/null 2>&1; then
    pv "$DUMP" | docker exec -i "$MYSQL_CONTAINER" sh -c 'exec mysql -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" "$0"' "$DB_DATABASE"
else
    docker exec -i "$MYSQL_CONTAINER" sh -c 'exec mysql -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" "$0"' "$DB_DATABASE" < "$DUMP"
fi
echo -e "${GREEN}✅ Loaded in $(( $(date +%s) - start )) s${NC}"

tables=$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_DATABASE'")
migrations=$(mysql_root -N -e "SELECT COUNT(*) FROM \`$DB_DATABASE\`.migrations" 2>/dev/null || echo "?")
echo "   Tables: $tables   Applied migrations: $migrations"

# Stack target: the app user was created by the image with rights on
# MYSQL_DATABASE only at first boot; re-granting is harmless and covers a
# database that was renamed by the dump.
if [ "$SCRATCH" = false ] && [ -n "${DB_USERNAME:-}" ]; then
    mysql_root -e "GRANT ALL ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'%'; FLUSH PRIVILEGES;" 2>/dev/null || true
fi
echo ""

# -----------------------------------------------------
# 4. Optional: run the migrations against the imported data
# -----------------------------------------------------
if [ "$MIGRATE" = true ]; then
    echo -e "${YELLOW}🚚 Running migrations...${NC}"
    if [ "$SCRATCH" = true ]; then
        if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
            echo -e "${RED}❌ App image $IMAGE not present locally — pull it first (./update-staging.sh --pull)${NC}"
            exit 1
        fi
        echo "   image: $IMAGE"
        # The real env/.env supplies APP_KEY & friends; the DB_* and driver
        # overrides win because Laravel never overwrites variables that already
        # exist in the process environment. Everything stateful is pointed at
        # in-process drivers so the run touches nothing but the scratch DB.
        docker run --rm --network "$SCRATCH_NET" \
            -v "$DOCKER_DIR/env/.env:/var/www/html/.env:ro" \
            -e DB_CONNECTION=mysql -e DB_HOST="$SCRATCH_NAME" -e DB_PORT=3306 \
            -e DB_DATABASE="$DB_DATABASE" -e DB_USERNAME=root -e DB_PASSWORD="$SCRATCH_PW" \
            -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
            -e BROADCAST_CONNECTION=log -e LOG_CHANNEL=stderr \
            -e HTTP_PROXY= -e HTTPS_PROXY= \
            "$IMAGE" php artisan migrate --force
        echo ""
        echo -e "${YELLOW}   migrate:status (last entries):${NC}"
        docker run --rm --network "$SCRATCH_NET" \
            -v "$DOCKER_DIR/env/.env:/var/www/html/.env:ro" \
            -e DB_CONNECTION=mysql -e DB_HOST="$SCRATCH_NAME" -e DB_PORT=3306 \
            -e DB_DATABASE="$DB_DATABASE" -e DB_USERNAME=root -e DB_PASSWORD="$SCRATCH_PW" \
            -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
            -e BROADCAST_CONNECTION=log -e LOG_CHANNEL=stderr \
            "$IMAGE" php artisan migrate:status 2>/dev/null | grep -Ev '^\s*$' | tail -8
    else
        docker exec "${PROJECT_NAME}-app" php artisan migrate --force
    fi
    echo -e "${GREEN}✅ Migrations done${NC}"
    echo ""
fi

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}✅ Import complete${NC}"
echo -e "${GREEN}========================================${NC}"
if [ "$SCRATCH" = true ] && [ "$KEEP" = true ]; then
    echo "   Scratch DB kept: docker exec -it $SCRATCH_NAME mysql -uroot -p$SCRATCH_PW $DB_DATABASE"
    echo "   Remove with:     docker rm -f $SCRATCH_NAME && docker network rm $SCRATCH_NET"
elif [ "$SCRATCH" = false ]; then
    echo "   Remember: sessions/caches in Redis may reference the old data — 'php artisan cache:clear' in the app container."
    echo "   Users can only log in if APP_KEY and the *_SALT values match the source deployment."
fi
