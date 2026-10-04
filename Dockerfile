FROM php:8.2-apache

# Install required PHP extensions (mysqli, pdo, pdo_mysql)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache mod_rewrite for routing and .htaccess support
RUN a2enmod rewrite

# Configure Apache to allow .htaccess overrides and set ServerName
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && echo "UseCanonicalName Off" >> /etc/apache2/apache2.conf \
    && echo "UseCanonicalPhysicalPort Off" >> /etc/apache2/apache2.conf

# Configure PHP output buffering and include path
RUN echo "include_path = \".:/var/www/html:/var/www/html/platform:/usr/local/lib/php\"" > /usr/local/etc/php/conf.d/custom.ini \
    && echo "output_buffering = 4096" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/conf.d/custom.ini \
    # Errors stay hidden from visitors -- display_errors above is Off, because a
    # PHP error names tables, columns and absolute paths. But they must reach
    # SOMEBODY: without these two lines a fatal is a blank page here and a blank
    # page in the logs, and the only way to find it is to guess. Sending the log
    # to stderr puts the file and line straight into the Render log pane.
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "error_log = /dev/stderr" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/custom.ini

# Copy application files to web root
COPY . /var/www/html/

# Set working directory and ownership
WORKDIR /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Ensure CSS, JS, and Font icons are accessible from platform subfolder
RUN cp -rn /var/www/html/bootstrap-5.3.8-dist /var/www/html/platform/ 2>/dev/null || true \
    && cp -rn /var/www/html/fontawesome-free-7.0.1-web /var/www/html/platform/ 2>/dev/null || true \
    && cp -rn /var/www/html/bootstrap-icons-1.13.1 /var/www/html/platform/ 2>/dev/null || true

# Create startup script to bind Apache to Render's dynamic PORT variable
RUN printf '#!/bin/bash\nPORT=${PORT:-80}\nsed -i "s/Listen [0-9]*/Listen $PORT/" /etc/apache2/ports.conf\nsed -i "s/:[0-9]*/:$PORT/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]

