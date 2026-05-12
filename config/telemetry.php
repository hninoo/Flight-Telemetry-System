<?php

return [

    'host' => env('TELEMETRY_HOST', 'fts.onenex.dev'),
    'api_scheme' => env('TELEMETRY_API_SCHEME', 'https'),
    'api_port' => env('TELEMETRY_API_PORT', 4000),

    'cache_ttl_seconds' => env('TELEMETRY_CACHE_TTL', 60),

    'default_interval_ms' => env('TELEMETRY_INTERVAL_MS', 5000),

    'memory_limit_mb' => env('TELEMETRY_MEMORY_LIMIT_MB', 80),

];
