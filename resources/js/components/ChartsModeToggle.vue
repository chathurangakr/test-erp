<script setup lang="ts">
import { ChartColumn } from '@lucide/vue';
import { useChartsVisible } from '@/composables/useChartsVisible';
import { useT } from '@/lib/i18n';

const { chartsVisible, setChartsVisible } = useChartsVisible();
const { t } = useT();
</script>

<template>
    <div class="flex items-center justify-between gap-4 rounded-lg border p-4">
        <div class="flex items-start gap-3">
            <ChartColumn class="mt-0.5 h-5 w-5 text-sky-600" />
            <div class="space-y-1">
                <p class="text-sm font-medium">{{ t('Charts') }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ t('Show charts above the tables on the finance dashboard and reports. Only for this device.') }}
                </p>
            </div>
        </div>
        <button
            type="button"
            role="switch"
            :aria-checked="chartsVisible"
            :aria-label="t('Charts')"
            @click="setChartsVisible(!chartsVisible)"
            :class="[
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                chartsVisible ? 'bg-sky-600' : 'bg-neutral-300 dark:bg-neutral-600',
            ]"
        >
            <span
                :class="[
                    'inline-block h-5 w-5 rounded-full bg-white shadow transition-transform',
                    chartsVisible ? 'translate-x-5' : 'translate-x-0.5',
                ]"
            />
        </button>
    </div>
</template>
