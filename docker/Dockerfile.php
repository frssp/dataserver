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
    && docker-php-ext-install mysqli mbstring xml curl intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Build igbinary from source (PECL is unreliable)
RUN cd /tmp \
    && curl -fsSL https://github.com/igbinary/igbinary/archive/refs/tags/3.2.16.tar.gz | tar xz \
    && cd igbinary-3.2.16 \
    && phpize && ./configure --enable-igbinary && make -j$(nproc) && make install \
    && docker-php-ext-enable igbinary \
    && rm -rf /tmp/igbinary-3.2.16

# Build memcached from source with igbinary support
RUN cd /tmp \
    && curl -fsSL https://github.com/php-memcached-dev/php-memcached/archive/refs/tags/v3.3.0.tar.gz | tar xz \
    && cd php-memcached-3.3.0 \
    && phpize && ./configure --enable-memcached-igbinary && make -j$(nproc) && make install \
    && docker-php-ext-enable memcached \
    && rm -rf /tmp/php-memcached-3.3.0

# Build redis from source
RUN cd /tmp \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.1.0.tar.gz | tar xz \
    && cd phpredis-6.1.0 \
    && phpize && ./configure --enable-redis-igbinary && make -j$(nproc) && make install \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/phpredis-6.1.0

# Short open tags required by Zotero codebase
RUN echo "short_open_tag = On" > /usr/local/etc/php/conf.d/zotero.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/dataserver

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
