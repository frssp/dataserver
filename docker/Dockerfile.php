FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    libmemcached-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    zlib1g-dev \
    libonig-dev \
    libicu-dev \
    unzip \
    git \
    && pecl install igbinary \
    && docker-php-ext-enable igbinary \
    && pecl install --configureoptions 'enable-memcached-igbinary="yes"' memcached \
    && pecl install redis \
    && docker-php-ext-enable memcached redis \
    && docker-php-ext-install mysqli mbstring xml curl intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Short open tags required by Zotero codebase
RUN echo "short_open_tag = On" > /usr/local/etc/php/conf.d/zotero.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/dataserver

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
