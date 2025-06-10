ARG ALPINE_VERSION
ARG NODE_VERSION

FROM node:${NODE_VERSION}-alpine AS node

FROM alpine:${ALPINE_VERSION}

ARG PHP_VERSION
ARG PHP_NUMBER
ENV TZ="Asia/Makassar"

# Set label information
LABEL org.opencontainers.image.maintainer="Aditya Darma <me@adityadarma.dev>"
LABEL org.opencontainers.image.description="Browsershor base on PHP."
LABEL org.opencontainers.image.os="Alpine Linux ${ALPINE_VERSION}"
LABEL org.opencontainers.image.php="${PHP_VERSION}"
LABEL org.opencontainers.image.node="${NODE_VERSION}"

# Install package
RUN apk add --update --no-cache \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ttf-freefont \
    fontconfig \
    tzdata \
    curl \
    git \
    nano \
    nginx \
    supervisor \
    gettext \
    php${PHP_NUMBER} \
    php${PHP_NUMBER}-bcmath \
    php${PHP_NUMBER}-curl \
    php${PHP_NUMBER}-ctype \
    php${PHP_NUMBER}-dom \
    php${PHP_NUMBER}-exif \
    php${PHP_NUMBER}-fileinfo \
    php${PHP_NUMBER}-iconv \
    php${PHP_NUMBER}-fpm \
    php${PHP_NUMBER}-gd \
    php${PHP_NUMBER}-json \
    php${PHP_NUMBER}-mbstring \
    php${PHP_NUMBER}-opcache \
    php${PHP_NUMBER}-openssl \
    php${PHP_NUMBER}-phar \
    php${PHP_NUMBER}-pdo_mysql \
    php${PHP_NUMBER}-session \
    php${PHP_NUMBER}-simplexml \
    php${PHP_NUMBER}-tokenizer \
    php${PHP_NUMBER}-xml \
    php${PHP_NUMBER}-xmlreader \
    php${PHP_NUMBER}-xmlwriter \
    php${PHP_NUMBER}-zip \
    && rm -rf /var/cache/apk/*

# Symlink if not found
RUN if [ ! -e /usr/bin/php ]; then ln -s /usr/bin/php${PHP_NUMBER} /usr/bin/php; fi

# Install composer from the official image
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Install node from the official image
COPY --from=node /usr/lib /usr/lib
COPY --from=node /usr/local/lib /usr/local/lib
COPY --from=node /usr/local/include /usr/local/include
COPY --from=node /usr/local/bin /usr/local/bin

# Copy file configurator
COPY .docker/www.conf /etc/php${PHP_NUMBER}/php-fpm.d/www.conf
COPY .docker/php.ini /etc/php${PHP_NUMBER}/conf.d/custom.ini
COPY .docker/nginx.conf /etc/nginx/nginx.conf
COPY .docker/supervisord.conf.template /etc/supervisord.conf.template

# Setup document root for application
WORKDIR /app

# Replace string and make sure files/folders needed by the processes are accessable when they run under the nobody user
RUN sed -i "s|command=php-fpm -F|command=php-fpm${PHP_NUMBER} -F|g" /etc/supervisord.conf.template && \
    chown -R nobody:nogroup /app /run /var/lib/nginx /var/log/nginx /etc/supervisord.conf && \
    git config --system --add safe.directory /app

# Copy file entrypoint to container
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
RUN npm install -g puppeteer

# Switch to use a non-root user from here on
USER nobody

# Expose the port nginx is reachable on
EXPOSE 8000

# Start entrypoint
ENTRYPOINT ["/entrypoint.sh"]