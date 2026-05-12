<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FlightCard from '@/components/FlightCard.vue';
import { useFlights } from '@/composables/useFlights';

const { flights, loading, error, load } = useFlights();
const now = ref(Date.now());
let clockTimer: number | null = null;

onMounted(() => {
    void load();
    clockTimer = window.setInterval(() => {
        now.value = Date.now();
    }, 1000);
});

onBeforeUnmount(() => {
    if (clockTimer !== null) {
        window.clearInterval(clockTimer);
    }
});

const stationTime = computed(() => {
    return new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
        timeZone: 'Asia/Yangon',
    }).format(new Date(now.value)) + ' MMT';
});
</script>

<template>
    <Head title="Flight Telemetry" />

    <div class="app-shell">
        <header class="masthead">
            <div class="masthead-left">
                <div class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 100 100" width="22" height="22">
                        <path
                            d="M50 12 L62 46 L92 50 L62 54 L50 88 L38 54 L8 50 L38 46 Z"
                            fill="currentColor"
                        />
                    </svg>
                </div>
                <div class="brand-text">
                    <span class="brand-name">FLIGHT TELEMETRY</span>
                    <span class="brand-sub">OPERATIONS / ONENEX FTS</span>
                </div>
            </div>

            <div class="masthead-right">
                <div class="meta">
                    <span class="meta-key">FLEET</span>
                    <span class="meta-val">{{ flights.length }}</span>
                </div>
                <div class="meta">
                    <span class="meta-key">SYNC</span>
                    <span class="meta-val live">{{ stationTime }}</span>
                </div>
            </div>
        </header>

        <main>
            <div v-if="loading && flights.length === 0" class="loading">
                <span class="dot"></span>
                <span class="dot"></span>
                <span class="dot"></span>
                <span class="loading-text">FETCHING FLEET MANIFEST</span>
            </div>

            <div v-else-if="error" class="error-panel">
                <p class="error-title">UPSTREAM UNREACHABLE</p>
                <p class="error-detail">{{ error }}</p>
                <button class="retry" type="button" @click="load">RETRY</button>
            </div>

            <div v-else-if="flights.length === 0" class="empty">
                <p>No flights available.</p>
            </div>

            <div v-else class="flight-grid">
                <FlightCard
                    v-for="flight in flights"
                    :key="flight.id"
                    :flight="flight"
                />
            </div>
        </main>

        <footer class="ground-line">
            <span>FTS-DASHBOARD</span>
        </footer>
    </div>
</template>
