#!/bin/sh

cd /var/www

echo "in entrypoint.sh"

if [ -f .env ]; then
  APP_ENV=$(grep -E '^APP_ENV=' .env | head -1 | cut -d '=' -f2 | tr -d '"' | tr -d "'" | tr -d '\r')
  echo "entrypoint: APP_ENV=${APP_ENV}"

  if [ "$APP_ENV" = "local" ] || [ "$APP_ENV" = "testing" ]; then
    # Never cache the config outside production.
    #
    # A cached config freezes app.env, and Laravel reads $app['env'] from
    # config('app.env') rather than from the environment variable. So a config
    # cached while APP_ENV=local makes app()->environment('testing') false even
    # under phpunit, which switches off the CSRF bypass in tests: every POST,
    # PATCH and DELETE comes back 419 while every GET passes. It also makes
    # env() return null in seeders, and it makes edits to .env do nothing.
    #
    # All three are silent. None of them looks like a caching problem.
    php artisan optimize:clear
  else
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
  fi
else
  echo ".env not found - run /bin/prepare_env.sh inside the container first"
fi

/usr/bin/supervisord -c /etc/supervisord.conf &
php-fpm
