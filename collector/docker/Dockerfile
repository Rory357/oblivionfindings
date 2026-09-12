FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libssl-dev libsodium-dev \
    && docker-php-ext-install sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/oblivion-monitoring-collector
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist
COPY bin bin
COPY src src

ENTRYPOINT ["php", "bin/oblivion-collector"]
CMD ["version"]
