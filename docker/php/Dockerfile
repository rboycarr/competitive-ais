# PHP-FPM image for the app, Horizon, and the scheduler (ADR-0003 pins).
FROM php:8.4.27-fpm-bookworm

ARG UID=1000
ARG GID=1000
ARG PHPREDIS_VERSION=6.3.0

# libfcgi-bin provides cgi-fcgi for the FPM healthcheck.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev libfcgi-bin unzip \
 && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_mysql zip \
 && pecl install "redis-${PHPREDIS_VERSION}" \
 && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2.11.1 /usr/bin/composer /usr/bin/composer

# Match the WSL developer's uid/gid so files written to the bind mount stay editable.
RUN groupmod -o -g "${GID}" www-data \
 && usermod -o -u "${UID}" -g "${GID}" www-data \
 && mkdir -p /var/www/html/vendor \
 && chown -R www-data:www-data /var/www

COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /var/www/html
USER www-data

HEALTHCHECK --interval=10s --timeout=5s --retries=6 \
  CMD SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
      cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
