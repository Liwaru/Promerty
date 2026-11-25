# syntax=docker/dockerfile:1
ARG PHP_IMAGE=php:8.4-fpm-bookworm
ARG NODE_IMAGE=node:22-bookworm-slim
ARG COMPOSER_IMAGE=composer:2
ARG NGINX_IMAGE=nginx/nginx-unprivileged:stable-alpine

FROM ${COMPOSER_IMAGE} AS composer-bin
FROM ${PHP_IMAGE} AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libfreetype6 libjpeg62-turbo libpng16-16 libwebp7 \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd opcache pcntl pdo_mysql \
    && apt-get purge -y --auto-remove libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app

FROM php-base AS dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock artisan ./
COPY app ./app
COPY bootstrap/app.php bootstrap/providers.php ./bootstrap/
COPY config ./config
COPY routes ./routes
RUN mkdir -p bootstrap/cache \
    && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts --no-plugins \
    && composer check-platform-reqs --no-dev \
    && APP_ENV=production APP_DEBUG=false php artisan package:discover

FROM ${NODE_IMAGE} AS frontend
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY design-tokens ./design-tokens
COPY resources ./resources
COPY public/index.php public/robots.txt public/favicon.ico public/favicon-*.png ./public/
COPY public/images/Promerty.png public/images/promerty-*.svg ./public/images/
COPY scripts/generate-design-tokens.mjs ./scripts/
# Tailwind must scan the Blade pagination templates also used by operations pages.
COPY --from=dependencies /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./pagination-templates
RUN npm run build && test -s public/build/manifest.json

FROM php-base AS app
COPY --from=dependencies /app /app
COPY database/migrations ./database/migrations
COPY resources/views ./resources/views
# These two files are read directly at render time by Blade.
COPY resources/js/theme-init.js ./resources/js/theme-init.js
COPY design-tokens/promerty.json ./design-tokens/promerty.json
COPY --from=frontend /src/public ./public
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-promerty.ini
COPY deploy/docker/fpm.conf /usr/local/etc/php-fpm.d/zzz-promerty.conf
COPY deploy/docker/runtime-check.php /opt/promerty/runtime-check.php
RUN groupadd --gid 10001 promerty && useradd --uid 10001 --gid 10001 --no-create-home promerty \
    && install -d -o 10001 -g 10001 -m 0755 storage storage/app storage/app/public storage/framework \
        storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && install -d -o 10001 -g 10001 -m 0700 storage/app/private \
    && touch storage/.volume-ready storage/app/public/.volume-ready \
    && chown 10001:10001 storage/.volume-ready storage/app/public/.volume-ready \
    && test ! -e .env && test ! -e public/storage && test ! -e public/hot
USER 10001:10001
ENTRYPOINT ["/bin/sh", "-ec", "php /opt/promerty/runtime-check.php; exec docker-php-entrypoint \"$@\"", "promerty"]
CMD ["php-fpm"]

FROM ${NGINX_IMAGE} AS web
COPY --from=frontend /src/public/ /srv/promerty/public/
COPY deploy/docker/nginx.conf.template /etc/nginx/templates/default.conf.template
