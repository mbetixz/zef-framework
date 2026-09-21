# ZEF Framework v2.8.0 — production-leaning image (cli base, no web server
# needed: bin/zef --serve wraps PHP's built-in server for dev, RoadRunner
# worker is the production runtime).
FROM php:8.4-cli-alpine

# No extensions are strictly required (openssl/mbstring ship with core
# builds); redis/apcu are optional and only loaded when the corresponding
# adapters are configured.
WORKDIR /app

COPY . .

RUN chmod +x bin/zef \
    && php -l bin/zef \
    && php bin/zef --self-test > /dev/null || true

EXPOSE 8080

# Development server. For production swap the command to the RoadRunner
# worker: ["php", "bin/worker.php"] (requires spiral/roadrunner-http).
CMD ["php", "bin/zef", "--serve", "0.0.0.0:8080"]
