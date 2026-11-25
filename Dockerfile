
FROM php:8.4-apache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install system dependencies and PHP extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        dom \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Clean Apache MPM configuration
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true

RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
          /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite \
    && apache2ctl -t \
    && apache2ctl -M

# Set working directory
WORKDIR /var/www/html

# Install PHP dependencies
COPY php/composer.json php/composer.lock /var/www/html/php/

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --working-dir=/var/www/html/php

# Copy project files
COPY . /var/www/html

# Configure PHP and Apache
RUN printf 'upload_max_filesize=12M\npost_max_size=14M\n' \
        > /usr/local/etc/php/conf.d/uploads.ini \
    && printf '\n<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        >> /etc/apache2/apache2.conf \
    && mkdir -p /var/www/html/storage/submissions \
    && chown -R www-data:www-data /var/www/html/storage

# Railway application port
EXPOSE 8080

# Start Apache with clean MPM configuration
CMD ["sh", "-c", "set -e; a2dismod mpm_event mpm_worker 2>/dev/null || true; rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf; a2enmod mpm_prefork rewrite; echo '=== RUNTIME APACHE MODULES ==='; apache2ctl -M; apache2ctl -t; port=\"${PORT:-8080}\"; sed -i \"s/^Listen 80$/Listen ${port}/\" /etc/apache2/ports.conf; sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${port}>/\" /etc/apache2/sites-available/000-default.conf; mkdir -p /var/www/html/storage/submissions; printf 'Require all denied\\n' > /var/www/html/storage/submissions/.htaccess; chown -R www-data:www-data /var/www/html/storage; php /var/www/html/bin/migrate_workflow_schema.php; exec apache2-foreground"]
