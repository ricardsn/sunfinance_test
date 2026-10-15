FROM php:8.4-fpm-alpine AS app
RUN apk add --no-cache postgresql-dev postgresql-client icu-dev \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS linux-headers \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache bcmath \
 && apk del .build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /app
# deps are installed by the same PHP that runs them, so composer sees the real
# extensions. manifest first, so a source change does not re-resolve them.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- dev image: app + xdebug + dev dependencies ---
FROM app AS dev
RUN apk add --no-cache --virtual .xdebug-build $PHPIZE_DEPS linux-headers \
 && pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && apk del .xdebug-build
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini
RUN composer install --no-interaction --no-scripts --prefer-dist
