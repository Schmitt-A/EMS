#!/bin/sh
set -eu
mkdir -p /data /run /var/log/nginx
# Recorder und php-fpm schreiben dieselbe SQLite-Datei. Ein gemeinsamer User, sonst scheitert WAL.
if [ -f /usr/local/etc/php-fpm.d/www.conf ]; then
  sed -i 's/^user = .*/user = root/' /usr/local/etc/php-fpm.d/www.conf
  sed -i 's/^group = .*/group = root/' /usr/local/etc/php-fpm.d/www.conf
fi
php-fpm -D
php /opt/ems/bin/recorder.php &
exec nginx -g 'daemon off;'
