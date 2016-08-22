FROM php:5.6-apache

RUN apt-get update
RUN apt-get upgrade -y
RUN apt-get install -y imagemagick
RUN apt-get install -y libmagickwand-dev
RUN pecl install imagick && docker-php-ext-enable imagick

RUN apt-get clean && rm -rf /tmp/* /var/tmp*

COPY policy.xml /etc/ImageMagick-6/policy.xml
COPY src/ /var/www/html/
RUN mkdir -p /var/cache/resize && chown www-data:www-data /var/cache/resize
