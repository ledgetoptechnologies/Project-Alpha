# ---------- Stage 1: Install PHP dependencies with Composer ----------
FROM php:8.5.12-cli AS vendor
WORKDIR /app

COPY composer.json ./

RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip zip zlib1g-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && docker-php-ext-install -j"$(nproc)" zip

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-progress \
    --no-interaction \
    --optimize-autoloader

# ---------- Stage 2: Runtime image ----------
FROM php:8.5.12-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client \
        cron \
        curl \
        zlib1g-dev \
        libzip-dev \
        libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by the cron scripts
RUN docker-php-ext-install -j"$(nproc)" zip pdo_mysql mysqli curl

WORKDIR /var/www

# NOTE: Application source is provided via volume mount from docker-compose.yml:
#   - ./src:/var/www/src
# This eliminates cron/src/ duplication — the cron container shares the same
# src/ tree as the web container. Do NOT COPY source files here.

# Copy Composer vendor from the builder stage
COPY --from=vendor /app/vendor /var/www/vendor

# Set proper permissions
RUN chown -R root:root /var/www && chmod -R 755 /var/www

# Install crontab into /etc/cron.d/ (requires user field in each entry)
COPY crontab /etc/cron.d/project-alpha
COPY crontab /usr/local/share/project-alpha/crontab.full
COPY crontab.portal-only /usr/local/share/project-alpha/crontab.portal-only
RUN sed -i 's/\r$//' /etc/cron.d/project-alpha && chmod 0644 /etc/cron.d/project-alpha

# Entrypoint and its shared key resolver (waits for DB, exports env vars, starts cron)
RUN mkdir -p /usr/local/lib/project-alpha
COPY entrypoint-encryption-key.sh /usr/local/lib/project-alpha/cron-encryption-key.sh
COPY execution-profile.sh /usr/local/lib/project-alpha/cron-execution-profile.sh
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/lib/project-alpha/cron-encryption-key.sh /usr/local/lib/project-alpha/cron-execution-profile.sh /usr/local/bin/entrypoint.sh /usr/local/share/project-alpha/crontab.* \
    && chmod 0644 /usr/local/lib/project-alpha/cron-encryption-key.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

CMD ["entrypoint.sh"]
