FROM php:8.5-cli-alpine

WORKDIR /app

COPY . /app

RUN mkdir -p /app/public /data/keys \
    && cp packages.json /app/public/packages.json \
    && cp registry-public-key.txt /app/public/registry-public-key.txt 2>/dev/null || true

VOLUME ["/data"]

EXPOSE 8080

ENTRYPOINT ["/bin/sh", "/app/docker/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public"]
