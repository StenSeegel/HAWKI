#!/bin/bash
# HAWKI Development Environment Entrypoint
set -e

echo "🚀 Starting HAWKI Development Environment..."

# Ensure storage directories exist
mkdir -p storage/framework/{cache,sessions,testing,views}
mkdir -p storage/logs
mkdir -p bootstrap/cache

# Set permissions
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Wait for database
echo "⏳ Waiting for MySQL..."
until php -r "new PDO('mysql:host=${DB_HOST:-mysql};port=${DB_PORT:-3306};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 2
done
echo "✅ Database is ready!"

# Run composer if needed
if [ ! -d "vendor" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --optimize-autoloader
fi

# Migrations and seeds
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    php artisan migrate --force 2>/dev/null || true
fi

if [ "${AUTO_SEED:-true}" = "true" ]; then
    php artisan db:seed --force 2>/dev/null || true
fi

php artisan storage:link 2>/dev/null || true
php artisan optimize:clear 2>/dev/null || true

echo "✅ Development environment ready!"
