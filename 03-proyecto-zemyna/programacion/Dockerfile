FROM php:8.2-apache-bookworm

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data \
    /var/www/html/backend/uploads/incidencias \
    /var/www/html/backend/sesion

EXPOSE 80
