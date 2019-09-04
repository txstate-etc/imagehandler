FROM php:apache as gifsicle
# build and install gifsicle
RUN apt-get update && apt-get upgrade -y
RUN apt-get install -y build-essential automake git
RUN git clone https://github.com/kohler/gifsicle /gifsicle
WORKDIR /gifsicle
RUN git checkout $(git describe --tags $(git rev-list --tags --max-count=1))
RUN autoreconf -i
RUN ./configure --disable-gifview && make && make install

FROM php:apache

RUN apt-get update && apt-get upgrade -y && \
    apt-get install -y libgraphicsmagick1-dev && \
    docker-php-ext-install exif && \
    pecl install gmagick-beta && docker-php-ext-enable gmagick && \
    a2enmod rewrite && a2enmod ssl && a2disconf security && \
    apt-get clean && rm -rf /tmp/* /var/tmp*

COPY apache/secure.conf /etc/apache2/sites-enabled
COPY apache/mpm_prefork.conf /etc/apache2/mods-available
COPY apache/php-overrides.ini /usr/local/etc/php/conf.d/php-overrides.ini
RUN mkdir /securekeys
RUN openssl req -newkey rsa:4096 -nodes -keyout /securekeys/private.key -out /securekeys/req.csr -subj "/CN=localhost"
RUN openssl x509 -req -days 3650 -in /securekeys/req.csr -signkey /securekeys/private.key -out /securekeys/cert.pem
RUN mkdir -p /var/cache/resize && chown www-data:www-data /var/cache/resize
RUN ln -s /var/cache/resize/.mounted /var/www/html/health-check
ADD https://raw.githubusercontent.com/txstate-etc/SSLConfig/master/SSLConfig-TxState.conf /etc/apache2/conf-enabled/ZZZ-SSLConfig-TxState.conf
RUN chsh -s /bin/bash www-data
COPY cmd.sh /cmd.sh
COPY src/ /var/www/html/
COPY --from=gifsicle /usr/local/bin/gifsicle /usr/local/bin/gifsicle

EXPOSE 443

CMD ["/cmd.sh"]
