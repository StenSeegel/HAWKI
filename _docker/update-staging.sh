#!/bin/bash

# =====================================================
# HAWKI - Staging Update Script
# =====================================================
# Updates HAWKI in staging mode with minimal downtime.
# The image is prebuilt by CI (push to the `staging` branch of the app repo)
# and pulled from the registry — nothing is built on this host.
#
# Usage:
#   ./update-staging.sh [--pull] [--update] [--init]
#
# Options:
#   --pull     Pull the latest images from the registry (containers keep running)
#   --update   Restart containers with the new image and run setup tasks
#   --init     Force re-initialization of environment
#   --build    Deprecated alias for --pull (images are built by CI now)
# =====================================================

set -e  # Exit on error

echo "🚀 Starting HAWKI Staging Deployment..."
echo ""

# Stop any running dev/prod containers first (they use the same ports)
if docker ps --format '{{.Names}}' | grep -qE '^hawki-(dev|prod)-'; then
    echo "⚠️  Detected running dev/prod containers. Stopping them first..."
    echo ""
    
    # Stop dev containers if running
    if docker ps --format '{{.Names}}' | grep -q '^hawki-dev-'; then
        echo "🛑 Stopping dev containers..."
        cd ..
        docker compose -f _docker/compose/docker-compose.dev.yml stop 2>/dev/null || true
        cd _docker
        echo "✅ Dev containers stopped"
        echo ""
    fi
    
    # Stop prod containers if running
    if docker ps --format '{{.Names}}' | grep -q '^hawki-prod-'; then
        echo "🛑 Stopping prod containers..."
        cd ..
        docker compose -f _docker/compose/docker-compose.prod.yml stop 2>/dev/null || true
        cd _docker
        echo "✅ Prod containers stopped"
        echo ""
    fi
fi

# Parse arguments
DO_PULL=false
DO_UPDATE=false
FORCE_INIT=false
for arg in "$@"; do
    case $arg in
        --pull)
            DO_PULL=true
            ;;
        --build)
            echo "ℹ️  --build is deprecated: images are built by CI. Pulling instead."
            DO_PULL=true
            ;;
        --update)
            DO_UPDATE=true
            ;;
        --init)
            FORCE_INIT=true
            ;;
    esac
done

if [ "$DO_PULL" = false ] && [ "$DO_UPDATE" = false ] && [ "$FORCE_INIT" = false ]; then
    echo "❌ Error: Please specify at least one action: --pull, --update, or --init"
    echo "Usage: ./update-staging.sh [--pull] [--update] [--init]"
    exit 1
fi

# Initialize environment if .env doesn't exist or --init flag is set
if [ ! -f "env/.env" ] || [ "$FORCE_INIT" = true ]; then
    echo "🔧 Initializing environment..."
    if [ -f "env/env-init.sh" ]; then
        DEPLOY_PROFILE=staging ./env/env-init.sh ${FORCE_INIT:+--force}
    else
        echo "❌ Error: env/env-init.sh not found!"
        exit 1
    fi
    echo ""
fi

# Load environment variables
# Load order matters: .env.staging first (defaults), then .env (user overrides)
if [ -f "env/.env.staging" ]; then
    set -a
    source env/.env.staging
    set +a
fi

if [ -f "env/.env" ]; then
    set -a
    source env/.env
    set +a
fi

# Export profile for docker-compose
export PROJECT_NAME=${PROJECT_NAME:-hawki-staging}
export PROJECT_HAWKI_IMAGE=${PROJECT_HAWKI_IMAGE:-hawki:staging}
export DEPLOY_PROFILE=staging  # Set profile for nginx config generation

# Compose interpolation cannot be made profile-aware, so the hard requirement
# lives here: coturn with an empty or placeholder credential would come up
# looking healthy while every TURN allocation is refused.
case ",${COMPOSE_PROFILES:-}," in
    *,realtime,*)
        if [ -z "${TURN_PASSWORD:-}" ] || [ "${TURN_PASSWORD:-}" = "changeme" ]; then
            echo "❌ Error: COMPOSE_PROFILES includes 'realtime' but TURN_PASSWORD is empty or the placeholder."
            echo "   Set a real TURN_PASSWORD in env/.env, or drop 'realtime' from COMPOSE_PROFILES."
            exit 1
        fi
        ;;
esac

# Generate nginx configuration
echo "🔧 Generating Nginx configuration..."
if [ -f "nginx/generate-nginx-config.sh" ]; then
    ./nginx/generate-nginx-config.sh
else
    echo "⚠️  Warning: nginx/generate-nginx-config.sh not found"
fi
echo ""

# Create external volumes if they don't exist
echo "🔧 Ensuring external volumes exist..."
if [ -f "scripts/create-volumes.sh" ]; then
    ./scripts/create-volumes.sh staging
else
    echo "❌ Error: scripts/create-volumes.sh not found!"
    exit 1
fi
echo ""

