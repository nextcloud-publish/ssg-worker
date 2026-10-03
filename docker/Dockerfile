# syntax=docker/dockerfile:1
FROM php:8.5-cli-alpine

# PIE ships binary-only: tags are `bin` / `x.y.z-bin`, there is no `latest`.
COPY --from=ghcr.io/php/pie:bin /pie /usr/bin/pie

# pcntl: Symfony installs its SIGTERM handler only when pcntl_signal exists,
# otherwise `docker stop` kills the worker mid-build and burns a build attempt.
# GNU tar: ArchiveExtractor shells out to tar, and busybox tar mishandles the
# pax/long-path headers Collectives exports can carry.
# unzip: PIE unpacks extensions from zip, and this base image has no unzip or git.
RUN apk add --no-cache rabbitmq-c tar \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS rabbitmq-c-dev unzip \
    && pie install --no-interaction --no-cache php-amqp/php-amqp \
    && docker-php-ext-install -j"$(nproc)" pcntl \
    && apk del .build-deps \
    && php -m | grep -qx amqp \
    && php -m | grep -qx pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# --no-scripts: Flex's auto-scripts shell out to bin/console, not copied yet.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts

# The only difference from ./Dockerfile.dev, which gets its source from a mount.
COPY . .

# --memory-limit only applies if PHP's own memory_limit is higher; Messenger
# checks between messages, PHP's limit is fatal mid-build.
CMD ["php", "bin/console", "messenger:consume", "builds", \
     "--time-limit=3600", "--memory-limit=512M", "-vv"]
