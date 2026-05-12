#!/bin/sh
set -e

WORKDIR="${1:-/workspace}"

cd "$WORKDIR"

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
else
    echo "vendor already exists; skipping composer install"
fi

if [ ! -d node_modules/vue ] || [ ! -d node_modules/@inertiajs/vue3 ]; then
    if [ -f package-lock.json ]; then
        npm ci --no-audit --no-fund
    else
        npm install --no-audit --no-fund
    fi
else
    echo "node_modules already exists; skipping npm install"
fi
