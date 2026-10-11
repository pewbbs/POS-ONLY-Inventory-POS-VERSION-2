#!/bin/sh
set -eu

posonly_app_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
posonly_php_bin=${POSONLY_PHP_BIN:-/opt/alt/php84/usr/bin/php}

cd "$posonly_app_dir"
exec /usr/bin/flock -n storage/framework/queue.lock "$posonly_php_bin" artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=60 --max-time=50 --no-interaction >> storage/logs/queue.log 2>&1
