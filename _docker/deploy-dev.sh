#!/bin/bash
set -e  # Exit on error

echo "🚀 Starting HAWKI Development Deployment..."
echo ""

# Stop any running staging/prod containers first (they use the same ports)
if docker ps --format '{{.Names}}' | grep -qE '^hawki-(staging|prod)-'; then
    echo "⚠️  Detected running staging/prod containers. Stopping them first..."
    echo ""
    
    # Stop staging containers if running
    if docker ps --format '{{.Names}}' | grep -q '^hawki-staging-'; then
        echo "🛑 Stopping staging containers..."
        cd ..
        docker compose -f _docker/compose/docker-compose.staging.yml down 2>/dev/null || true
        cd _docker
        echo "✅ Staging containers stopped"
        echo ""
    fi
    
    # Stop prod containers if running
    if docker ps --format '{{.Names}}' | grep -q '^hawki-prod-'; then
        echo "🛑 Stopping prod containers..."
        cd ..
        docker compose -f _docker/compose/docker-compose.prod.yml down 2>/dev/null || true
        cd _docker
        echo "✅ Prod containers stopped"
        echo ""
    fi
fi

# Clean up any orphaned containers from previous runs
echo "🧹 Cleaning up any orphaned containers..."
docker ps -a --filter "name=hawki-${DEPLOY_PROFILE:-dev}-" --format '{{.Names}}' | xargs -r docker rm -f 2>/dev/null || true
echo ""

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
        DEPLOY_PROFILE=dev ./env/env-init.sh ${FORCE_INIT:+--force}
    else
        echo "❌ Error: env/env-init.sh not found!"
        exit 1
    fi
    echo ""
fi

# Load environment variables
# Load order matters: .env.dev first (defaults), then .env (user overrides)
if [ -f "env/.env.dev" ]; then
    set -a
    source env/.env.dev
    set +a
fi

if [ -f "env/.env" ]; then
    set -a
    source env/.env
    set +a
fi

# Export profile for docker-compose
export PROJECT_NAME=${PROJECT_NAME:-hawki-dev}
export PROJECT_HAWKI_IMAGE=${PROJECT_HAWKI_IMAGE:-hawki:dev}
export DEPLOY_PROFILE=dev  # Set profile for nginx config generation

# Key generation is now handled by env/env-init.sh

# Generate nginx configuration
echo "🔧 Generating Nginx configuration..."
if [ -f "nginx/generate-nginx-config.sh" ]; then
    ./nginx/generate-nginx-config.sh
else
    echo "⚠️  Warning: nginx/generate-nginx-config.sh not found"
fi
echo ""

# Fix storage permissions for dev mode (Linux only, skip on macOS)
if [ -d "./storage" ]; then
    # Check if running on Linux (where permissions are critical for Docker)
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        echo "📁 Fixing storage permissions (Linux)..."
        STORAGE_UID=${DOCKER_UID:-501}
        STORAGE_GID=${DOCKER_GID:-1000}
        
        # Use sudo only if not root
        if [ "$EUID" -ne 0 ]; then
            sudo chown -R ${STORAGE_UID}:${STORAGE_GID} ./storage 2>/dev/null || true
        else
            chown -R ${STORAGE_UID}:${STORAGE_GID} ./storage 2>/dev/null || true
        fi
        
        chmod -R 755 ./storage 2>/dev/null || true
        find ./storage -type f -exec chmod 644 {} \; 2>/dev/null || true
        echo "✅ Storage permissions fixed (UID:${STORAGE_UID}, GID:${STORAGE_GID})"
        echo ""
    else
        echo "ℹ️  Skipping storage permissions (not on Linux, Docker Desktop handles this)"
        echo ""
    fi
fi

# Local domains are now set up by env-init.sh

# Build from parent directory (where Dockerfile is located)
cd ..

# Clean up any existing containers with the same names to avoid conflicts
echo "🧹 Cleaning up any existing dev containers..."
docker ps -a --filter "name=hawki-dev-" --format '{{.Names}}' | xargs -r docker rm -f 2>/dev/null || true
echo ""

