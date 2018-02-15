FROM php:7.0-fpm
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

RUN git clone --branch 4.x https://github.com/kandy/php-profiler-extension.git /usr/src/php/ext/tideways
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
    tideways

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN echo 'alias ll="ls -lA"' >>/root/.bashrc


WORKDIR /magento

EXPOSE 9000 9001
CMD ["php-fpm", "-R"]
