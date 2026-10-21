FROM php:8.5.12-apache-trixie AS gifsicle
# build and install gifsicle
RUN apt-get update && apt-get upgrade -y
RUN apt-get install -y build-essential automake git
RUN git clone https://github.com/kohler/gifsicle /gifsicle
WORKDIR /gifsicle
RUN git checkout $(git describe --tags $(git rev-list --tags --max-count=1))
RUN autoreconf -i
RUN ./configure --disable-gifview && make && make install

FROM php:8.5.12-apache-trixie

RUN apt-get update && apt-get upgrade -y && \
    apt-get install -y libgraphicsmagick1-dev && \
    docker-php-ext-install exif && \
    # the last gmagick release (2.0.6RC1, 2021) doesn't build on PHP 8.5; use upstream master plus
    # Remi Collet's open PHP 8.5 fix (https://github.com/vitoc/gmagick/pulls?q=author%3Aremicollet)
    mkdir /tmp/gmagick && curl -sSL https://github.com/remicollet/gmagick/archive/2fed131a3e4a52b9cd9ef07d400ee16915d23d2a.tar.gz | tar xz -C /tmp/gmagick --strip-components=1 && \
    docker-php-ext-install /tmp/gmagick && \
    a2enmod rewrite && a2enmod ssl && a2enmod headers && a2disconf security && \
    apt-get clean && rm -rf /tmp/* /var/tmp*

COPY apache/secure.conf /etc/apache2/sites-enabled
COPY apache/no-multipart.conf /etc/apache2/conf-enabled
COPY apache/mpm_prefork.conf /etc/apache2/mods-available
COPY apache/php-overrides.ini /usr/local/etc/php/conf.d/php-overrides.ini
RUN mkdir /securekeys
RUN openssl req -newkey rsa:4096 -nodes -keyout /securekeys/private.key -out /securekeys/req.csr -subj "/CN=localhost"
RUN openssl x509 -req -days 3650 -in /securekeys/req.csr -signkey /securekeys/private.key -out /securekeys/cert.pem
RUN mkdir -p /var/cache/resize && chown www-data:www-data /var/cache/resize && touch /var/cache/resize/.mounted
ADD https://raw.githubusercontent.com/txstate-etc/SSLConfig/master/SSLConfig-TxState.conf /etc/apache2/conf-enabled/ZZZ-SSLConfig-TxState.conf
RUN chsh -s /bin/bash www-data
COPY cmd.sh /cmd.sh
COPY src/ /var/www/html/
COPY --from=gifsicle /usr/local/bin/gifsicle /usr/local/bin/gifsicle

EXPOSE 443

CMD ["/cmd.sh"]
