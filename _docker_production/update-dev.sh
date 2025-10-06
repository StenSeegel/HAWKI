#!/bin/bash
set -e

echo "🔄 Quick update from Git (Development Setup)..."

cd ..

# Pull latest code
echo "📥 Pulling latest code..."
git pull

# Check if package files changed
COMPOSER_CHANGED=0
PACKAGE_CHANGED=0

if git diff HEAD@{1} HEAD --name-only | grep -q "composer.json\|composer.lock"; then
    COMPOSER_CHANGED=1
    echo "📦 composer.json or composer.lock changed - will update dependencies"
fi

if git diff HEAD@{1} HEAD --name-only | grep -q "package.json\|package-lock.json"; then
    PACKAGE_CHANGED=1
    echo "📦 package.json or package-lock.json changed - will update dependencies"
fi

# Update Composer if needed
if [ $COMPOSER_CHANGED -eq 1 ]; then
    echo "📦 Updating Composer dependencies..."
    docker compose -f _docker_production/docker-compose.dev.yml exec app composer install --no-dev --optimize-autoloader
fi

# Update NPM if needed
if [ $PACKAGE_CHANGED -eq 1 ]; then
    echo "📦 Updating NPM dependencies..."
    docker compose -f _docker_production/docker-compose.dev.yml exec app npm install
    
    echo "🔨 Rebuilding frontend assets..."
    docker compose -f _docker_production/docker-compose.dev.yml exec app npm run build
fi

# Clear Laravel caches
echo "⚡ Clearing Laravel caches..."
docker compose -f _docker_production/docker-compose.dev.yml exec app php artisan optimize:clear

# Recache for performance
echo "📦 Rebuilding caches..."
docker compose -f _docker_production/docker-compose.dev.yml exec app bash -c "php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache"

# Update git info
echo "📝 Updating Git info..."
docker compose -f _docker_production/docker-compose.dev.yml exec app bash -c "git config --global --add safe.directory /var/www/html && /var/www/html/git_info.sh"

echo ""
echo "✅ Update complete! Changes are live."
echo ""

if [ $COMPOSER_CHANGED -eq 0 ] && [ $PACKAGE_CHANGED -eq 0 ]; then
    echo "💡 To manually update dependencies, run:"
    echo "   docker compose -f _docker_production/docker-compose.dev.yml exec app composer install --no-dev"
    echo "   docker compose -f _docker_production/docker-compose.dev.yml exec app npm install && npm run build"
fi
