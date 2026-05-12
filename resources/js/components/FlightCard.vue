<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useFlightSocket } from '@/composables/useFlightSocket';
import type { Flight } from '@/types/telemetry';

const props = defineProps<{
    flight: Flight;
}>();

const { status, data, lastUpdate } = useFlightSocket(props.flight.id);
const now = ref(Date.now());
let relativeTimer: number | null = null;

onMounted(() => {
    relativeTimer = window.setInterval(() => {
        now.value = Date.now();
    }, 1000);
});

onBeforeUnmount(() => {
    if (relativeTimer !== null) {
        window.clearInterval(relativeTimer);
    }
});

const lastUpdateLabel = computed(() => {
    if (lastUpdate.value === null) {
        return '-';
    }

    const seconds = Math.max(0, Math.floor((now.value - lastUpdate.value) / 1000));
    if (seconds < 1) {
        return 'now';
    }
    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    return `${Math.floor(seconds / 60)}m ago`;
});

const statusClass = computed(() => `status-${status.value.toLowerCase()}`);

function metricValue(key: keyof NonNullable<typeof data.value>): string {
    const value = data.value?.[key];
    if (value === undefined || value === null) {
        return '-';
    }

    return String(value);
}
</script>

<template>
    <article class="flight-card" :class="statusClass">
        <header class="card-head">
            <div class="head-row">
                <span class="flight-num">{{ flight.flightNumber }}</span>
                <span class="status-badge">{{ status }}</span>
            </div>
            <div class="head-row sub">
                <span class="route">
                    {{ flight.origin }}
                    <span class="route-arrow">-&gt;</span>
                    {{ flight.destination }}
                </span>
                <span class="model">{{ flight.model }}</span>
            </div>
        </header>

        <section class="readout">
            <dl class="metric">
                <dt>ALT</dt>
                <dd>
                    <span class="num">{{ metricValue('altitude') }}</span>
                    <span class="unit">m</span>
                </dd>
            </dl>

            <dl class="metric">
                <dt>SPD</dt>
                <dd>
                    <span class="num">{{ metricValue('speed') }}</span>
                    <span class="unit">m/s</span>
                </dd>
            </dl>

            <dl class="metric">
                <dt>ACC</dt>
                <dd>
                    <span class="num">{{ metricValue('acceleration') }}</span>
                    <span class="unit">m/s2</span>
                </dd>
            </dl>

            <dl class="metric">
                <dt>THR</dt>
                <dd>
                    <span class="num">{{ metricValue('thrust') }}</span>
                    <span class="unit">N</span>
                </dd>
            </dl>

            <dl class="metric metric-wide">
                <dt>TEMP</dt>
                <dd>
                    <span class="num">{{ metricValue('temperature') }}</span>
                    <span class="unit">C</span>
                </dd>
            </dl>
        </section>

        <footer class="card-foot">
            <span class="foot-key">PORT</span>
            <span class="foot-val">{{ flight.telemetryPort }}</span>
            <span class="foot-sep">/</span>
            <span class="foot-key">UPDATED</span>
            <span class="foot-val">{{ lastUpdateLabel }}</span>
        </footer>
    </article>
</template>
