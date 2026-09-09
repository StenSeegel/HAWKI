#!/bin/bash
set -e  # Exit on error

echo "🚀 Starting HAWKI Production Deployment (prebuilt image from registry)..."

# Check if .env file exists
if [ ! -f ".env" ]; then
    echo "❌ Error: .env file not found in _docker directory!"
    echo "   Please create .env file from .env.example"
    exit 1
fi

# Generate nginx configuration from template
if [ -f "generate-nginx-config.sh" ]; then
    echo "🔧 Generating Nginx configuration..."
    ./generate-nginx-config.sh
fi

# Create external volumes if they don't exist
echo "🔧 Ensuring external volumes exist..."
if [ -f "scripts/create-volumes.sh" ]; then
    ./scripts/create-volumes.sh prod
else
    echo "❌ Error: scripts/create-volumes.sh not found!"
    exit 1
fi
echo ""

# Fix storage permissions for production (Linux only, skip on macOS)
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

# Run compose from the repository root
cd ..

echo "⬇️  Pulling images from registry..."
docker compose -f _docker/compose/docker-compose.prod.yml pull

echo "🚢 Starting containers..."
docker compose -f _docker/compose/docker-compose.prod.yml up -d --force-recreate --remove-orphans

echo "⚙️  Running Laravel optimizations..."
docker compose -f _docker/compose/docker-compose.prod.yml exec app bash -c "php artisan migrate --force && \
    php artisan db:seed --force && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache && \
    php artisan optimize:clear"

echo "📝 Updating system text keys..."
docker compose -f _docker/compose/docker-compose.prod.yml exec app php artisan texts:seed

echo "📝 Disabling test users..."
docker compose -f _docker/compose/docker-compose.prod.yml exec app bash -c "echo '[]' > /var/www/html/storage/app/test_users.json"

# Get APP_URL from .env file
cd _docker
APP_URL=$(grep -E "^APP_URL=" .env | cut -d '=' -f2- | tr -d '"' | tr -d "'")

echo ""
echo "✅ Production deployment complete!"
echo ""
if [ -n "$APP_URL" ]; then
    echo "🌐 Access your application at:"
    echo "   → $APP_URL"
else
    echo "🌐 Access your application at:"
    echo "   → http://localhost"
fi
echo ""
