FROM php:7.0
MAINTAINER KAndy <to.kandy@gmail.com>

RUN apt-get update && apt-get install -y \
    zlib1g-dev \
    git \
    openssh-server \
    libssl-dev \
    --no-install-recommends && rm -r /var/lib/apt/lists/* \
    && docker-php-ext-install zip && pecl install mongodb && docker-php-ext-enable mongodb

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN git clone https://github.com/kandy/xhgui.git /xhgui && cd /xhgui && composer install --no-dev

WORKDIR /xhgui

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "-t", "/xhgui/webroot/", "/xhgui/router.php"]
