# Build all needed extesion
ARG PHP_VERSION=7.3

FROM php:${PHP_VERSION}-fpm-alpine AS build

MAINTAINER Andrii Kasian <akasian@adobe.com>

RUN apk update && apk upgrade && \
    apk add --no-cache ${PHPIZE_DEPS} bash git tar \
    freetype libpng libjpeg-turbo freetype-dev libpng-dev libjpeg-turbo-dev \
    libsodium-dev \
    libxslt-dev \
    icu-dev \
    libressl-dev \
    libzip-dev

RUN docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/

#RUN docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/  \
#    && curl -k -O https://download.libsodium.org/libsodium/releases/libsodium-1.0.18.tar.gz \
#       && tar xfvz libsodium-1.0.18.tar.gz \
#       && cd libsodium-1.0.18 \
#       && ./configure \
#       && make && make install

RUN docker-php-ext-install -j$(nproc) \
    bcmath \
    gd \
    iconv \
    intl \
    mbstring \
    opcache \
    pcntl \
    soap \
    sockets \
    sodium \
    xsl \
    zip \
    pdo_mysql

# RUN  printf 'no\nno\nno\nyes\nno' | pecl install swoole && docker-php-ext-install swoole



#RUN NEWRELIC_PATH=https://download.newrelic.com`curl -s https://download.newrelic.com/php_agent/release/ | grep 'linux-musl.tar.gz' | cut -d '"' -f2` && \
#    NR_DIR=$TMPDIR/newrelic/ && mkdir $NR_DIR && \
#    curl -L ${NEWRELIC_PATH} -o $NR_DIR/newrelic.tar.gz && \
#    tar -xvzf $NR_DIR/newrelic.tar.gz -C $NR_DIR --strip 1 && \
#    echo "\n" | $NR_DIR/newrelic-install install && \
#    cp $NR_DIR/agent/x64/newrelic-20170718.so /usr/local/lib/php/extensions/no-debug-non-zts-20170718/newrelic.so && \
#    rm -rf $NR_DIR
#



RUN pecl install -o -f redis && docker-php-ext-enable redis
RUN pecl install -o -f apcu && docker-php-ext-enable apcu

ARG XDEBUG=0
RUN pecl install -o -f xdebug  && if [ "$XDEBUG" = "1" ]; then  echo Enable xdebug ; docker-php-ext-enable xdebug;  fi;

RUN git clone https://github.com/NoiseByNorthwest/php-spx.git  \
    --depth 1  --single-branch --branch master /usr/src/php/ext/spx && \
  docker-php-ext-install -j$(nproc) spx

RUN php --version && php -m


FROM alpine:latest AS base

ENV APP_ROOT=/magento
ENV NOT_ROOT_USER=www-data


COPY --from=build /usr/local/bin/php /usr/local/bin/php
COPY --from=build /usr/local/sbin/php-fpm /usr/local/sbin/php-fpm
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=build /usr/local/share/misc/php-spx /usr/local/share/misc/php-spx
RUN apk add --no-cache \
		ca-certificates \
		bash \
		git \
		vim \
    unzip \
		curl \
		openssl \
		$( \
    		scanelf --needed --nobanner --format '%n#p' --recursive /usr/local \
    			| tr ',' '\n' \
    			| sort -u \
    			| awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
    			| tr '\n' ' ' \
    	)


# 82 is the standard uid/gid for "www-data" in Alpine
# https://git.alpinelinux.org/aports/tree/main/apache2/apache2.pre-install?h=3.9-stable
# https://git.alpinelinux.org/aports/tree/main/lighttpd/lighttpd.pre-install?h=3.9-stable
# https://git.alpinelinux.org/aports/tree/main/nginx/nginx.pre-install?h=3.9-stable
RUN set -eux; \
  addgroup --gid 82 $NOT_ROOT_USER && \
  adduser  --uid 82 \
    --disabled-password \
    --home "$APP_ROOT" \
    --ingroup "$NOT_ROOT_USER" \
    --no-create-home \
    "$NOT_ROOT_USER"

COPY env/php.ini /usr/local/etc/php/php.ini
COPY env/php-fpm.conf /usr/local/etc/php-fpm.conf



# Clean Up
RUN rm -rf /var/cache/apk/* && \
    rm -rf /tmp/* /var/tmp/*

# @todo Add test with container/root/tests/php-fpm/base.goss.yaml

### Collapse layers ###

FROM scratch
COPY --from=base / .

WORKDIR /magento
# https://github.com/php/php-src/blob/17baa87faddc2550def3ae7314236826bc1b1398/sapi/fpm/php-fpm.8.in#L163
STOPSIGNAL SIGQUIT

EXPOSE 9000


CMD ["php-fpm", "-R"]

