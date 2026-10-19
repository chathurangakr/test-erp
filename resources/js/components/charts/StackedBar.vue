<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * One bar split into ordered bands (e.g. aging: current → 90+ days), shaded light to dark so
 * the older, more serious bands stand out. A legend under the bar names every band with its
 * value, so nothing depends on colour or hover alone.
 */
const props = defineProps<{
    segments: { key: string; label: string; value: number; display: string }[];
    chartLabel: string;
}>();

const ramp = ['var(--viz-ramp-1)', 'var(--viz-ramp-2)', 'var(--viz-ramp-3)', 'var(--viz-ramp-4)', 'var(--viz-ramp-5)'];
const hovered = ref<string | null>(null);

const total = computed(() => props.segments.reduce((sum, s) => sum + Math.max(0, s.value), 0));
const visible = computed(() =>
    props.segments
        .map((segment, i) => ({ ...segment, colour: ramp[i % ramp.length], share: total.value > 0 ? (Math.max(0, segment.value) / total.value) * 100 : 0 }))
        .filter((segment) => segment.share > 0),
);
</script>

<template>
    <div role="img" :aria-label="chartLabel">
        <div class="flex h-4 w-full gap-0.5 overflow-hidden rounded bg-muted">
            <span
                v-for="segment in visible"
                :key="segment.key"
                class="h-full transition-all duration-500 first:rounded-l last:rounded-r"
                :class="hovered && hovered !== segment.key ? 'opacity-50' : ''"
                :style="{ width: `${segment.share}%`, background: segment.colour }"
                :title="`${segment.label}: ${segment.display}`"
                @pointerenter="hovered = segment.key"
                @pointerleave="hovered = null"
            />
        </div>
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <span
                v-for="(segment, i) in segments"
                :key="segment.key"
                class="flex items-center gap-1.5"
                @pointerenter="hovered = segment.key"
                @pointerleave="hovered = null"
            >
                <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: ramp[i % ramp.length] }" />
                <span class="text-muted-foreground">{{ segment.label }}</span>
                <span class="font-mono tabular-nums">{{ segment.display }}</span>
            </span>
        </div>
    </div>
</template>
