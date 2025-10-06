#!/bin/bash
set -e  # Exit on error

echo "🚀 Starting HAWKI Live Deployment (with live code)..."

# Generate nginx configuration from template
if [ -f "generate-nginx-config.sh" ]; then
    echo "🔧 Generating Nginx configuration..."
    ./generate-nginx-config.sh
fi

# Permissions - check if storage directory exists first
if [ -d "./storage" ]; then
    echo "📁 Setting storage permissions..."
    chmod -R 755 ./storage
    find ./storage -type f -exec chmod 644 {} \;
fi

# Build from parent directory (where Dockerfile is located)
cd ..

# Load proxy configuration from .env file
if [ -f "_docker_production/.env" ]; then
    export HTTP_PROXY=$(grep -E "^DOCKER_HTTP_PROXY=" _docker_production/.env | cut -d '=' -f2- | tr -d '"' | tr -d "'")
    export HTTPS_PROXY=$(grep -E "^DOCKER_HTTPS_PROXY=" _docker_production/.env | cut -d '=' -f2- | tr -d '"' | tr -d "'")
    export NO_PROXY=$(grep -E "^DOCKER_NO_PROXY=" _docker_production/.env | cut -d '=' -f2- | tr -d '"' | tr -d "'")
fi

# Only set proxy if values are not empty
if [ -n "$HTTP_PROXY" ]; then
    echo "🌐 Using proxy: $HTTP_PROXY"
    PROXY_ARGS="--build-arg HTTP_PROXY=$HTTP_PROXY --build-arg HTTPS_PROXY=$HTTPS_PROXY --build-arg NO_PROXY=$NO_PROXY"
else
    PROXY_ARGS=""
fi

# Build app image (only needed first time or after Dockerfile changes)
if [ "$1" == "--build" ] || [ ! "$(docker images -q ${PROJECT_HAWKI_IMAGE} 2> /dev/null)" ]; then
    echo "🔨 Building app image..."
    docker compose -f _docker_production/docker-compose.live.yml build \
      $PROXY_ARGS \
      --pull app
fi

echo "🚢 Starting containers with live code..."
docker compose -f _docker_production/docker-compose.live.yml up -d --force-recreate --remove-orphans

# Run Laravel setup
echo "⚙️  Running Laravel setup..."
docker compose -f _docker_production/docker-compose.live.yml exec app bash -c "php artisan migrate --force && \
    php artisan db:seed --force && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache && \
    php artisan storage:link && \
    php artisan optimize:clear"

# Generate git info
echo "📝 Generating Git info..."
docker compose -f _docker_production/docker-compose.live.yml exec app bash -c "git config --global --add safe.directory /var/www/html && /var/www/html/git_info.sh"

# Get configuration from .env file
cd _docker_production
APP_URL=$(grep -E "^APP_URL=" .env | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DOCKER_PROJECT_IP=$(grep -E "^DOCKER_PROJECT_IP=" .env | cut -d '=' -f2- | tr -d '"' | tr -d "'")

# Determine if we're running locally or on remote server
if [ -z "$DOCKER_PROJECT_IP" ] || [ "$DOCKER_PROJECT_IP" = "127.0.0.1" ] || [ "$DOCKER_PROJECT_IP" = "0.0.0.0" ]; then
    # Local development - Docker exposes ports
    LOCAL_URL="http://localhost"
    IS_LOCAL=true
else
    IS_LOCAL=false
fi

echo ""
echo "✅ Live deployment complete!"
echo ""
echo "🌐 Access your application:"
if [ "$IS_LOCAL" = true ]; then
    echo "   → $LOCAL_URL (Local Docker)"
    if [ -n "$APP_URL" ] && [ "$APP_URL" != "http://localhost" ]; then
        echo "   → $APP_URL (Remote/Production URL in .env)"
    fi
else
    # Remote server
    if [ -n "$APP_URL" ]; then
        echo "   → $APP_URL"
    else
        echo "   → http://localhost"
    fi
fi
echo ""
echo "💡 Code is now live-mounted from the repository."
echo "   To update code: git pull && cd _docker_production && ./update-live.sh"
echo ""
echo "🔧 To force rebuild: ./deploy-live.sh --build"
