FROM debian:trixie-slim AS gifsicle
# build and install gifsicle
RUN apt-get update && apt-get upgrade -y
RUN apt-get install -y build-essential automake git ca-certificates
RUN git clone https://github.com/kohler/gifsicle /gifsicle
WORKDIR /gifsicle
RUN git checkout $(git describe --tags $(git rev-list --tags --max-count=1))
RUN autoreconf -i
RUN ./configure --disable-gifview && make && make install

FROM debian:trixie-slim

# PHP is built from the php.net source tarball, following the official php:*-apache image
# (https://github.com/docker-library/php/blob/master/8.5/trixie/apache/Dockerfile), so that
# we can pick up security releases before the official image is published
ENV PHP_VERSION=8.5.12
ENV PHP_URL="https://www.php.net/distributions/php-8.5.12.tar.xz" PHP_ASC_URL="https://www.php.net/distributions/php-8.5.12.tar.xz.asc"
ENV PHP_SHA256="08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209"
# PHP 8.5 release managers, from https://www.php.net/gpg-keys.php
ENV GPG_KEYS="1198C0117593497A5EC5C199286AF1F9897469DC 49D9AF6BC72A80D6691719C8AA23F5BE9C7097D4 D95C03BC702BE9515344AE3374E44BC9067701A5"
ENV PHP_INI_DIR=/usr/local/etc/php

RUN apt-get update && apt-get upgrade -y && \
    apt-get install -y --no-install-recommends apache2 ca-certificates curl && \
    savedAptMark="$(apt-mark showmanual)" && \
    apt-get install -y --no-install-recommends \
      apache2-dev autoconf dpkg-dev file g++ gcc gnupg libc-dev make pkg-config re2c xz-utils \
      libgraphicsmagick1-dev libssl-dev libxml2-dev zlib1g-dev && \
    # download and verify php
    mkdir -p /usr/src/php "$PHP_INI_DIR/conf.d" && cd /usr/src && \
    curl -fsSL -o php.tar.xz "$PHP_URL" && \
    echo "$PHP_SHA256 *php.tar.xz" | sha256sum -c - && \
    curl -fsSL -o php.tar.xz.asc "$PHP_ASC_URL" && \
    export GNUPGHOME="$(mktemp -d)" && \
    for key in $GPG_KEYS; do gpg --batch --keyserver keyserver.ubuntu.com --recv-keys "$key"; done && \
    gpg --batch --verify php.tar.xz.asc php.tar.xz && \
    gpgconf --kill all && rm -rf "$GNUPGHOME" && \
    tar -xJf php.tar.xz -C /usr/src/php --strip-components=1 && \
    # build php and mod_php
    cd /usr/src/php && \
    export CFLAGS="-fstack-protector-strong -fpic -fpie -O2 -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64" && \
    export CPPFLAGS="$CFLAGS" LDFLAGS="-Wl,-O1 -pie" && \
    ./configure \
      --build="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)" \
      --with-libdir="lib/$(dpkg-architecture --query DEB_BUILD_MULTIARCH)" \
      --sysconfdir="${PHP_INI_DIR%/php}" \
      --with-config-file-path="$PHP_INI_DIR" \
      --with-config-file-scan-dir="$PHP_INI_DIR/conf.d" \
      --enable-option-checking=fatal \
      --with-pic \
      --with-openssl \
      --with-zlib \
      --enable-exif \
      --without-sqlite3 --without-pdo-sqlite \
      --disable-cgi --disable-phpdbg \
      --with-apxs2 && \
    make -j "$(nproc)" && make install && \
    cp php.ini-* "$PHP_INI_DIR/" && \
    # the last gmagick release (2.0.6RC1, 2021) doesn't build on PHP 8.5; use upstream master plus
    # Remi Collet's open PHP 8.5 fix (https://github.com/vitoc/gmagick/pulls?q=author%3Aremicollet)
    mkdir /usr/src/gmagick && curl -fsSL https://github.com/remicollet/gmagick/archive/2fed131a3e4a52b9cd9ef07d400ee16915d23d2a.tar.gz | tar xz -C /usr/src/gmagick --strip-components=1 && \
    cd /usr/src/gmagick && phpize && ./configure && make -j "$(nproc)" && make install && \
    echo "extension=gmagick" > "$PHP_INI_DIR/conf.d/docker-php-ext-gmagick.ini" && \
    cd / && rm -rf /usr/src/php /usr/src/gmagick /usr/src/php.tar.xz* && \
    # keep only the runtime libraries php and gmagick link against, then drop the build deps
    apt-mark auto '.*' > /dev/null && apt-mark manual $savedAptMark > /dev/null && \
    { find /usr/local /usr/lib/apache2/modules -type f -name '*.so' ; find /usr/local/bin -type f -executable; } \
      | xargs ldd 2>/dev/null \
      | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
      | sort -u | xargs -r dpkg-query --search | awk 'sub(":$", "", $1) { print $1 }' | sort -u | xargs -r apt-mark manual && \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false && \
    php -r 'exit(version_compare(PHP_VERSION, "8.5.12", ">=") && extension_loaded("gmagick") && extension_loaded("exif") ? 0 : 1);' && \
    # apache setup, matching the official image
    a2dismod mpm_event && a2enmod mpm_prefork && \
    printf '<FilesMatch \\.php$>\n\tSetHandler application/x-httpd-php\n</FilesMatch>\n\nDirectoryIndex disabled\nDirectoryIndex index.php index.html\n\n<Directory /var/www/>\n\tOptions -Indexes\n\tAllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/docker-php.conf && \
    a2enconf docker-php && \
    a2enmod rewrite && a2enmod ssl && a2enmod headers && a2disconf security && \
    ln -sfT /dev/stderr /var/log/apache2/error.log && \
    ln -sfT /dev/stdout /var/log/apache2/access.log && \
    ln -sfT /dev/stdout /var/log/apache2/other_vhosts_access.log && \
    rm -rf /var/www/html/* && \
    apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

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
