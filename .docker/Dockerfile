FROM karimabdelaah/php8.3-fullstack:fpm

COPY core/. /var/www/

############################# Laravel App Config #############################
COPY scripts/entrypoint.sh /tmp/entrypoint.sh
COPY scripts/prepare_env.sh /bin/prepare_env.sh
COPY scripts/fix_permissions.sh /bin/fix_permissions.sh

RUN chown -R www-data:www-data /var/www
RUN chmod 777 -R /tmp && chmod o+t -R /tmp

RUN chmod +x /tmp/entrypoint.sh /bin/prepare_env.sh /bin/fix_permissions.sh

RUN composer install --no-ansi --no-interaction --no-plugins --optimize-autoloader

############################# Supervisor Config #############################
COPY .docker/supervisor/app.conf /etc/supervisord.conf
COPY .docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
#############################################################################

WORKDIR /var/www

############################# Start the app #############################
ENTRYPOINT ["/tmp/entrypoint.sh"]
