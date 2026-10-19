<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ChartValue } from '@/lib/charts';
import { barPath, compact, niceTicks, useElementWidth } from '@/lib/charts';

/**
 * Grouped columns over categories (e.g. months), one or two series on ONE money axis.
 * Hovering or focusing a category shows every series' value; extra lines (such as net profit)
 * can be added to that readout.
 */
const props = withDefaults(
    defineProps<{
        categories: string[];
        series: { name: string; colour: string; values: ChartValue[] }[];
        extra?: { label: string; values: string[] }[];
        height?: number;
        chartLabel: string;
    }>(),
    { extra: () => [], height: 220 },
);

const { element, width } = useElementWidth();
const hovered = ref<number | null>(null);

const margin = { top: 12, right: 8, bottom: 26, left: 44 };
const plotWidth = computed(() => Math.max(120, width.value - margin.left - margin.right));
const plotHeight = computed(() => props.height - margin.top - margin.bottom);

const allValues = computed(() => props.series.flatMap((s) => s.values.map((v) => v.value)));
const ticks = computed(() => niceTicks(Math.min(0, ...allValues.value), Math.max(0, ...allValues.value)));
const low = computed(() => ticks.value[0]);
const high = computed(() => ticks.value[ticks.value.length - 1]);

function y(value: number): number {
    const span = high.value - low.value || 1;

    return margin.top + ((high.value - value) / span) * plotHeight.value;
}

const band = computed(() => plotWidth.value / Math.max(1, props.categories.length));
const barWidth = computed(() => Math.min(24, (band.value * 0.7 - 2 * (props.series.length - 1)) / props.series.length));

const bars = computed(() =>
    props.categories.flatMap((_, i) => {
        const groupWidth = barWidth.value * props.series.length + 2 * (props.series.length - 1);
        const start = margin.left + band.value * i + (band.value - groupWidth) / 2;

        return props.series.map((s, j) => {
            const value = s.values[i]?.value ?? 0;
            const x = start + j * (barWidth.value + 2);
            const top = Math.min(y(value), y(0));
            const h = Math.abs(y(value) - y(0));

            return { key: `${i}-${j}`, colour: s.colour, d: barPath(x, top, barWidth.value, h, value >= 0 ? 'top' : 'bottom') };
        });
    }),
);

const tooltipX = computed(() => (hovered.value === null ? 0 : margin.left + band.value * (hovered.value + 0.5)));
</script>

<template>
    <div ref="element" class="relative w-full">
        <svg :width="width" :height="height" role="img" :aria-label="chartLabel" class="block">
            <g>
                <line
                    v-for="tick in ticks"
                    :key="tick"
                    :x1="margin.left"
                    :x2="margin.left + plotWidth"
                    :y1="y(tick)"
                    :y2="y(tick)"
                    :stroke="tick === 0 ? 'var(--viz-axis)' : 'var(--viz-grid)'"
                    stroke-width="1"
                />
                <text
                    v-for="tick in ticks"
                    :key="`t${tick}`"
                    :x="margin.left - 6"
                    :y="y(tick)"
                    text-anchor="end"
                    dominant-baseline="middle"
                    class="tabular-nums"
                    fill="var(--viz-muted)"
                    font-size="11"
                >
                    {{ compact(tick) }}
                </text>
            </g>
            <rect
                v-if="hovered !== null"
                :x="margin.left + band * hovered"
                :y="margin.top"
                :width="band"
                :height="plotHeight"
                fill="var(--viz-grid)"
                opacity="0.5"
            />
            <path v-for="bar in bars" :key="bar.key" :d="bar.d" :fill="bar.colour" />
            <text
                v-for="(category, i) in categories"
                :key="category"
                :x="margin.left + band * (i + 0.5)"
                :y="height - 8"
                text-anchor="middle"
                fill="var(--viz-muted)"
                font-size="11"
            >
                {{ category }}
            </text>
            <rect
                v-for="(category, i) in categories"
                :key="`hit${category}`"
                class="viz-hit"
                :x="margin.left + band * i"
                :y="margin.top"
                :width="band"
                :height="plotHeight"
                fill="transparent"
                tabindex="0"
                :aria-label="category"
                @pointerenter="hovered = i"
                @pointerleave="hovered = null"
                @focus="hovered = i"
                @blur="hovered = null"
            />
        </svg>

        <div v-if="hovered !== null" class="viz-tooltip" :style="{ left: `${tooltipX}px`, top: `${margin.top}px` }">
            <div class="mb-1 font-medium">{{ categories[hovered] }}</div>
            <div v-for="s in series" :key="s.name" class="flex items-center gap-2">
                <span class="inline-block h-0.5 w-3" :style="{ background: s.colour }" />
                <span class="font-mono font-semibold">{{ s.values[hovered]?.display }}</span>
                <span class="text-muted-foreground">{{ s.name }}</span>
            </div>
            <div v-for="line in extra" :key="line.label" class="mt-1 flex items-center gap-2 border-t pt-1">
                <span class="font-mono font-semibold">{{ line.values[hovered] }}</span>
                <span class="text-muted-foreground">{{ line.label }}</span>
            </div>
        </div>

        <div v-if="series.length > 1" class="mt-1 flex flex-wrap gap-4 text-xs text-muted-foreground">
            <span v-for="s in series" :key="s.name" class="flex items-center gap-1.5">
                <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: s.colour }" />
                {{ s.name }}
            </span>
        </div>
    </div>
</template>
