import { onBeforeUnmount, ref } from 'vue';
import echo from '@/lib/echo';
import type { ConnectionStatus, TelemetryPayload } from '@/types/telemetry';

export function useFlightSocket(flightId: string) {
    const status = ref<ConnectionStatus>('WAITING');
    const data = ref<TelemetryPayload['data']>(null);
    const lastUpdate = ref<number | null>(null);

    const channelName = `flight.${flightId}`;
    const channel = echo.channel(channelName);

    channel.listen('.TelemetryUpdated', (payload: TelemetryPayload) => {
        status.value = payload.status || 'WAITING';
        data.value = payload.data || null;
        lastUpdate.value = payload.timestamp || Date.now();
    });

    onBeforeUnmount(() => {
        echo.leaveChannel(channelName);
    });

    return { status, data, lastUpdate };
}
