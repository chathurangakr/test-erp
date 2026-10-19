<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Horizontal bars, one per row, in a single colour, with the value at the bar's tip.
 * Negative values draw left of a zero line, so an overdraft or a loss reads as one.
 */
const props = withDefaults(
    defineProps<{
        rows: { key: string | number; label: string; sublabel?: string | null; value: number; display: string }[];
        colour?: string;
        chartLabel: string;
        emptyText?: string;
    }>(),
    { colour: 'var(--viz-1)', emptyText: '' },
);

const hovered = ref<string | number | null>(null);

const largest = computed(() => Math.max(0, ...props.rows.map((r) => r.value)));
const smallest = computed(() => Math.min(0, ...props.rows.map((r) => r.value)));
const span = computed(() => largest.value - smallest.value || 1);
/** Where zero sits, as a share of the track. */
const zero = computed(() => (-smallest.value / span.value) * 100);

function geometry(value: number): { left: string; width: string; radius: string } {
    const size = (Math.abs(value) / span.value) * 100;

    return value >= 0
        ? { left: `${zero.value}%`, width: `${size}%`, radius: '0 4px 4px 0' }
        : { left: `${zero.value - size}%`, width: `${size}%`, radius: '4px 0 0 4px' };
}
</script>

<template>
    <div role="img" :aria-label="chartLabel" class="space-y-2.5">
        <p v-if="rows.length === 0 && emptyText" class="py-4 text-center text-sm text-muted-foreground">{{ emptyText }}</p>
        <div
            v-for="row in rows"
            :key="row.key"
            class="grid grid-cols-[minmax(0,9rem)_1fr] items-center gap-3 rounded-md outline-none sm:grid-cols-[minmax(0,12rem)_1fr]"
            :class="hovered === row.key ? 'bg-muted/60' : ''"
            tabindex="0"
            :title="`${row.label}: ${row.display}`"
            @pointerenter="hovered = row.key"
            @pointerleave="hovered = null"
            @focus="hovered = row.key"
            @blur="hovered = null"
        >
            <div class="min-w-0 text-xs">
                <div class="truncate font-medium">{{ row.label }}</div>
                <div v-if="row.sublabel" class="truncate text-muted-foreground">{{ row.sublabel }}</div>
            </div>
            <div class="flex min-w-0 items-center gap-2">
                <div class="relative h-4 flex-1">
                    <span v-if="smallest < 0" class="absolute inset-y-0 w-px" :style="{ left: `${zero}%`, background: 'var(--viz-axis)' }" />
                    <span
                        class="absolute inset-y-0 transition-all duration-500"
                        :style="{ ...geometry(row.value), borderRadius: geometry(row.value).radius, background: colour, minWidth: row.value === 0 ? '0' : '2px' }"
                    />
                </div>
                <span class="w-28 shrink-0 text-right font-mono text-xs tabular-nums">{{ row.display }}</span>
            </div>
        </div>
    </div>
</template>
