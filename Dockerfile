# Production-style image used for the Railway demo. Local development uses docker-compose.yml.
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && printf "upload_max_filesize=12M\npost_max_size=14M\nexpose_php=Off\ndisplay_errors=Off\nlog_errors=On\n" > /usr/local/etc/php/conf.d/app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/evidence storage/logs \
    && chmod +x docker/start.sh

# Several workers so one slow request does not block others (PHP built-in server, Linux only).
ENV PHP_CLI_SERVER_WORKERS=4 \
    APP_ENV=prod \
    STORAGE_PATH=/app/storage/evidence \
    LOG_PATH=/app/storage/logs

CMD ["docker/start.sh"]
