FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git curl wget gnupg zip unzip \
    libpng-dev libonig-dev libxml2-dev libzip-dev \
    libcurl4-openssl-dev libssl-dev \
    && docker-php-ext-install -j$(nproc) \
        mbstring exif pcntl bcmath zip sockets curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs && npm install -g npm@latest \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN echo "APP_KEY=" > .env \
    && composer install \
        --no-scripts --no-autoloader \
        --ignore-platform-reqs --no-interaction --prefer-dist

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; \
    then npm ci --silent; \
    else npm install --legacy-peer-deps --silent; \
    fi

COPY . .

RUN composer dump-autoload --optimize \
    && php artisan key:generate --force --no-interaction \
    && npm run build \
    && php artisan storage:link --no-interaction 2>/dev/null || true \
    && chmod -R 775 storage bootstrap/cache \
    && rm -f .env

EXPOSE 8000

CMD ["sh", "-c", "\
    APP_KEY=$(php -r \"echo 'base64:'.base64_encode(random_bytes(32));\" ) && \
    export APP_KEY=$APP_KEY && \
    php artisan config:cache --no-interaction && \
    php artisan route:cache  --no-interaction && \
    php artisan view:cache   --no-interaction && \
    echo '✅ Ready → http://localhost:8000' && \
    php artisan serve --host=0.0.0.0 --port=8000 \
"]