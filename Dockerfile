FROM php:5.6-apache

RUN apt-get update
RUN apt-get upgrade -y
RUN apt-get install -y imagemagick
RUN apt-get install -y libmagickwand-dev
RUN pecl install imagick && docker-php-ext-enable imagick
RUN a2enmod rewrite
RUN a2enmod ssl
RUN a2disconf security

# build and install gifsicle
RUN apt-get install -y build-essential
COPY gifsicle-1.88 /gifsicle
RUN cd /gifsicle && ./configure --disable-gifview && make && make install
RUN apt-get purge -y build-essential && apt-get autoremove -y

RUN apt-get clean && rm -rf /tmp/* /var/tmp*

COPY policy.xml /etc/ImageMagick-6/policy.xml
COPY apache/secure.conf /etc/apache2/sites-enabled
COPY apache/mpm_prefork.conf /etc/apache2/mods-available
COPY apache/php-overrides.ini /usr/local/etc/php/conf.d/php-overrides.ini
RUN mkdir /securekeys
RUN openssl req -newkey rsa:4096 -nodes -keyout /securekeys/private.key -out /securekeys/req.csr -subj "/CN=localhost"
RUN openssl x509 -req -days 3650 -in /securekeys/req.csr -signkey /securekeys/private.key -out /securekeys/cert.pem
COPY cmd.sh /cmd.sh
COPY src/ /var/www/html/
RUN mkdir -p /var/cache/resize && chown www-data:www-data /var/cache/resize
RUN ln -s /var/cache/resize/.mounted /var/www/html/health-check
ADD https://raw.githubusercontent.com/txstate-etc/SSLConfig/master/SSLConfig-TxState.conf /etc/apache2/conf-enabled/ZZZ-SSLConfig-TxState.conf
RUN chsh -s /bin/bash www-data

EXPOSE 443

CMD ["/cmd.sh"]
