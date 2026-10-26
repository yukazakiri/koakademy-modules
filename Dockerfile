FROM php:8.5-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY . /app

RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --no-progress \
    && mkdir -p /app/public /data/keys \
    && cp packages.json /app/public/packages.json \
    && cp registry-public-key.txt /app/public/registry-public-key.txt

VOLUME ["/data"]

EXPOSE 8080

ENTRYPOINT ["/bin/sh", "/app/docker/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public"]
