# HAWKI Docker Scripts

This directory contains helper scripts for Docker deployments.

## Available Scripts

### `create-volumes.sh`

Creates external Docker volumes for staging and production environments.

**Usage:**
```bash
./create-volumes.sh [staging|prod]
```

**Volumes Created:**
- **Staging:**
  - `hawki-staging_mysql_data` - MySQL database storage
  - `hawki-staging_redis_data` - Redis cache storage

- **Production:**
  - `hawki-prod_mysql_data` - MySQL database storage
  - `hawki-prod_redis_data` - Redis cache storage

**Note:** This script is automatically called by `update-staging.sh` during every deployment. Subsequent deployments will skip volume creation if they already exist.

## Manual Volume Management

To manually list volumes:
```bash
docker volume ls | grep hawki
```

To manually remove volumes (⚠️ destroys all data):
```bash
docker volume rm hawki-staging_mysql_data
docker volume rm hawki-staging_redis_data
```

### `import-backup.sh`

Loads a HAWKI database backup — a spatie/laravel-backup zip (`php artisan backup:run --only-db`)
or a bare `.sql`/`.sql.gz` — into MySQL.

```bash
# rehearse pending migrations against a prod dump in a throwaway MySQL (touches no stack)
./import-backup.sh ../storage/app/prod-backups/2026-09-07-04-00-01.zip --profile=staging --scratch --migrate

# replace a stack's database from a dump (asks for the database name; --yes skips)
./import-backup.sh ~/Downloads/hawki-backups/hawki-staging-backup-x.zip --profile=dev
```

`--profile` selects which `env/.env.<profile>` + `env/.env` to read (container name,
database, app image). Set `DOCKER_DIR=/path/to/_docker` to run a copy of the script
from outside the checkout. Encrypted archives need `BACKUP_ARCHIVE_PASSWORD` in the
environment and `7z` installed.
