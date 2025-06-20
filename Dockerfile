ARG ALPINE_VERSION

FROM alpine:${ALPINE_VERSION}

ARG PHP_VERSION
ARG PHP_NUMBER
ARG UID
ARG GID
ARG USERNAME
ENV TZ="Asia/Makassar"

# Set label information
LABEL org.opencontainers.image.maintainer="Aditya Darma <me@adityadarma.dev>"
LABEL org.opencontainers.image.description="Browsershot base on PHP."
LABEL org.opencontainers.image.os="Alpine Linux ${ALPINE_VERSION}"
LABEL org.opencontainers.image.php="${PHP_VERSION}"
LABEL org.opencontainers.image.node="22"

# Setup document root for application
WORKDIR /app

# Install package
RUN apk add --update --no-cache \
    shadow \
    tzdata \
    curl \
    git \
    nano \
    nginx \
    supervisor \
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

# Add grup and user with UID/GID from host
RUN getent group $GID || groupadd -g $GID $USERNAME && \
    useradd -u $UID -g $GID -s /bin/sh -m $USERNAME

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
    chmod +x /entrypoint.sh && \
    git config --system --add safe.directory /app && \
    mkdir -p /tmp/chrome-user && \
    chmod -R 777 /tmp/chrome-user

RUN npm install -g puppeteer && rm -rf /root/.cache /root/.npm

# Expose the port nginx is reachable on
EXPOSE 8000

# Start entrypoint
ENTRYPOINT ["/entrypoint.sh"]