# ---------- Stage 1: Install PHP dependencies with Composer ----------
FROM php:8.5-cli AS vendor
WORKDIR /app

# Copy only Composer manifests first for better layer caching
COPY composer.json composer.lock ./

# Install system utilities and Composer (use a matching PHP version so lockfile checks pass)
RUN apt-get update && apt-get upgrade -y --no-install-recommends && apt-get install -y --no-install-recommends \
        git curl unzip zip zlib1g-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --version \
    # Install ext-zip so Composer can make use of zip archives where available
    && docker-php-ext-install -j"$(nproc)" zip

# Install production dependencies and optimize autoloader
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-progress \
    --no-interaction \
    --optimize-autoloader

# Development/test dependencies are isolated from production images.
FROM vendor AS vendor-dev
RUN composer install \
    --prefer-dist \
    --no-progress \
    --no-interaction \
    --optimize-autoloader

# ---------- Stage 2: Runtime image ----------
FROM php:8.5-apache AS web
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

# Tell Apache what hostname to use. NOTE: this is not needed to run, only to avoid warnings
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Always-on hardening (ZAP findings 10036/10037): hide Apache version and
# PHP X-Powered-By header regardless of APP_HOST.
RUN { \
      echo "ServerTokens Prod"; \
      echo "ServerSignature Off"; \
    } >> /etc/apache2/conf-available/security.conf \
    && a2enconf security \
    && echo "expose_php = Off" > /usr/local/etc/php/conf.d/zz-hardening.ini

# Optional: set working dir
WORKDIR /var/www/html

