<script setup lang="ts">
import { ChartColumn, ChartNoAxesColumn } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useChartsVisible } from '@/composables/useChartsVisible';
import { useT } from '@/lib/i18n';

const { chartsVisible, setChartsVisible } = useChartsVisible();
const { t } = useT();
</script>

<template>
    <Button
        type="button"
        variant="outline"
        class="print:hidden"
        :aria-pressed="chartsVisible"
        @click="setChartsVisible(!chartsVisible)"
    >
        <ChartNoAxesColumn v-if="chartsVisible" class="size-4" />
        <ChartColumn v-else class="size-4" />
        {{ chartsVisible ? t('Hide charts') : t('Show charts') }}
    </Button>
</template>