# Fix storage permissions for staging (Linux only, skip on macOS)
# Only run on pull or init, not on update to avoid massive slowdowns
if [ "$DO_PULL" = true ] || [ "$FORCE_INIT" = true ]; then
    if [ -d "./storage" ]; then
        # Check if running on Linux (where permissions are critical for Docker)
        if [[ "$OSTYPE" == "linux-gnu"* ]]; then
            echo "📁 Setting storage ownership and permissions (Linux)..."
            STORAGE_UID=${DOCKER_UID:-33}
            STORAGE_GID=${DOCKER_GID:-33}
            
            # Use sudo only if not root
            if [ "$EUID" -ne 0 ]; then
                sudo chown -R ${STORAGE_UID}:${STORAGE_GID} ./storage 2>/dev/null || true
            else
                chown -R ${STORAGE_UID}:${STORAGE_GID} ./storage 2>/dev/null || true
            fi
            
            chmod -R 775 ./storage 2>/dev/null || true
            find ./storage -type f -exec chmod 664 {} \; 2>/dev/null || true
            echo "✅ Storage permissions set (UID:${STORAGE_UID}, GID:${STORAGE_GID})"
            echo ""
        else
            # Skipping storage permissions (not on Linux, Docker handles this)
            echo ""
        fi
    fi
fi

# Run compose from the repository root
cd ..

# Check if image exists locally, if not, force a pull during update
if ! docker image inspect "$PROJECT_HAWKI_IMAGE" >/dev/null 2>&1; then
    echo "📦 Image $PROJECT_HAWKI_IMAGE not found locally, pull required..."
    DO_PULL=true
fi

if [ "$DO_PULL" = true ]; then
    echo "⬇️  Pulling images from registry (containers are still running)..."
    docker compose -f _docker/compose/docker-compose.staging.yml pull
    echo "✅ Pull complete."
    echo ""
fi

if [ "$DO_UPDATE" = true ]; then
    echo "🛑 Updating containers (this will cause a short downtime)..."
    
    # Remove containers completely to release volume locks
    echo "🗑️  Stopping and removing old containers..."
    docker compose -f _docker/compose/docker-compose.staging.yml down
    
    # ONLY remove staging_build volume (NOT staging_public with user uploads!)
    echo "🗑️  Removing old build assets volume..."
    VOLUME_NAME="${PROJECT_NAME}_staging_build"
    if docker volume inspect "$VOLUME_NAME" >/dev/null 2>&1; then
        docker volume rm "$VOLUME_NAME" 2>/dev/null || {
            echo "⚠️  Could not remove volume $VOLUME_NAME (might still be in use)"
        }
    fi

    echo "🚢 Starting new containers..."
    docker compose -f _docker/compose/docker-compose.staging.yml up -d --remove-orphans

    # Wait for containers to be ready
    echo "⏳ Waiting for containers to be ready..."
    sleep 5
    echo ""

    # Run Laravel setup
    echo "⚙️  Running Laravel setup..."
    docker compose -f _docker/compose/docker-compose.staging.yml exec app bash -c "\
        php artisan migrate --force && \
        php artisan db:seed --force && \
        php artisan storage:link && \
        php artisan config:cache && \
        php artisan view:cache && \
        php artisan optimize:clear"
    echo ""

    # Update system texts with new keys
    echo "📝 Updating system text keys..."
    docker compose -f _docker/compose/docker-compose.staging.yml exec app php artisan texts:seed
    echo ""

    # Fix storage permissions inside container
    echo "🔒 Setting storage permissions inside container..."
    docker compose -f _docker/compose/docker-compose.staging.yml exec app bash -c "\
        chmod -R 775 storage && \
        chmod -R 775 storage/logs && \
        chown -R www-data:www-data storage"
    echo ""

fi

# Display success message
cd _docker
APP_URL=${APP_URL:-https://staging.hawki.test}

echo "═══════════════════════════════════════════════════════"
if [ "$DO_UPDATE" = true ]; then
    echo "✅ Staging update complete!"
else
    echo "✅ Staging pull complete!"
fi
echo "═══════════════════════════════════════════════════════"
echo ""
if [ "$DO_UPDATE" = true ]; then
    echo "🌐 Access your application:"
    echo "   → $APP_URL"
    echo ""
fi
echo "💡 Actions performed:"
[ "$DO_PULL" = true ] && echo "   → Images pulled from registry"
[ "$DO_UPDATE" = true ] && echo "   → Containers restarted and Laravel optimized"
[ "$FORCE_INIT" = true ] && echo "   → Environment re-initialized"
echo ""
echo "🔄 Quick Commands:"
echo "   View logs:           docker compose logs -f app"
echo "   Pull only:           ./update-staging.sh --pull"
echo "   Update only:         ./update-staging.sh --update"
echo "   Pull & Update:       ./update-staging.sh --pull --update"
echo ""
echo "═══════════════════════════════════════════════════════"
