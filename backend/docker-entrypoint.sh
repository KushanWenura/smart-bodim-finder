#!/bin/sh
set -eu
php artisan config:clear
php artisan storage:link >/dev/null 2>&1 || true
if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ] && [ "${3:-}" = "serve" ]; then
  php artisan migrate --force
  php artisan app:seed-demo-if-empty
fi
exec "$@"
