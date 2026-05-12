import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        flightTelemetryConfig?: {
            reverb_app_key?: string;
            reverb_host?: string;
            reverb_port?: number;
            reverb_scheme?: string;
        };
    }
}

const runtimeConfig = window.flightTelemetryConfig ?? {};
const reverbAppKey = runtimeConfig.reverb_app_key || import.meta.env.VITE_REVERB_APP_KEY || '';
const reverbHost = runtimeConfig.reverb_host || import.meta.env.VITE_REVERB_HOST || window.location.hostname;
const reverbPort = Number(runtimeConfig.reverb_port || import.meta.env.VITE_REVERB_PORT || 8080);
const reverbScheme = runtimeConfig.reverb_scheme || import.meta.env.VITE_REVERB_SCHEME || 'http';

const echo = new Echo({
    broadcaster: 'reverb',
    key: reverbAppKey,
    wsHost: reverbHost,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],
    client: new Pusher(reverbAppKey, {
        wsHost: reverbHost,
        wsPort: reverbPort,
        wssPort: reverbPort,
        forceTLS: reverbScheme === 'https',
        enabledTransports: ['ws', 'wss'],
        cluster: '',
    }),
});

export default echo;
