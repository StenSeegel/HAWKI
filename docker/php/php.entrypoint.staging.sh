#!/bin/bash
# Boot hook for the app_staging target (staging AND prod deployments).
# The .env is bind-mounted by compose, so unlike app_prod this must NOT run
# prepareEnvVariables.php: it would rewrite the host's env file in place.

mkdir -p /var/www/html/storage/framework/{cache,sessions,testing,views}
chmod -R 777 /var/www/html/storage/framework

php artisan config:cache
php artisan route:cache
php artisan view:cache
