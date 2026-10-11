#!/bin/sh
set -eu

posonly_app_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
posonly_php_bin=${POSONLY_PHP_BIN:-/opt/alt/php84/usr/bin/php}

cd "$posonly_app_dir"
exec /usr/bin/flock -n storage/framework/scheduler.lock "$posonly_php_bin" artisan schedule:run --no-interaction >> storage/logs/scheduler.log 2>&1
