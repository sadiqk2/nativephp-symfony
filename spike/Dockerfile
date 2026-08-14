FROM php:8.4-cli

# PHP extensions Symfony + the NativePHP sqlite path want
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      git unzip libzip-dev libicu-dev libsqlite3-dev libxml2-dev ca-certificates curl; \
    docker-php-ext-install -j"$(nproc)" zip intl pdo_sqlite opcache; \
    rm -rf /var/lib/apt/lists/*

# Node 22 (electron requires >=22)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Electron runtime libraries + a virtual display so we can screenshot
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      xvfb x11-utils imagemagick \
      libgtk-3-0 libnotify4 libnss3 libxss1 libxtst6 libatspi2.0-0 \
      libasound2 libgbm1 libdrm2 libxkbcommon0 libatk1.0-0 libatk-bridge2.0-0 \
      libcups2 libpango-1.0-0 libcairo2 libexpat1 xdg-utils; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /work
