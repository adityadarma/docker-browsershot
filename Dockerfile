FROM alpine:3.22

ARG PHP_NUMBER=84
ARG UID
ARG GID
ARG USERNAME
ENV TZ="UTC"

# Set label information
LABEL org.opencontainers.image.maintainer="Aditya Darma <me@adityadarma.dev>"
LABEL org.opencontainers.image.description="Browsershot base on PHP."
LABEL org.opencontainers.image.os="Alpine Linux 3.22"
LABEL org.opencontainers.image.php="PHP 8.4"
LABEL org.opencontainers.image.node="Nodejs 22"

# Setup document root for application
WORKDIR /app
COPY ./ /app/

# Install package
RUN apk add --update --no-cache \
    shadow \
    tzdata \
    nginx \
    multirun \
    gettext \
    nodejs \
    npm \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ttf-freefont \
    fontconfig \
    php${PHP_NUMBER} \
    php${PHP_NUMBER}-exif \
    php${PHP_NUMBER}-fileinfo \
    php${PHP_NUMBER}-fpm \
    php${PHP_NUMBER}-gd \
    php${PHP_NUMBER}-json \
    php${PHP_NUMBER}-mbstring \
    php${PHP_NUMBER}-opcache \
    php${PHP_NUMBER}-openssl \
    php${PHP_NUMBER}-phar \
    php${PHP_NUMBER}-session \
    && rm -rf /var/cache/apk/* \
    && if [ ! -e /usr/bin/php ]; then ln -s /usr/bin/php${PHP_NUMBER} /usr/bin/php; fi

# Install composer from the official image
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Copy file configurator
COPY .docker/www.conf /etc/php${PHP_NUMBER}/php-fpm.d/www.conf
COPY .docker/php.ini /etc/php${PHP_NUMBER}/conf.d/custom.ini
COPY .docker/nginx.conf /etc/nginx/nginx.conf
COPY .docker/entrypoint.sh /entrypoint.sh

# Replace string and make sure files/folders needed by the processes are accessable when they run under the nobody user
RUN chmod +x /entrypoint.sh && \
    mkdir -p /tmp/chrome-user && \
    chmod -R 777 /tmp/chrome-user

RUN npm install -g puppeteer && rm -rf /root/.cache /root/.npm

# Expose the port nginx is reachable on
EXPOSE 8000

# Start entrypoint
ENTRYPOINT ["/entrypoint.sh"]