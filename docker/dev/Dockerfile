# syntax=docker/dockerfile:1

# Dev image: the worker's dependencies, without the source; publish's dev compose mounts the checkout.
# Build context is the repo root: docker build -f docker/dev/Dockerfile .
FROM alpine:latest

# openssl and zip are for composer.
RUN apk add --no-cache \
        php85 php85-pecl-amqp php85-pcntl \
        php85-ctype php85-iconv php85-simplexml \
        php85-dom php85-mbstring php85-tokenizer php85-xml php85-xmlwriter \
        php85-curl php85-phar php85-openssl php85-zip \
        tar tzdata

ENV TZ=UTC
COPY docker/php/php.ini /etc/php85/conf.d/99-publish-worker.ini

ARG UID=10001
ARG GID=10001
RUN addgroup -S -g "$GID" publish-worker && adduser -S -u "$UID" -G publish-worker publish-worker

WORKDIR /publish-worker

COPY --from=composer/composer:2-bin /composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-interaction --no-progress

# var/ holds the Symfony cache, var/log/, var/build_temp/ and var/published/, the only directory the worker writes.
# var/build_temp exists so a new named volume mounted there belongs to publish-worker.
RUN mkdir -p var/build_temp && chown -R publish-worker:publish-worker var

USER publish-worker

CMD ["php", "bin/console", "messenger:consume", "builds", \
     "--time-limit=3600", "--memory-limit=512M"]
