#!/bin/bash
set -e

echo "🔄 Quick update from Git (Live Setup)..."

cd ..

# Pull latest code
echo "📥 Pulling latest code..."
git pull

# Clear Laravel caches
echo "⚡ Clearing Laravel caches..."
docker compose -f _docker_production/docker-compose.live.yml exec app php artisan optimize:clear

# Recache for performance
echo "📦 Rebuilding caches..."
docker compose -f _docker_production/docker-compose.live.yml exec app bash -c "php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache"

# Update git info
echo "📝 Updating Git info..."
docker compose -f _docker_production/docker-compose.live.yml exec app bash -c "git config --global --add safe.directory /var/www/html && /var/www/html/git_info.sh"

echo ""
echo "✅ Update complete! Changes are live."
echo ""
echo "💡 If you updated composer.json or package.json, run:"
echo "   docker compose -f _docker_production/docker-compose.live.yml exec app composer install --no-dev"
echo "   docker compose -f _docker_production/docker-compose.live.yml exec app npm run build"
