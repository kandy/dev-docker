FROM php:7.1-fpm
MAINTAINER KAndy <to.kandy@gmail.com>

RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libmcrypt-dev \
    libpng12-dev \
    libicu-dev \
    libxslt-dev \
    vim \
    git \
    mysql-client \
    openssh-server \
    libssl-dev \
    libgringotts-dev \
    --no-install-recommends && rm -r /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ --with-mhash --with-openssl \
    && docker-php-ext-install -j$(nproc) \
    gd \
    iconv \
    mbstring \
    opcache \
    bcmath \
    mcrypt \
    soap \
    intl \
    zip \
    xsl \
    pdo_mysql \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

WORKDIR /magento

EXPOSE 9000
CMD ["php-fpm", "-R"]
