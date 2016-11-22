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
COPY apache/secure.conf /etc/apache2/sites-enabled
COPY apache/mpm_prefork.conf /etc/apache2/mods-available
COPY apache/php-overrides.ini /usr/local/etc/php/conf.d/php-overrides.ini
COPY ssl/private.key /securekeys/private.key
COPY ssl/cert.pem /securekeys/cert.pem
COPY cmd.sh /cmd.sh
COPY src/ /var/www/html/
RUN mkdir -p /var/cache/resize && chown www-data:www-data /var/cache/resize
RUN ln -s /var/cache/resize/.mounted /var/www/html/health-check

EXPOSE 443

CMD ["/cmd.sh"]
