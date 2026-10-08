# syntax=docker/dockerfile:1

ARG ALPINE_VERSION=3.24

# ---------------------------------------------------------------------------
# Stage 1: PHP dependencies (composer only lives here)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

COPY app/ app/
RUN composer dump-autoload --no-dev --classmap-authoritative

# ---------------------------------------------------------------------------
# Stage 2: Node dependencies (npm only lives here)
# ---------------------------------------------------------------------------
FROM alpine:${ALPINE_VERSION} AS node_modules

RUN apk add --no-cache nodejs npm

WORKDIR /app

# Chromium comes from apk in the runtime stage, so skip Puppeteer's download.
ENV PUPPETEER_SKIP_DOWNLOAD=1

COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund

# ---------------------------------------------------------------------------
# Stage 3: Runtime (no composer, no npm)
# ---------------------------------------------------------------------------
FROM alpine:${ALPINE_VERSION}

ARG PHP_NUMBER=84
ARG VERSION=dev

LABEL org.opencontainers.image.title="docker-browsershot" \
      org.opencontainers.image.description="HTTP API bridge for spatie/browsershot (PDF & screenshot rendering)." \
      org.opencontainers.image.authors="Aditya Darma <me@adityadarma.dev>" \
      org.opencontainers.image.source="https://github.com/adityadarma/docker-browsershot" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${VERSION}"

RUN apk add --no-cache \
        tzdata \
        nginx \
        multirun \
        nodejs \
        chromium \
        fontconfig \
        font-noto \
        font-noto-emoji \
        font-liberation \
        php${PHP_NUMBER} \
        php${PHP_NUMBER}-fpm \
        php${PHP_NUMBER}-opcache \
        php${PHP_NUMBER}-ctype \
        php${PHP_NUMBER}-fileinfo \
        php${PHP_NUMBER}-mbstring \
    && ln -sf /usr/bin/php${PHP_NUMBER} /usr/bin/php \
    && ln -sf /usr/sbin/php-fpm${PHP_NUMBER} /usr/sbin/php-fpm \
    && rm -f /etc/php${PHP_NUMBER}/php-fpm.d/www.conf \
    && mkdir -p /tmp/chromium \
    && chown nginx:nginx /tmp/chromium

ENV TZ=UTC \
    # Runtime tuning (read by .docker/www.conf)
    PHP_FPM_MAX_CHILDREN=5 \
    PHP_FPM_MAX_REQUESTS=50 \
    # Browsershot (read by app/Support/Config.php)
    BROWSERSHOT_NODE_BINARY=/usr/bin/node \
    BROWSERSHOT_NODE_MODULE_PATH=/app/node_modules \
    BROWSERSHOT_CHROME_PATH=/usr/bin/chromium-browser \
    BROWSERSHOT_INCLUDE_PATH=/usr/local/bin:/usr/bin:/bin \
    BROWSERSHOT_NO_SANDBOX=true \
    # Chromium writes config/cache under XDG dirs; keep them in a writable place.
    XDG_CONFIG_HOME=/tmp/chromium \
    XDG_CACHE_HOME=/tmp/chromium \
    PUPPETEER_SKIP_DOWNLOAD=1

COPY .docker/php.ini /etc/php${PHP_NUMBER}/conf.d/99-custom.ini
COPY .docker/www.conf /etc/php${PHP_NUMBER}/php-fpm.d/www.conf
COPY .docker/nginx.conf /etc/nginx/nginx.conf
COPY --chmod=755 .docker/entrypoint.sh /entrypoint.sh

WORKDIR /app

COPY --from=node_modules /app/node_modules ./node_modules
COPY --from=vendor /app/vendor ./vendor
COPY app/ ./app/
COPY public/index.php ./public/index.php

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8000/health >/dev/null || exit 1

ENTRYPOINT ["/entrypoint.sh"]
