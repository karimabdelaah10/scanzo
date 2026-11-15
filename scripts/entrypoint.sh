#!/bin/sh

cd /var/www

echo "in entrypoint.sh"

if [ -f .env ]; then
  php artisan config:cache
  php artisan cache:clear
else
  echo ".env not found - run /bin/prepare_env.sh inside the container first"
fi

/usr/bin/supervisord -c /etc/supervisord.conf &
php-fpm
