FROM php:8.2-apache-bookworm

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/html
COPY . /var/www/html
COPY docker/apache-zemyna.conf /etc/apache2/sites-available/000-default.conf

RUN chown -R www-data:www-data \
    /var/www/html/backend/uploads/incidencias \
    /var/www/html/backend/sesion

EXPOSE 80
