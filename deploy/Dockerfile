# syntax=docker/dockerfile:1
#
# The NiceTryBot control panel for self-hosting: PHP-FPM + nginx on port 8080 (serversideup/php),
# reached only by the edge inside the compose network. One image runs all three app services:
#
#   app        the default command (nginx + PHP-FPM); runs migrations, then caches config, routes,
#              views and events at start, since every installation's .env differs
#   worker     command: php artisan queue:work ...
#   scheduler  command: php artisan schedule:work
#
# Build from the repository root (the add-ons in packages/ are Composer path repositories, so they
# must be in the context):
#
#   docker build -f deploy/Dockerfile -t ghcr.io/nicetrybot/app:dev .
#
# Vapor never reads this file; its builds are set in vapor.yml.

ARG PHP_IMAGE=serversideup/php:8.5-fpm-nginx
ARG NODE_IMAGE=node:22-bookworm-slim

############################################################################
# base: the PHP runtime plus the extensions the image lacks (it already has pdo_pgsql, redis,
# sodium, zip and pcntl)
############################################################################
FROM ${PHP_IMAGE} AS base

USER root

RUN install-php-extensions bcmath gd intl

# The app's own php.ini (PHP's session upload progress stays off: PHP2Shell).
COPY php/conf.d/php.ini /usr/local/etc/php/conf.d/zz-nicetry.ini

# HSTS is the edge's to send, per domain. The app also answers the gym on customers' own domains
# (/_q/*), where nginx's default "Strict-Transport-Security: ...; includeSubDomains" would pin
# every subdomain of theirs to HTTPS for a year.
RUN sed -i '/Strict-Transport-Security/d' /etc/nginx/server-opts.d/security.conf

USER www-data

############################################################################
# vendor: Composer dependencies, production only. Path repositories are mirrored (copied) into
# vendor/, not symlinked, so vendor/ stands on its own.
############################################################################
FROM base AS vendor

WORKDIR /var/www/html

ENV COMPOSER_MIRROR_PATH_REPOS=1

COPY --chown=www-data:www-data composer.json composer.lock ./
COPY --chown=www-data:www-data packages ./packages

RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

############################################################################
# assets: the Vite build. Tailwind reads Flux's CSS and some views from vendor/, and the add-ons'
# and obstacles' views from packages/ and gym/.
############################################################################
FROM ${NODE_IMAGE} AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor

RUN npm run build

############################################################################
# app: the image that runs
############################################################################
FROM base AS app

# The release this image is (bare, like 1.2.0); the release workflow passes it with --build-arg.
ARG NICETRY_RELEASE=

WORKDIR /var/www/html

ENV COMPOSER_MIRROR_PATH_REPOS=1 \
    # Laravel automations at start (serversideup): storage:link, migrate --force (waiting for the
    # database), then optimize. Worker and scheduler turn migrations off in compose.
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION_TIMEOUT=90 \
    # The container healthcheck asks Laravel's own health route.
    HEALTHCHECK_PATH=/up \
    PHP_OPCACHE_ENABLE=1 \
    # The edge is the only proxy in front of the app; Laravel's TrustProxies (TRUSTED_PROXIES)
    # decides what to believe, so nginx passes the connection's address through untouched.
    TRUSTED_PROXY=off \
    SHOW_WELCOME_MESSAGE=false \
    LOG_CHANNEL=stderr \
    # Add-ons come in through NICETRY_ADDONS and the installer here, which the Add-ons page explains.
    NICETRY_INSTALL=docker \
    # Which release this is, for the Admin section's update notice (empty in a local build).
    NICETRY_RELEASE=${NICETRY_RELEASE}

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

# The storage tree the context leaves out (storage/app becomes the app-storage volume, which starts
# as a copy of this), then the optimized autoloader and the add-on manifest (package:discover).
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && composer check-platform-reqs --no-dev \
    && rm -rf /composer/cache

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --start-period=120s --retries=5 \
    CMD curl --silent --show-error --fail http://localhost:8080/up > /dev/null || exit 1
