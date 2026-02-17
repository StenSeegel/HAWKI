#!/bin/bash
# HAWKI Production Environment Entrypoint
set -e

echo "🚀 Starting HAWKI Production Environment..."

mkdir -p /var/www/html/storage/framework/{cache,sessions,testing,views}
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

if [ -f /var/www/prepareEnvVariables.php ]; then
    php /var/www/prepareEnvVariables.php
fi

echo "⏳ Waiting for database..."
until php -r "new PDO('mysql:host=${DB_HOST:-mysql};port=${DB_PORT:-3306};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 2
done
echo "✅ Database is ready!"

php artisan migrate --force
php artisan db:seed --force
php artisan storage:link 2>/dev/null || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ Production environment ready!"
