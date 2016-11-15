FROM php:5.6-apache

RUN apt-get update
RUN apt-get upgrade -y
RUN apt-get install -y imagemagick
RUN apt-get install -y libmagickwand-dev
RUN pecl install imagick && docker-php-ext-enable imagick
RUN a2enmod rewrite
RUN a2enmod ssl

RUN apt-get clean && rm -rf /tmp/* /var/tmp*

COPY policy.xml /etc/ImageMagick-6/policy.xml
COPY secure.conf /etc/apache2/sites-enabled
COPY mpm_prefork.conf /etc/apache2/mods-available
COPY private.key /securekeys/private.key
COPY cert.pem /securekeys/cert.pem
COPY cmd.sh /cmd.sh
COPY src/ /var/www/html/
RUN mkdir -p /var/cache/resize && chown www-data:www-data /var/cache/resize

EXPOSE 443

CMD ["/cmd.sh"]
