ARG ALPINE_VERSION

FROM alpine:${ALPINE_VERSION}

ARG PHP_VERSION
ARG PHP_NUMBER

# Set label information
LABEL org.opencontainers.image.maintainer="Aditya Darma <me@adityadarma.dev>"
LABEL org.opencontainers.image.description="Browsershor base on PHP."
LABEL org.opencontainers.image.os="Alpine Linux ${ALPINE_VERSION}"
LABEL org.opencontainers.image.php="${PHP_VERSION}"

# Install package
RUN apk add --update --no-cache \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ttf-freefont \
    fontconfig \
    nginx \
    supervisor \
    nodejs \
    npm \
    gettext \
    php${PHP_NUMBER} \
    php${PHP_NUMBER}-exif \
    php${PHP_NUMBER}-fileinfo \
    php${PHP_NUMBER}-fpm \
    php${PHP_NUMBER}-gd \
    php${PHP_NUMBER}-json \
    php${PHP_NUMBER}-opcache \
    php${PHP_NUMBER}-openssl \
    php${PHP_NUMBER}-phar \
    && rm -rf /var/cache/apk/*

# Symlink if not found
RUN if [ ! -e /usr/bin/php ]; then ln -s /usr/bin/php${PHP_NUMBER} /usr/bin/php; fi

# Install composer from the official image
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Copy file configurator
COPY .docker/www.conf /etc/php${PHP_NUMBER}/php-fpm.d/www.conf
COPY .docker/php.ini /etc/php${PHP_NUMBER}/conf.d/custom.ini
COPY .docker/nginx.conf /etc/nginx/nginx.conf
COPY .docker/supervisord.conf.template /etc/supervisord.conf.template
COPY .docker/entrypoint.sh /entrypoint.sh

# Setup document root for application
WORKDIR /app

# Replace string and make sure files/folders needed by the processes are accessable when they run under the nobody user
RUN sed -i "s|command=php-fpm -F|command=php-fpm${PHP_NUMBER} -F|g" /etc/supervisord.conf.template && \
    chown -R nobody:nogroup /app /run /var/lib/nginx /var/log/nginx /etc/supervisord.conf && \
    chmod +x /entrypoint.sh && \
    npm install -g puppeteer

# Switch to use a non-root user from here on
USER nobody

# Expose the port nginx is reachable on
EXPOSE 8000

# Start entrypoint
ENTRYPOINT ["/entrypoint.sh"]