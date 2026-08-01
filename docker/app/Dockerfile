# syntax=docker/dockerfile:1

# FrankenPHP is the app server from day one so that enabling Octane worker mode in
# Phase 8 is a configuration change (FRANKENPHP_CONFIG) rather than a server swap.
FROM dunglas/frankenphp:1-php8.4

ARG UID=1000
ARG GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    SERVER_NAME=":8080"

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        redis \
        sockets \
        zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-laremit.ini
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile

# Run as the host user: the source tree is bind-mounted, so anything the container
# writes (logs, compiled views, generated migrations) must stay host-writable.
RUN if ! getent group "${GID}" >/dev/null; then groupadd -g "${GID}" app; fi \
    && if ! getent passwd "${UID}" >/dev/null; then useradd -u "${UID}" -g "${GID}" -m -s /bin/bash app; fi \
    && mkdir -p /app /data/caddy /config/caddy \
    && chown -R "${UID}:${GID}" /app /data/caddy /config/caddy

WORKDIR /app
USER ${UID}:${GID}
