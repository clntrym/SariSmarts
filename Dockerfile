FROM php:8.2-apache

# Install required PHP extensions (mysqli, pdo, pdo_mysql)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache mod_rewrite for routing and .htaccess support
RUN a2enmod rewrite

# Configure Apache to allow .htaccess overrides and prevent internal port redirection
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && echo "UseCanonicalName Off" >> /etc/apache2/apache2.conf \
    && echo "UseCanonicalPhysicalPort Off" >> /etc/apache2/apache2.conf

# Copy application files to web root
COPY . /var/www/html/

# Set working directory and ownership
WORKDIR /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Create startup script to bind Apache to Render's dynamic PORT variable
RUN printf '#!/bin/bash\nPORT=${PORT:-80}\nsed -i "s/Listen [0-9]*/Listen $PORT/" /etc/apache2/ports.conf\nsed -i "s/:[0-9]*/:$PORT/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]
