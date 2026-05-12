FROM phpswoole/swoole:php8.3-alpine

RUN apk add --no-cache \
    bash git curl unzip nodejs npm

RUN docker-php-ext-install pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then \
      npm ci --no-audit --no-fund; \
    else \
      npm install --no-audit --no-fund; \
    fi

COPY . .

RUN cp .env.example .env \
    && rm -f bootstrap/cache/*.php \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-scripts --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && npm run build \
    && chmod -R 775 storage bootstrap/cache \
    && rm -f .env

EXPOSE 8000

CMD ["sh", "-lc", "\
  php artisan config:clear --no-interaction || true && \
  php artisan route:clear --no-interaction || true && \
  php artisan view:clear --no-interaction || true && \
  echo \"Ready -> http://localhost:${APP_SERVER_PORT:-8000}\" && \
  exec php artisan octane:start --server=${OCTANE_SERVER:-swoole} --host=${APP_SERVER_HOST:-0.0.0.0} --port=${APP_SERVER_PORT:-8000} --workers=${OCTANE_WORKERS:-2} \
"]
