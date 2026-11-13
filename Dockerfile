FROM php:8.4-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring dom \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite

WORKDIR /var/www/html

COPY php/composer.json php/composer.lock /var/www/html/php/
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --working-dir=/var/www/html/php

COPY . /var/www/html

RUN printf 'upload_max_filesize=12M\npost_max_size=14M\n' > /usr/local/etc/php/conf.d/uploads.ini \
    && printf '\n<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf \
    && mkdir -p /var/www/html/storage/submissions \
    && chown -R www-data:www-data /var/www/html/storage

EXPOSE 8080

CMD ["sh", "-c", "port=\"${PORT:-8080}\"; sed -i \"s/^Listen 80$/Listen ${port}/\" /etc/apache2/ports.conf; sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${port}>/\" /etc/apache2/sites-available/000-default.conf; mkdir -p /var/www/html/storage/submissions; printf 'Require all denied\\n' > /var/www/html/storage/submissions/.htaccess; chown -R www-data:www-data /var/www/html/storage; exec apache2-foreground"]
