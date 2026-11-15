#!/bin/sh

echo "in fix_permissions.sh"
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
find /var/www/storage -type d -exec chmod 775 {} \;
find /var/www/storage -type f -exec chmod 664 {} \;
