# syntax=docker/dockerfile:1

# Stage "base": everything but the source.
FROM alpine:latest AS base

RUN apk add --no-cache \
        php85 php85-pecl-amqp php85-pcntl \
        php85-ctype php85-iconv \
        php85-dom php85-mbstring php85-tokenizer php85-xml php85-xmlwriter \
        php85-curl php85-phar php85-openssl php85-zip \
        tar tzdata \
    && php -m | grep -qx amqp \
    && php -m | grep -qx pcntl

# PHP reads TZ when a process starts; the deployment sets it, UTC otherwise.
ENV TZ=UTC
RUN echo 'date.timezone = ${TZ}' > /etc/php85/conf.d/99-timezone.ini

ARG UID=10001
ARG GID=10001
RUN addgroup -S -g "$GID" ssg-worker && adduser -S -u "$UID" -G ssg-worker ssg-worker

WORKDIR /ssg-worker

# Install composer via official composer image
COPY --from=composer/composer:2-bin /composer /usr/bin/composer
# --no-scripts: Flex's auto-scripts call bin/console, which is not copied yet.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts

# var/ holds the Symfony cache, the only directory in the image the worker writes.
RUN mkdir var && chown ssg-worker:ssg-worker var

USER ssg-worker

CMD ["php", "bin/console", "messenger:consume", "builds", \
     "--time-limit=3600", "--memory-limit=512M", "-vv"]

# Stage "release", the default: base plus the source.
FROM base AS release

COPY . .
