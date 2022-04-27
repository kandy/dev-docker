FROM behance/docker-base:2.6-alpine
MAINTAINER Andrii Kasian <akasian@adobe.com>

# Used with alternative CMD (worker.sh), leverages supervisor to maintain long-running processes
ENV APP_ROOT=/app \
    CONTAINER_ROLE=web \
    CONTAINER_PORT=9000 \
    NOT_ROOT_USER=www-data \
    S6_KILL_FINISH_MAXTIME=55000

# Create an unprivileged user
RUN addgroup --gid 1000 $NOT_ROOT_USER && \
  adduser  --uid 1000 \
    --disabled-password \
    --home "$APP_ROOT" \
    --ingroup "$NOT_ROOT_USER" \
    --no-create-home \
    "$NOT_ROOT_USER"

RUN apk update --no-cache && \
    /bin/bash -e /clean.sh

# Overlay the root filesystem from this repo
COPY ./container/root /


# Using a non-privileged port to prevent having to use setcap internally

EXPOSE ${CONTAINER_PORT}

# Set TERM to suppress warning messages.
ENV CONF_PHPFPM=/etc/php7/php-fpm.conf \
    CONF_PHPMODS=/etc/php7/conf.d \
    CONF_FPMPOOL=/etc/php7/php-fpm.d/www.conf \
    CONF_FPMOVERRIDES=/app/overrides.user.ini \
    APP_ROOT=/app \
    PHP_FPM_MAX_CHILDREN=4096 \
    PHP_FPM_START_SERVERS=20 \
    PHP_FPM_MAX_REQUESTS=1024 \
    PHP_FPM_MIN_SPARE_SERVERS=5 \
    PHP_FPM_MAX_SPARE_SERVERS=128 \
    PHP_FPM_MEMORY_LIMIT=256M \
    PHP_FPM_MAX_EXECUTION_TIME=60 \
    PHP_FPM_UPLOAD_MAX_FILESIZE=1M \
    PHP_OPCACHE_MEMORY_CONSUMPTION=128 \
    PHP_OPCACHE_INTERNED_STRINGS_BUFFER=16 \
    PHP_OPCACHE_MAX_WASTED_PERCENTAGE=5 \
    CFG_APP_DEBUG=1

RUN apk update && \
    apk add --no-cache \
      git \
      curl \
      wget \
      php7 \
      php7-bcmath \
      php7-bz2 \
      php7-fpm \
      php7-apcu \
      php7-calendar \
      php7-common \
      php7-ctype \
      php7-curl \
      php7-dom \
      php7-exif \
      php7-gd \
      php7-gettext \
      php7-iconv \
      php7-intl \
      php7-json \
      php7-sodium \
      php7-mbstring \
      php7-mysqlnd \
      php7-opcache \
      php7-openssl \
      php7-pcntl \
      php7-pdo \
      php7-pdo_mysql \
      php7-phar \
      php7-posix \
      php7-session \
      php7-simplexml \
      php7-sockets \
      php7-shmop \
      php7-tokenizer \
      php7-xdebug \
      php7-xml \
      php7-xmlreader \
      php7-xmlwriter \
      php7-xsl \
      php7-zip \
      php7-zlib \
    && \
      # Disable postgres by default
      # Remove extra extensions installed via packages for other versions of PHP, leaving the active engine folder
      rm -rf /usr/lib/php/20121212 && \
      rm -rf /usr/lib/php/20131226 && \
      rm -rf /usr/lib/php/20151012 && \
      rm -rf /usr/lib/php/20160303 && \
      rm -rf /usr/lib/php/20170718 && \
    # Disable xdebug by default \
    sed -i 's/zend_extension\s\?=/;zend_extension =/' $CONF_PHPMODS/xdebug.ini && \
    /bin/bash -e /clean.sh

# Locate and install latest Alpine-compatible NewRelic, seed with variables to be replaced
# Requires PHP to already be installed
RUN NEWRELIC_MUSL_PATH=$(curl -s https://download.newrelic.com/php_agent/release/ | grep 'linux-musl.tar.gz' | cut -d '"' -f2) && \
    NEWRELIC_PATH="https://download.newrelic.com${NEWRELIC_MUSL_PATH}" && \
    curl -L ${NEWRELIC_PATH} -o ./root/newrelic-musl.tar.gz && \
    cd /root && \
    gzip -dc newrelic-musl.tar.gz | tar xf - && \
    rm newrelic-musl.tar.gz && \
    NEWRELIC_DIRECTORY=/root/$(basename $(find . -maxdepth 1 -type d -name newrelic\*)) && \
    cd $NEWRELIC_DIRECTORY && \
    echo "\n" | ./newrelic-install install && \
    chown -R root:root $NEWRELIC_DIRECTORY/agent && \
    mv $NEWRELIC_DIRECTORY/agent/x64/newrelic-20170718.so /usr/lib/php7/modules/newrelic.so && \
    rm -rf $NEWRELIC_DIRECTORY/agent/x64 && \
    # Fix permissions on extracted folder \
    /bin/bash -e /clean.sh

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# - Make additional hacks to migrate files from Ubuntu to Alpine folder structure
RUN cp /etc/php/7.0/mods-available/* $CONF_PHPMODS && \
    rm $CONF_PHPMODS/00_opcache.ini && \
    # - Run standard set of tweaks to ensure runs performant, reliably, and consistent between variants
    touch /var/run/lock && \
    ln -s /usr/sbin/php-fpm7 /usr/sbin/php-fpm && \
    /bin/bash -e prep-php.sh

RUN goss -g /tests/php-fpm/7.2-alpine.goss.yaml validate

USER $NOT_ROOT_USER:$NOT_ROOT_USER


