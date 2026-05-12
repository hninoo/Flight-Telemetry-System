#!/bin/sh
set -e

ENV_FILE="${1:-.env}"
HOST_ENV_FILE="${2:-}"

if [ ! -f "$ENV_FILE" ]; then
    cp .env.example "$ENV_FILE"
fi

set_env_if_empty() {
    key="$1"
    value="$2"

    current="$(grep "^${key}=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- || true)"

    if [ -n "$current" ]; then
        return
    fi

    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        printf "%s=%s\n" "$key" "$value" >> "$ENV_FILE"
    fi
}

app_key="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
reverb_id="$(php -r 'echo bin2hex(random_bytes(6));')"
reverb_key="$(php -r 'echo bin2hex(random_bytes(16));')"
reverb_secret="$(php -r 'echo bin2hex(random_bytes(24));')"

set_env_if_empty APP_KEY "$app_key"
set_env_if_empty REVERB_APP_ID "$reverb_id"
set_env_if_empty REVERB_APP_KEY "$reverb_key"
set_env_if_empty REVERB_APP_SECRET "$reverb_secret"

current_reverb_key="$(grep "^REVERB_APP_KEY=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- || true)"
set_env_if_empty VITE_REVERB_APP_KEY "$current_reverb_key"

if [ -n "$HOST_ENV_FILE" ]; then
    cp "$ENV_FILE" "$HOST_ENV_FILE"
fi
