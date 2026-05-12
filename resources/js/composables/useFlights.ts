import { ref } from 'vue';
import type { Flight } from '@/types/telemetry';

export function useFlights() {
    const flights = ref<Flight[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    async function load() {
        loading.value = true;
        error.value = null;

        try {
            const response = await fetch('/api/flights', {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            flights.value = Array.isArray(payload) ? payload : payload.data || [];
        } catch (caught) {
            error.value = caught instanceof Error ? caught.message : 'Unknown error';
            flights.value = [];
        } finally {
            loading.value = false;
        }
    }

    return { flights, loading, error, load };
}
