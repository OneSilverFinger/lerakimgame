#!/bin/sh
set -e

cd /var/www/html

if [ ! -f storage/framework/.migrated ]; then
  php artisan migrate --force --seed || true
  touch storage/framework/.migrated
fi

exec php-fpm
