# syntax=docker/dockerfile:1
FROM php:8.5-cli-alpine

# Symfony Messenger's AMQP transport is built on ext-amqp, which links against
# rabbitmq-c.
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

# Two extensions, two mechanisms, and they have to share one RUN.
#
# amqp is a third-party PECL extension, so PIE has to fetch it. pcntl IS
# bundled with PHP (merely disabled by default), so the base image's own
# docker-php-ext-install builds it straight from /usr/src/php.tar.xz with no
# download at all. Both compile, so both need $PHPIZE_DEPS -- which is why they
# cannot be split into separate layers: `apk del .build-deps` below removes the
# compiler, and anything building after it fails with no obvious cause.
#
# WHY pcntl AT ALL: messenger:consume needs it to shut down cleanly. Symfony
# installs its SIGTERM handler only when SignalRegistry::isSupported() is true,
# which is just function_exists('pcntl_signal'). Without it `docker stop`
# hard-kills the worker mid-build -- now mid-promotion -- the message stays
# unacked until consumer_timeout, and comes back redelivered, where
# RejectRedeliveredMessageMiddleware rejects it before the handler ever runs.
# That silently spends one of the two build attempts on every redeploy.
#
# unzip is a PIE requirement rather than an amqp one: PIE downloads the
# extension as a zip, and this base image ships neither unzip nor git to
# unpack it. tar is explicit because ArchiveExtractor shells out to it and
# busybox's version is looser about the pax and long-path headers that real
# Collectives exports can carry.
#
# The final `php -m` checks fail the build here if either extension did not
# actually load, rather than letting composer install below report it as a
# missing platform requirement.
RUN apk add --no-cache rabbitmq-c tar \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS rabbitmq-c-dev unzip \
    && pie install --no-interaction --no-cache php-amqp/php-amqp \
    && docker-php-ext-install -j"$(nproc)" pcntl \
    && apk del .build-deps \
    && php -m | grep -qx amqp \
    && php -m | grep -qx pcntl

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

# --time-limit/--memory-limit: a long-running PHP worker is expected to exit
# periodically and be restarted, which caps the damage from a leak in any one
# process. Compose's `restart:` policy is the restarter, so this image MUST NOT
# be run without one -- `docker run` on its own just stops after an hour.
#
# --memory-limit only does anything if PHP's own memory_limit is higher or
# unlimited; Messenger's is a soft check between messages, and PHP's is a hard
# fatal mid-build.
CMD ["php", "bin/console", "messenger:consume", "builds", \
     "--time-limit=3600", "--memory-limit=512M", "-vv"]
