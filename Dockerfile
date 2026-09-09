# syntax=docker/dockerfile:1
FROM php:8.5-cli-alpine

# php-amqplib hard-requires ext-sockets. It ships with PHP but is not built in
# the official images, so it has to be compiled in. linux-headers is needed on
# top of $PHPIZE_DEPS because sockets.c includes <linux/sock_diag.h>, which
# Alpine does not ship by default. Both are build-time only, hence the virtual
# package and the immediate cleanup.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install -j"$(nproc)" sockets \
    && apk del .build-deps

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

CMD ["php", "bin/console", "app:listen-for-build-jobs"]