# System packages needed for PHP extensions
RUN apt-get update && apt-get upgrade -y --no-install-recommends && apt-get install -y \
    default-mysql-client \
    git \
    unzip \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    zlib1g-dev \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
  --no-install-recommends && rm -rf /var/lib/apt/lists/*

# PHP extensions. DOM is already enabled in the official php:* images;
# PHP 8.5's bundled DOM depends on bundled Lexbor and should not be rebuilt here.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mbstring zip pdo_mysql mysqli curl xmlwriter \
    && php -m | grep -qx sodium \
    && a2enmod rewrite

# Create php ini file for error logging and future php customization
COPY php.ini /usr/local/etc/php/conf.d/php.ini

# Copy application code
COPY ./public/ /var/www/html/
COPY ./src/ /var/www/src/
COPY ./bin/ /var/www/bin/

# Copy the destructive 0.5.0 baseline and immutable forward migrations.
COPY ./database/baseline.sql /usr/local/share/app-migrations/baseline.sql
COPY ./database/migrations/ /var/www/database/migrations/

# Copy Composer vendor from the builder stage
COPY --from=vendor /app/vendor /var/www/vendor

# Set recommended permissions (adjust as needed)
RUN mkdir -p /var/www/config/logs/system && touch /var/www/config/logs/system/error_log.txt && chown -R www-data:www-data /var/www/config && chmod 775 /var/www/config/logs/system && chmod 660 /var/www/config/logs/system/error_log.txt
RUN chown -R www-data:www-data /var/www/html /var/www/src /var/www/bin /var/www/vendor \
    && chmod -R 755 /var/www/html /var/www/src /var/www/bin /var/www/vendor

# RUN chown -R www-data:www-data /var/www/config && chmod -R 755 /var/www/config

# Stamp the build version into the image. The env var can still be overridden at
# runtime; the fallback file makes the version survive in either case.
RUN echo "$APP_VERSION" > /var/www/APP_VERSION

# Entry script
WORKDIR /var/www
COPY ./docker/start.sh /usr/local/bin/start.sh
COPY ./docker/migrate.sh /usr/local/bin/migrate.sh
COPY ./docker/enable-mysql-encryption.sh /usr/local/bin/enable-mysql-encryption.sh
# Normalize Windows CRLF to LF to avoid "env: 'bash\r'" errors
RUN sed -i 's/\r$//' /usr/local/bin/start.sh /usr/local/bin/migrate.sh /usr/local/bin/enable-mysql-encryption.sh \
    && chmod +x /usr/local/bin/start.sh /usr/local/bin/migrate.sh /usr/local/bin/enable-mysql-encryption.sh

EXPOSE 80
CMD ["start.sh"]

# Local/CI target: production-equivalent web runtime plus PHPUnit and tests.
FROM web AS test
COPY --from=vendor-dev /app/vendor /var/www/vendor
COPY --from=vendor /usr/local/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock /var/www/
COPY ./tests/ /var/www/tests/
COPY ./tools/ /var/www/tools/
COPY ./docs/ /var/www/docs/
COPY ./phpunit.xml /var/www/phpunit.xml
COPY ./database/ /var/www/database/
COPY ./public/ /var/www/public/
COPY ./cron/ /var/www/cron/
COPY ./docker/ /var/www/docker/
COPY ./docker-compose.yml /var/www/docker-compose.yml
COPY ./Dockerfile /var/www/Dockerfile
COPY ./php.ini /var/www/php.ini
COPY ./.github/ /var/www/.github/
COPY ./SECURITY.md /var/www/SECURITY.md
RUN mkdir -p /var/www/config
COPY ./config/.env.example /var/www/config/.env.example
RUN chown -R www-data:www-data /var/www/vendor /var/www/tests /var/www/tools /var/www/docs /var/www/database /var/www/public /var/www/cron /var/www/docker /var/www/docker-compose.yml /var/www/Dockerfile /var/www/php.ini /var/www/.github /var/www/config /var/www/phpunit.xml /var/www/composer.json /var/www/composer.lock /var/www/SECURITY.md

# ---------- Stage 3: Encrypted MySQL runtime ----------
FROM mysql:8.4 AS db

USER root
# The upstream image includes MySQL Shell (about 500 MB of optional Python
# tooling) and a statically linked gosu binary. PA uses neither. Use the base
# image's maintained coreutils chroot for the one privilege drop performed by
# the official entrypoint, then remove those unused binaries.
RUN microdnf upgrade -y \
    && microdnf remove -y mysql-shell \
    && microdnf clean all \
    && sed -i 's/exec gosu mysql "\$BASH_SOURCE" "\$@"/exec chroot --userspec=mysql:mysql --groups=mysql \/ "\$BASH_SOURCE" "\$@"/' /usr/local/bin/docker-entrypoint.sh \
    && rm -f /usr/local/bin/gosu \
    && test -x /usr/sbin/chroot \
    && ! command -v mysqlsh >/dev/null 2>&1 \
    && ! command -v gosu >/dev/null 2>&1
COPY ./docker/mysql/mysqld.my /usr/sbin/mysqld.my
COPY ./docker/mysql/component_keyring_file.cnf /usr/lib64/mysql/plugin/component_keyring_file.cnf
COPY ./docker/mysql/pa-encryption.cnf /etc/mysql/conf.d/pa-encryption.cnf
COPY ./docker/mysql/entrypoint.sh /usr/local/bin/pa-mysql-entrypoint.sh
COPY ./docker/mysql/healthcheck.sh /usr/local/bin/pa-mysql-healthcheck.sh
RUN sed -i 's/\r$//' /usr/local/bin/pa-mysql-entrypoint.sh /usr/local/bin/pa-mysql-healthcheck.sh \
    && chmod 0555 /usr/local/bin/pa-mysql-entrypoint.sh /usr/local/bin/pa-mysql-healthcheck.sh \
    && chown root:root /usr/sbin/mysqld.my /usr/lib64/mysql/plugin/component_keyring_file.cnf /etc/mysql/conf.d/pa-encryption.cnf \
    && chmod 0444 /usr/sbin/mysqld.my /usr/lib64/mysql/plugin/component_keyring_file.cnf /etc/mysql/conf.d/pa-encryption.cnf

ENTRYPOINT ["/usr/local/bin/pa-mysql-entrypoint.sh"]
CMD ["mysqld"]
HEALTHCHECK --interval=10s --timeout=5s --start-period=60s --retries=12 \
  CMD ["/usr/local/bin/pa-mysql-healthcheck.sh"]

# ---------- Stage 4: Cron service ----------
# Uses the same vendor stage as web. Source code is volume-mounted at runtime.
FROM php:8.5-cli AS cron
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

RUN apt-get update && apt-get upgrade -y --no-install-recommends && apt-get install -y --no-install-recommends \
        default-mysql-client cron curl tzdata zlib1g-dev libzip-dev libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" zip pdo_mysql mysqli curl \
    && php -m | grep -qx sodium

WORKDIR /var/www

COPY --from=vendor /app/vendor /var/www/vendor
COPY ./src/ /var/www/src/
COPY ./database/migrations/ /var/www/database/migrations/
COPY ./database/baseline.sql /usr/local/share/app-migrations/baseline.sql
COPY ./docker/migrate.sh /usr/local/bin/migrate.sh
COPY ./docker/enable-mysql-encryption.sh /usr/local/bin/enable-mysql-encryption.sh
RUN echo "$APP_VERSION" > /var/www/APP_VERSION \
    && mkdir -p /var/www/config/logs/cron /var/www/backups \
    && chown -R root:root /var/www \
    && chmod -R 755 /var/www \
    && sed -i 's/\r$//' /usr/local/bin/migrate.sh /usr/local/bin/enable-mysql-encryption.sh \
    && chmod +x /usr/local/bin/migrate.sh /usr/local/bin/enable-mysql-encryption.sh

COPY cron/crontab /etc/cron.d/project-alpha
RUN sed -i 's/\r$//' /etc/cron.d/project-alpha && chmod 0644 /etc/cron.d/project-alpha

COPY cron/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

CMD ["entrypoint.sh"]
