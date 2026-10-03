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

# Render sets the PORT env variable (default 80 or 10000)
# Configure Apache to listen on the port provided by Render
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

ENV PORT=80

EXPOSE 80
