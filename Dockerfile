# syntax=docker/dockerfile:1
FROM php:8.5-cli-alpine

# Symfony Messenger's AMQP transport is built on ext-amqp, which links against
# rabbitmq-c -- not php-amqplib/ext-sockets, which this replaced.
#
# PIE, not `pecl install`: PECL is deprecated in favour of PIE (the PHP
# Installer for Extensions). PIE replaces the download/build/enable
# orchestration only -- the extension still compiles against the rabbitmq-c
# headers, which is why rabbitmq-c-dev is here. The headers are build-time
# only; the rabbitmq-c runtime library has to stay.
#
# PIE is published as a binary-only image tagged `bin` (latest) or `x.y.z-bin`;
# there is no `latest` tag to pull.
COPY --from=ghcr.io/php/pie:bin /pie /usr/bin/pie

# unzip is a PIE requirement rather than an amqp one: PIE downloads the
# extension as a zip, and this base image ships neither unzip nor git to
# unpack it.
#
# The final `php -m` check fails the build here if the extension did not
# actually load, rather than letting composer install below report it as a
# missing platform requirement.
RUN apk add --no-cache rabbitmq-c \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS rabbitmq-c-dev unzip \
    && pie install --no-interaction --no-cache php-amqp/php-amqp \
    && apk del .build-deps \
    && php -m | grep -qx amqp

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# CMD runs bin/console directly (there's no web server keeping the process
# alive through a request-level error), so vendor/ has to exist before the
# container starts, not just be installable on demand.
#
# --no-scripts: composer.json's post-install-cmd runs Flex's auto-scripts
# (cache:clear, assets:install), which shell out to bin/console -- but only
# composer.json/composer.lock are copied at this point, not the rest of the
# source, so bin/console doesn't exist yet and those scripts fail the build.
# Skipping them is harmless here: this is dev tooling behind a bind mount that
# overrides /app at runtime anyway (only vendor/ is protected from it), and
# the container already generates its own cache on first real boot.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts

CMD ["php", "bin/console", "messenger:consume", "builds", "-vv"]