if [ "$FORCE_BUILD" = true ]; then
    echo "🔨 Building Docker images..."
    
    # Load proxy configuration
    if [ -n "$DOCKER_HTTP_PROXY" ]; then
        echo "   Using proxy: $DOCKER_HTTP_PROXY"
        PROXY_ARGS="--build-arg HTTP_PROXY=$DOCKER_HTTP_PROXY --build-arg HTTPS_PROXY=$DOCKER_HTTPS_PROXY --build-arg NO_PROXY=$DOCKER_NO_PROXY"
    else
        PROXY_ARGS=""
    fi
    
    docker compose -f _docker/compose/docker-compose.dev.yml build \
      $PROXY_ARGS \
      --pull app
    echo ""
fi

echo "🚢 Starting containers..."
docker compose -f _docker/compose/docker-compose.dev.yml up -d --remove-orphans

# Wait for containers to be ready
echo "⏳ Waiting for containers to be ready..."
sleep 5
echo ""

# Update Composer dependencies (WITH dev dependencies for development)
echo "📦 Installing Composer dependencies..."
docker compose -f _docker/compose/docker-compose.dev.yml exec app composer install --optimize-autoloader
echo ""

# Build frontend on the host with the Reverb settings from env/.env so the
# bundle talks to the right WebSocket endpoint
echo "🎨 Building frontend assets..."
export VITE_APP_NAME="${APP_NAME:-HAWKI2}"
export VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
export VITE_REVERB_HOST="${REVERB_HOST}"
export VITE_REVERB_PORT="${REVERB_PORT}"
export VITE_REVERB_SCHEME="${REVERB_SCHEME}"
[ -d node_modules ] || npm install
npm run build
echo ""

# Note: NPM dev server info
echo "💡 Frontend Development:"
echo "   Frontend built with Docker environment (https://app.hawki.dev)"
echo ""
echo "   For live development with hot reload:"
echo "   → npm run dev (on HOST)"
echo ""

# Run Laravel setup (without route:cache due to Laravel 12 bug)
echo "⚙️  Running Laravel setup..."
docker compose -f _docker/compose/docker-compose.dev.yml exec app bash -c "php artisan migrate --force && \
    php artisan db:seed --force && \
    php artisan storage:link && \
    php artisan optimize:clear"
echo ""

# Update system texts with new keys
echo "📝 Updating system text keys..."
docker compose -f _docker/compose/docker-compose.dev.yml exec app php artisan texts:seed
echo ""

# Apply development configuration overwrites
cd _docker
if [ -f "scripts/apply-dev-overwrites.sh" ]; then
    ./scripts/apply-dev-overwrites.sh
fi
cd ..
echo ""

# Run post-deployment commands
cd _docker
if [ -f "scripts/run-post-deployment-commands.sh" ]; then
    ./scripts/run-post-deployment-commands.sh
fi
cd ..
echo ""

# Display success message
cd _docker
APP_URL=${APP_URL:-https://app.hawki.dev}

echo "═══════════════════════════════════════════════════════"
echo "✅ Development deployment complete!"
echo "═══════════════════════════════════════════════════════"
echo ""
echo "🌐 Access your application:"
echo "   → https://app.hawki.dev       (HAWKI Application)"
echo "   → https://admin.hawki.dev     (Admin Portal - separate session)"
echo "   → https://db.hawki.dev        (Adminer - Database)"
echo "   → https://mail.hawki.dev      (Mailpit - Email Testing)"
echo ""
if [ "$APP_URL" != "https://app.hawki.dev" ]; then
    echo "   Configured URL in .env:"
    echo "   → $APP_URL"
    echo ""
fi
echo "💡 Development Features:"
echo "   → Live code mounting (changes are instant)"
echo "   → Debug mode enabled"
echo "   → Detailed error pages"
echo "   → Database management via Adminer"
echo "   → Auto-applied dev config overwrites"
echo "   → Custom post-deployment commands"
echo ""
echo "🛠️  Quick Commands:"
echo "   Update code:         git pull && ./update-dev.sh"
echo "   Restart containers:  docker compose restart"
echo "   View logs:           docker compose logs -f app"
echo "   Force rebuild:       ./deploy-dev.sh --build"
echo "   Reinitialize env:    ./deploy-dev.sh --init"
echo ""
echo "⚙️  Customization:"
echo "   Config overwrites:   env/dev-overwrites"
echo "   Post-deploy cmds:    env/dev-cmds"
echo ""
echo "═══════════════════════════════════════════════════════"
