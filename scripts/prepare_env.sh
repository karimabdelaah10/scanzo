#!/bin/sh

# shellcheck disable=SC2164
echo "in prepare_env.sh"

composer install --no-interaction --prefer-dist --optimize-autoloader

FILE=./.env

if [ -f "$FILE" ]; then
  echo "$FILE exists."
else
  echo "$FILE does not exist."
  cp .env.example .env
  echo "$FILE created."
fi

php artisan key:generate
php artisan storage:link
php artisan migrate

# On a development machine, seed as well: a database with the tables but no
# accounts gives "These details do not match our records" at the login screen,
# which looks like a broken build rather than an empty database. Both seeders
# check before they write, so running this again changes nothing.
APP_ENV=$(grep -E '^APP_ENV=' .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")

if [ "$APP_ENV" = "local" ]; then
  php artisan db:seed
fi

# npm ci
# npm run build

/bin/fix_permissions.sh
