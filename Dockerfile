# Bestellseite Metzgerei Lang – PHP 8.3 + Apache
FROM php:8.3-apache

# Zeitzone für Abholtermine und Bestellnummern
ENV TZ=Europe/Berlin
RUN echo "date.timezone=Europe/Berlin" > /usr/local/etc/php/conf.d/zeitzone.ini \
 && echo "expose_php=Off" > /usr/local/etc/php/conf.d/sicherheit.ini \
 && a2enmod headers

# Apache: interne Ordner sperren, Sicherheits-Header, Umgebungsvariablen für PHP
COPY docker/zz-bestellseite.conf /etc/apache2/conf-enabled/zz-bestellseite.conf

# Website-Dateien
COPY index.html /var/www/html/
COPY assets/ /var/www/html/assets/
COPY data/ /var/www/html/data/
COPY api/ /var/www/html/api/
RUN rm -f /var/www/html/api/config.local.php \
 && chown -R www-data:www-data /var/www/html

EXPOSE 80
