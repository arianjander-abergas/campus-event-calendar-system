# Runs the site as ONE server on Render (or any single Docker host) the same
# way XAMPP does: PHP 8.2 + Apache, with only public/ served. config/,
# includes/ and supabase/ sit next to it and are never reachable from the web.
# (Several servers behind a load balancer on a VPS: deploy/load-balancer/.)
#
# Secrets are NOT in this image (config/*.local.php are gitignored).
# Set them as environment variables on the host instead:
#   ANTHROPIC_API_KEY, ASSISTANT_MODEL (optional)   - the assistant
#   SMTP_USERNAME, SMTP_PASSWORD, SMTP_HOST, SMTP_PORT,
#   SMTP_FROM_EMAIL, SMTP_FROM_NAME                 - notification emails
FROM php:8.2-apache

# curl, mbstring, fileinfo, zlib and openssl are already built into this image.
RUN a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
 && printf '%s\n' \
      '<Directory /var/www/html/public>' \
      '    AllowOverride All' \
      '    Require all granted' \
      '</Directory>' \
      '# Render ends HTTPS before the request reaches Apache: tell PHP the page is secure.' \
      'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on' \
      'ServerTokens Prod' \
      'ServerSignature Off' \
      > /etc/apache2/conf-available/campus-event-hub.conf \
 && a2enconf campus-event-hub \
 && { echo 'expose_php = Off'; echo 'upload_max_filesize = 10M'; echo 'post_max_size = 12M'; } \
      > /usr/local/etc/php/conf.d/campus-event-hub.ini

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Render tells the app which port to use in $PORT (10000 by default).
ENV PORT=10000
CMD sed -ri "s/Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf \
 && sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf \
 && exec apache2-foreground
