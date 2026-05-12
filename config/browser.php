<?php

return [
    'flight_telemetry' => [
        'reverb_app_key' => env('VITE_REVERB_APP_KEY', env('REVERB_APP_KEY')),
        'reverb_host' => env('VITE_REVERB_HOST', 'localhost'),
        'reverb_port' => (int) env('VITE_REVERB_PORT', 8080),
        'reverb_scheme' => env('VITE_REVERB_SCHEME', 'http'),
    ],
];
