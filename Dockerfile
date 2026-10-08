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
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund \
    # browser.cjs only require()s the CommonJS build; drop typings, source
    # maps, docs and the ESM/browser bundles (~55 MB -> ~22 MB).
    && find node_modules -type f \( \
           -name '*.d.ts' -o -name '*.d.ts.map' -o -name '*.d.mts' -o -name '*.d.cts' \
           -o -name '*.map' -o -name '*.md' -o -name '*.markdown' \
           -o -name 'LICENSE*' -o -name 'CHANGELOG*' \) -delete \
    && rm -rf \
        node_modules/@types \
        node_modules/puppeteer-core/lib/esm \
        node_modules/puppeteer-core/lib/es5-iife \
        node_modules/puppeteer-core/src \
        node_modules/puppeteer/lib/esm \
        node_modules/puppeteer/src \
        node_modules/chromium-bidi/lib/esm \
        node_modules/devtools-protocol/json \
        node_modules/devtools-protocol/types \
    && node -e "const p = require('/app/node_modules/puppeteer'); if (!p.KnownDevices || !p.launch) process.exit(1)"

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
        tini \
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
        php${PHP_NUMBER}-posix \
        php${PHP_NUMBER}-fileinfo \
    # Chromium pulls in Mesa for GPU rendering. Headless Chromium here renders
    # with Skia on the CPU and ships its own ANGLE (libEGL/libGLESv2), so the
    # Mesa drivers and their LLVM backend (~215 MB) are never loaded.
    # libgbm.so.1 stays because chromium links it; only its dlopen'd backend goes.
    # This must happen in the same RUN as apk add to actually shrink the layer.
    && rm -rf \
        /usr/lib/libLLVM* \
        /usr/lib/libgallium-*.so \
        /usr/lib/dri \
        /usr/lib/gbm \
        /usr/lib/libSPIRV-Tools* \
        /usr/lib/libEGL.so* \
        /usr/lib/libGLES*.so* \
        /usr/lib/libcamera* \
        /usr/lib/libcamera \
        /usr/lib/spa-0.2/libcamera \
        /usr/share/drirc.d \
        /usr/share/man \
        /usr/share/doc \
        /usr/share/info \
    # Fail the build if anything chromium/node/php link against went missing.
    && ! ldd /usr/lib/chromium/chromium /usr/bin/node /usr/bin/php${PHP_NUMBER} /usr/sbin/php-fpm${PHP_NUMBER} 2>&1 | grep -i 'not found' \
    && ln -sf /usr/bin/php${PHP_NUMBER} /usr/bin/php \
    && ln -sf /usr/sbin/php-fpm${PHP_NUMBER} /usr/sbin/php-fpm \
    && rm -f /etc/php${PHP_NUMBER}/php-fpm.d/www.conf \
    && mkdir -p /tmp/chromium \
    && chown nginx:nginx /tmp/chromium

ENV TZ=UTC \
    # Runtime tuning (read by .docker/www.conf)
    PHP_FPM_MAX_CHILDREN=3 \
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
COPY --chmod=755 .docker/reaper.sh /usr/local/bin/browsershot-reaper

WORKDIR /app

COPY --from=node_modules /app/node_modules ./node_modules
COPY --from=vendor /app/vendor ./vendor
COPY app/ ./app/
COPY bin/ ./bin/
COPY public/index.php ./public/index.php

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8000/health >/dev/null || exit 1

# tini as PID 1 reaps zombie processes left by crashed Chromium children.
ENTRYPOINT ["/sbin/tini", "-g", "--", "/entrypoint.sh"]
