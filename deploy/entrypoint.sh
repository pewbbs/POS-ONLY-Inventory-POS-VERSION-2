#!/bin/sh
set -eu

cd /var/www/html
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

php artisan config:cache --no-interaction
php artisan stockroom:preflight --configuration-only --no-interaction

exec "$@"
