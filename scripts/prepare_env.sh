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

# npm ci
# npm run build

/bin/fix_permissions.sh
