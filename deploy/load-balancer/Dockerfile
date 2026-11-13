# One Campus Event Hub app server: PHP 8.2 + Apache, serving public/.
# docker-compose.yml runs three of these behind the Nginx load balancer.
FROM php:8.2-apache

# redis: shared logins and assistant limits across the app servers.
# (curl, mbstring, fileinfo and zlib — the others the site uses — are built in.)
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/zz-hide-version.conf

# Only public/ is the website; config/, includes/ and supabase/ sit beside it.
ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/app/public>\n  AllowOverride All\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/zz-app.conf

# Production PHP settings; posters can be up to 5 MB.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'upload_max_filesize=8M\npost_max_size=10M\nexpose_php=Off\n' > "$PHP_INI_DIR/conf.d/zz-app.ini"

COPY . /var/www/app
RUN chown -R www-data:www-data /var/www/app

HEALTHCHECK --interval=15s --timeout=3s --retries=3 \
  CMD curl -fsS http://localhost/health.php || exit 1
