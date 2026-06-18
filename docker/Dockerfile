FROM drupal:11-php8.4-apache

# Tools needed at build/runtime: git for the on-VPS code checkout, the mysql
# client for drush sql commands, unzip for composer package extraction.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git nano default-mysql-client unzip; \
    rm -rf /var/lib/apt/lists/*

# Redis PHP extension (the redis cache backend is wired in settings.php once the
# drupal/redis module is added; the extension is harmless until then).
RUN pecl install redis \
    && docker-php-ext-enable redis

# Recipe application and Drupal generally need more than the stock 128M.
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/zz-memory.ini

# Writable directories for the public files, JS assets, and the translations
# imported at install time.
RUN mkdir -p /opt/drupal/web/sites/default/files \
    && mkdir -p /opt/drupal/web/sites/default/files/assets/js \
    && mkdir -p /opt/drupal/web/sites/default/files/translations \
    && mkdir -p /opt/drupal/private \
    && chown -R www-data:www-data /opt/drupal/web/sites/default/files /opt/drupal/private \
    && chmod -R 775 /opt/drupal/web/sites/default/files /opt/drupal/private

# Project code. The committed theme dist/ is the production asset transport, so
# no node build runs here.
COPY composer.json /opt/drupal/
COPY composer.lock /opt/drupal/
COPY settings.php /opt/drupal/web/sites/default/settings.php
COPY web/modules/custom /opt/drupal/web/modules/custom/
COPY web/themes/custom /opt/drupal/web/themes/custom/
COPY web/recipes /opt/drupal/web/recipes/
COPY scripts /opt/drupal/scripts/

WORKDIR /opt/drupal
RUN composer install --no-dev --no-interaction --optimize-autoloader

RUN chown -R www-data:www-data /opt/drupal \
    && chmod -R 775 /opt/drupal/web/sites/default/files /opt/drupal/private

CMD ["apache2-foreground"]
