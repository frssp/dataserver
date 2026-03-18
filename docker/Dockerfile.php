FROM php:8.4-fpm
ARG http_proxy
ARG https_proxy
# 사내 프록시 CA 등록 (외부 SSL inspection용)
COPY docker/samsungsemi-prx.com.pem /usr/local/share/ca-certificates/samsungsemi-prx.crt
RUN update-ca-certificates
RUN apt-get update && apt-get install -y \
    libmemcached-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    zlib1g-dev \
    libonig-dev \
    libicu-dev \
    unzip \
    git \
    && mkdir -p /tmp/pecl \
    && cd /tmp/pecl \
    && curl -L https://github.com/igbinary/igbinary/archive/refs/tags/3.2.16.tar.gz -o igbinary.tar.gz \
    && tar xzf igbinary.tar.gz && cd igbinary-3.2.16 \
    && phpize && ./configure && make && make install \
    && docker-php-ext-enable igbinary \
    && cd /tmp/pecl \
    && curl -L https://github.com/php-memcached-dev/php-memcached/archive/refs/tags/v3.3.0.tar.gz -o memcached.tar.gz \
    && tar xzf memcached.tar.gz && cd php-memcached-3.3.0 \
    && phpize && ./configure --enable-memcached-igbinary && make && make install \
    && docker-php-ext-enable memcached \
    && cd /tmp/pecl \
    && curl -L https://github.com/phpredis/phpredis/archive/refs/tags/6.1.0.tar.gz -o redis.tar.gz \
    && tar xzf redis.tar.gz && cd phpredis-6.1.0 \
    && phpize && ./configure --enable-redis-igbinary && make && make install \
    && docker-php-ext-enable redis \
    && docker-php-ext-install mysqli mbstring xml curl intl \
    && rm -rf /tmp/pecl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
RUN echo "short_open_tag = On" > /usr/local/etc/php/conf.d/zotero.ini
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/dataserver
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
