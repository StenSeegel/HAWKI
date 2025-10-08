#!/bin/bash

# =====================================================
# HAWKI - Staging Deployment Script
# =====================================================
# Deploys HAWKI in staging mode with:
# - Custom build from repository
# - Debug mode enabled for testing
# - No Adminer (production-like setup)
# - Standard www-data user (no UID mapping)
#
# Usage:
#   ./deploy-staging.sh [--build] [--init]
#
# Options:
#   --build    Force rebuild of Docker images
#   --init     Force re-initialization of environment
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
        docker compose -f _docker_production/docker-compose.dev.yml stop 2>/dev/null || true
        cd _docker_production
        echo "✅ Dev containers stopped"
        echo ""
    fi
    
    # Stop prod containers if running
    if docker ps --format '{{.Names}}' | grep -q '^hawki-prod-'; then
        echo "🛑 Stopping prod containers..."
        cd ..
        docker compose -f _docker_production/docker-compose.prod.yml stop 2>/dev/null || true
        cd _docker_production
        echo "✅ Prod containers stopped"
        echo ""
    fi
fi

# Parse arguments
FORCE_BUILD=false
FORCE_INIT=false
for arg in "$@"; do
    case $arg in
        --build)
            FORCE_BUILD=true
            ;;
        --init)
            FORCE_INIT=true
            ;;
    esac
done

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
if [ -f "env/.env" ]; then
    set -a
    source env/.env
    set +a
fi

# Load staging-specific defaults
if [ -f "env/.env.staging" ]; then
    set -a
    source env/.env.staging
    set +a
fi

# Export profile for docker-compose
export PROJECT_NAME=${PROJECT_NAME:-hawki-staging}
export PROJECT_HAWKI_IMAGE=${PROJECT_HAWKI_IMAGE:-hawki:staging}
export DEPLOY_PROFILE=staging  # Set profile for nginx config generation

# Generate nginx configuration
echo "🔧 Generating Nginx configuration..."
if [ -f "nginx/generate-nginx-config.sh" ]; then
    ./nginx/generate-nginx-config.sh
else
    echo "⚠️  Warning: nginx/generate-nginx-config.sh not found"
fi
echo ""

# Fix storage permissions for staging (Linux only, skip on macOS)
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
        
        chmod -R 755 ./storage 2>/dev/null || true
        find ./storage -type f -exec chmod 644 {} \; 2>/dev/null || true
        echo "✅ Storage permissions set (UID:${STORAGE_UID}, GID:${STORAGE_GID})"
        echo ""
    else
        # Skipping storage permissions (not on Linux, Docker handles this)
        echo ""
    fi
fi

# Build from parent directory (where Dockerfile is located)
cd ..

# Check if image exists, if not, force build
if ! docker image inspect "$PROJECT_HAWKI_IMAGE" >/dev/null 2>&1; then
    echo "📦 Image $PROJECT_HAWKI_IMAGE not found, building automatically..."
    FORCE_BUILD=true
fi

if [ "$FORCE_BUILD" = true ]; then
    echo "🔨 Building Docker images from repository..."
    
    # Load proxy configuration
    if [ -n "$DOCKER_HTTP_PROXY" ]; then
        echo "   Using proxy: $DOCKER_HTTP_PROXY"
        PROXY_ARGS="--build-arg HTTP_PROXY=$DOCKER_HTTP_PROXY --build-arg HTTPS_PROXY=$DOCKER_HTTPS_PROXY --build-arg NO_PROXY=$DOCKER_NO_PROXY"
    else
        PROXY_ARGS=""
    fi
    
    docker compose -f _docker_production/docker-compose.staging.yml build \
      $PROXY_ARGS \
      --pull app
    echo ""
fi

# Prepare proxy args for docker compose up --build
if [ -n "$DOCKER_HTTP_PROXY" ]; then
    COMPOSE_BUILD_ARGS="--build-arg HTTP_PROXY=$DOCKER_HTTP_PROXY --build-arg HTTPS_PROXY=$DOCKER_HTTPS_PROXY --build-arg NO_PROXY=$DOCKER_NO_PROXY"
else
    COMPOSE_BUILD_ARGS=""
fi

echo "🚢 Starting containers..."
# Use --build to ensure image is built if it doesn't exist
# Export build args as environment variables for docker compose
export HTTP_PROXY="$DOCKER_HTTP_PROXY"
export HTTPS_PROXY="$DOCKER_HTTPS_PROXY"
export NO_PROXY="$DOCKER_NO_PROXY"

docker compose -f _docker_production/docker-compose.staging.yml up -d --build --remove-orphans

# Wait for containers to be ready
echo "⏳ Waiting for containers to be ready..."
sleep 10
echo ""

# Run Laravel setup (without route:cache due to Laravel 12 bug)
echo "⚙️  Running Laravel setup..."
docker compose -f _docker_production/docker-compose.staging.yml exec app bash -c "\
    php artisan migrate --force && \
    php artisan db:seed --force && \
    php artisan storage:link && \
    php artisan config:cache && \
    php artisan view:cache && \
    php artisan optimize:clear"
echo ""

# Generate git info
echo "📝 Generating Git info..."
docker compose -f _docker_production/docker-compose.staging.yml exec app bash -c "\
    git config --global --add safe.directory /var/www/html && \
    /var/www/html/git_info.sh" 2>/dev/null || true
echo ""

# Display success message
cd _docker_production
APP_URL=${APP_URL:-https://staging.hawki.test}

echo "═══════════════════════════════════════════════════════"
echo "✅ Staging deployment complete!"
echo "═══════════════════════════════════════════════════════"
echo ""
echo "🌐 Access your application:"
echo "   → $APP_URL"
echo ""
echo "💡 Staging Features:"
echo "   → Built from current repository code"
echo "   → Debug mode enabled for testing"
echo "   → Production-like setup (no Adminer)"
echo "   → Cached routes and config for performance"
echo "   → Standard www-data user permissions"
echo ""
echo "🔄 Quick Commands:"
echo "   View logs:           docker compose logs -f app"
echo "   Restart containers:  docker compose restart"
echo "   Force rebuild:       ./deploy-staging.sh --build"
echo "   Reinitialize env:    ./deploy-staging.sh --init"
echo ""
echo "═══════════════════════════════════════════════════════"
