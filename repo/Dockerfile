# syntax=docker/dockerfile:1.4
#
# Build strategy:
#   - php:8.4-fpm (Debian Bookworm) — no Alpine, full libc compatibility
#   - mlocati/php-extension-installer — downloads pre-built .so binaries, avoids
#     slow compile-from-source for most extensions
#   - BuildKit cache mounts for apt, pecl, and Composer — subsequent builds
#     skip downloads entirely if lock files are unchanged
#   - Layer ordering: system deps → PHP extensions → Composer deps → app source
#     so a code-only change rebuilds only the final layer

# ─── Stage: base — shared PHP runtime ────────────────────────────────────────
FROM php:8.4-fpm AS base

LABEL org.opencontainers.image.title="VetOps Portal" \
      org.opencontainers.image.vendor="VetOps Engineering"

# Pre-built PHP extension installer (avoids compiling from source)
COPY --from=mlocati/php-extension-installer:latest \
     /usr/bin/install-php-extensions /usr/local/bin/

# System packages — apt cache mount makes repeated builds near-instant
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        curl \
        unzip \
        default-mysql-client \
    # Remove the default nginx virtual host
    && rm -f /etc/nginx/sites-enabled/default \
    && rm -f /etc/nginx/conf.d/default.conf

# PHP extensions — IPE cache mount stores downloaded artefacts between builds
RUN --mount=type=cache,target=/tmp/ipe-cache \
    install-php-extensions \
        pdo_mysql \
        mbstring \
        zip \
        bcmath \
        opcache \
        pcntl \
        exif \
        gd

# Composer 2 binary copied from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP-FPM: align uid/gid with common host user (1000) to avoid bind-mount permission issues
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

WORKDIR /var/www/html

# ─── Stage: development ───────────────────────────────────────────────────────
FROM base AS development

ENV APP_ENV=local \
    COMPOSER_ALLOW_SUPERUSER=1

# Copy manifests first — Composer layer only invalidates when lock changes
COPY composer.json composer.lock ./

# Composer cache mount: ~/.composer/cache persists across builds on the same host
RUN --mount=type=cache,target=/root/.composer/cache,sharing=locked \
    composer install \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# Copy full application source
COPY . .

# Generate optimised class map
RUN composer dump-autoload --optimize

# Configuration: Nginx vhost, Supervisor programs, startup script
COPY .docker/nginx/default.conf  /etc/nginx/conf.d/vetops.conf
COPY .docker/supervisord.conf    /etc/supervisor/supervisord.conf
COPY .docker/entrypoint.sh       /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Create required Laravel directories and fix permissions
RUN mkdir -p \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/cache \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ─── Stage: production ────────────────────────────────────────────────────────
FROM base AS production

ENV APP_ENV=production \
    COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/root/.composer/cache,sharing=locked \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-dev

COPY .docker/nginx/default.conf  /etc/nginx/conf.d/vetops.conf
COPY .docker/supervisord.conf    /etc/supervisor/supervisord.conf
COPY .docker/entrypoint.sh       /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/cache \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER www-data
EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
