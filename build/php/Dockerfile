#
ARG PHP_VERSION=8.1


FROM php:${PHP_VERSION}-fpm-alpine AS build


RUN apk add --update --no-cache ${PHPIZE_DEPS} bash git tar \
    freetype libpng libjpeg-turbo freetype-dev libpng-dev libjpeg-turbo-dev \
    libsodium-dev \
    libxslt-dev \
    icu-dev \
 #   libressl-dev \
    libzip-dev \
    oniguruma-dev \
# swoole dependensies
    curl-dev openssl-dev pcre-dev pcre2-dev zlib-dev \
    && echo ok
    # grpc dependensies
#    linux-headers

# for local only ENV CFLAGS="-O3 -march=native"
RUN docker-php-source extract
RUN case ${PHP_VERSION}  in \
  7.3) docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ ;; \
  *) docker-php-ext-configure gd --with-freetype --with-jpeg ;; \
esac

RUN case ${PHP_VERSION}  in \
  7.3) ;; \
  *) docker-php-ext-configure mbstring --disable-mbregex  ;; \
esac

RUN docker-php-ext-install -j$(nproc) \
    sockets \
    bcmath \
    gd \
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

RUN pecl channel-update pecl.php.net

# RUN pecl install protobuf  && docker-php-ext-enable protobuf
# RUN pecl install grpc  && docker-php-ext-enable grpc


#RUN NEWRELIC_PATH=https://download.newrelic.com`curl -s https://download.newrelic.com/php_agent/release/ | grep 'linux-musl.tar.gz' | cut -d '"' -f2` && \
#    NR_DIR=$TMPDIR/newrelic/ && mkdir $NR_DIR && \
#    curl -L ${NEWRELIC_PATH} -o $NR_DIR/newrelic.tar.gz && \
#    tar -xvzf $NR_DIR/newrelic.tar.gz -C $NR_DIR --strip 1 && \
#    echo "\n" | $NR_DIR/newrelic-install install && \
#    cp $NR_DIR/agent/x64/newrelic-20170718.so /usr/local/lib/php/extensions/no-debug-non-zts-20170718/newrelic.so && \
#    rm -rf $NR_DIR
#



RUN pecl install -o -f redis && docker-php-ext-enable redis
#RUN pecl install -o -f apcu && docker-php-ext-enable apcu

ARG XDEBUG=0
ARG XDEBUG_VERSION=3.1.4
RUN if [ "$XDEBUG" = "1" ]; then \
      pecl install -o -f xdebug-${XDEBUG_VERSION}  \
      &&  echo Enable xdebug \
      && docker-php-ext-enable xdebug;  \
    fi;

RUN mkdir -p /usr/local/share/misc/php-spx
RUN if [ "$XDEBUG" = "0" ]; then  \
      git clone https://github.com/NoiseByNorthwest/php-spx.git  \
        --depth 1  --single-branch --branch master /usr/src/php/ext/spx  \
      && docker-php-ext-install -j$(nproc) spx;  \
    fi;

ARG SWOOLE_VERSION=4.8.6
RUN mkdir /usr/src/php/ext/swoole && \
    curl -sfL https://github.com/swoole/swoole-src/archive/v${SWOOLE_VERSION}.tar.gz -o swoole.tar.gz && \
    tar xfz swoole.tar.gz --strip-components=1 -C /usr/src/php/ext/swoole && \
    docker-php-ext-configure swoole \
        --enable-http2   \
        --enable-mysqlnd \
        --enable-openssl \
        --enable-sockets --enable-swoole-curl --enable-swoole-json && \
    docker-php-ext-install -j$(nproc)  swoole

RUN php --version && php -m
# RUN php -r 'print_r(gd_info());'
RUN cat /etc/alpine-release

FROM alpine:3.17 AS base

ENV NOT_ROOT_USER=www-data


COPY --from=build /usr/local/bin/php /usr/local/bin/php
COPY --from=build /usr/local/sbin/php-fpm /usr/local/sbin/php-fpm
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=build /usr/local/share/misc/php-spx /usr/local/share/misc/php-spx
RUN apk add --update --no-cache \
		ca-certificates \
		bash \
		git \
		vim \
        unzip \
		curl \
		openssh-client \
		procps \
		strace \
		openssl \
		mysql-client \
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
#RUN set -eux; \
#  addgroup --gid 82 $NOT_ROOT_USER && \
#  adduser  --uid 82 \
#    --disabled-password \
#    --home "$APP_ROOT" \
#    --ingroup "$NOT_ROOT_USER" \
#    --no-create-home \
#    "$NOT_ROOT_USER"


COPY env/php-fpm.conf /usr/local/etc/php-fpm.conf

RUN curl -sS https://getcomposer.org/installer | php --  --2.2 --install-dir=/usr/local/bin --filename=composer


#Setup sendmail in php
RUN echo '#!/usr/bin/php' > /usr/local/bin/sendmail \
 && chmod +x /usr/local/bin/sendmail

# Clean Up
RUN rm -rf /var/cache/apk/* && \
    rm -rf /tmp/* /var/tmp/*

# @todo Add test with container/root/tests/php-fpm/base.goss.yaml

### Collapse layers ###

FROM alpine:3.16
COPY --from=base / .


ARG APP_ROOT="/magento"
WORKDIR $APP_ROOT

# https://github.com/php/php-src/blob/17baa87faddc2550def3ae7314236826bc1b1398/sapi/fpm/php-fpm.8.in#L163
STOPSIGNAL SIGQUIT

EXPOSE 9000


CMD ["php-fpm", "-R"]

