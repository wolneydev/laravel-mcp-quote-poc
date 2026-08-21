#!/bin/sh
set -e

mkdir -p /var/www/html/storage/framework/{cache,sessions,views} /var/www/html/storage/logs /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R ug+rwx /var/www/html/storage /var/www/html/bootstrap/cache

exec docker-php-entrypoint "$@"
