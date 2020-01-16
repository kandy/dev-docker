FROM php:7.3-fpm
MAINTAINER KAndy <to.kandy@gmail.com>

ENV APP_ROOT=/magento \
    CONTAINER_ROLE=web \
    CONTAINER_PORT=9000 \
    NOT_ROOT_USER=www-data

RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libmcrypt-dev \
    libsodium-dev \
    libicu-dev \
    libxslt-dev \
    vim \
    git \
    less \
    default-mysql-client \
    libssl-dev \
    libgringotts-dev \
    unzip \
    libzip-dev \
    --no-install-recommends && rm -r /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/  \
    && docker-php-ext-install -j$(nproc) \
    gd \
    iconv \
    mbstring \
    opcache \
    bcmath \
    soap \
    intl \
    zip \
    xsl \
    pdo_mysql \
    pcntl \
    sockets

RUN pecl install -f xdebug apcu msgpack libsodium swoole \
    && docker-php-ext-enable apcu msgpack swoole


#RUN git clone --branch 4.x https://github.com/kandy/php-profiler-extension.git /usr/src/php/ext/tideways \
#  && docker-php-ext-install -j$(nproc)  tideways

RUN addgroup --gid 1000 $NOT_ROOT_USER && \
  adduser  --uid 1000 \
    --disabled-password \
    --home "$APP_ROOT" \
    --ingroup "$NOT_ROOT_USER" \
    --no-create-home \
    "$NOT_ROOT_USER"


RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR ${APP_ROOT}

EXPOSE ${CONTAINER_PORT}

CMD ["php-fpm", "-R"]
