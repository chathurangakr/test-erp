<script setup lang="ts">
import { computed, ref } from 'vue';
import { barPath, compact, niceTicks, useElementWidth } from '@/lib/charts';

/**
 * A profit bridge: revenue, what each cost takes away, and what is left.
 *
 * Totals (revenue, gross profit, net profit) stand on the zero line in blue; each cost hangs
 * from the previous total in orange. The value sits on every column, which is short enough
 * to label in full.
 */
const props = withDefaults(
    defineProps<{
        steps: { key: string; label: string; value: number; display: string; total: boolean }[];
        totalLabel: string;
        decreaseLabel: string;
        chartLabel: string;
        height?: number;
    }>(),
    { height: 240 },
);

const { element, width } = useElementWidth();
const hovered = ref<string | null>(null);

const margin = { top: 20, right: 8, bottom: 40, left: 44 };
const plotWidth = computed(() => Math.max(120, width.value - margin.left - margin.right));
const plotHeight = computed(() => props.height - margin.top - margin.bottom);

/** Each column's start and end: totals from zero, costs from the running total downwards. */
const spans = computed(() => {
    let running = 0;

    return props.steps.map((step) => {
        if (step.total) {
            running = step.value;

            return { ...step, from: 0, to: step.value };
        }

        const from = running;
        running = running - step.value;

        return { ...step, from, to: running };
    });
});

const ticks = computed(() => {
    const values = spans.value.flatMap((s) => [s.from, s.to]);

    return niceTicks(Math.min(0, ...values), Math.max(0, ...values));
});

function y(value: number): number {
    const low = ticks.value[0];
    const high = ticks.value[ticks.value.length - 1];

    return margin.top + ((high - value) / (high - low || 1)) * plotHeight.value;
}

const band = computed(() => plotWidth.value / Math.max(1, props.steps.length));

/** A label too long for its column is split over two lines rather than overlapping its neighbour. */
function labelLines(label: string): string[] {
    const words = label.split(' ');

    if (words.length < 2 || label.length * 6 < band.value - 4) {
        return [label];
    }

    const half = Math.ceil(words.length / 2);

    return [words.slice(0, half).join(' '), words.slice(half).join(' ')];
}
const barWidth = computed(() => Math.min(40, band.value * 0.6));

const columns = computed(() =>
    spans.value.map((s, i) => {
        const x = margin.left + band.value * i + (band.value - barWidth.value) / 2;
        const top = Math.min(y(s.from), y(s.to));
        const h = Math.max(1, Math.abs(y(s.from) - y(s.to)));
        const goesUp = s.to >= s.from;

        return {
            ...s,
            x,
            top,
            h,
            colour: s.total ? 'var(--viz-1)' : 'var(--viz-2)',
            d: barPath(x, top, barWidth.value, h, goesUp ? 'top' : 'bottom'),
            labelY: goesUp ? top - 6 : top + h + 12,
        };
    }),
);
</script>

<template>
    <div ref="element" class="relative w-full">
        <svg :width="width" :height="height" role="img" :aria-label="chartLabel" class="block">
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
                fill="var(--viz-muted)"
                font-size="11"
                class="tabular-nums"
            >
                {{ compact(tick) }}
            </text>
            <!-- Connectors: each column starts where the previous one ended. -->
            <line
                v-for="(column, i) in columns.slice(0, -1)"
                :key="`c${column.key}`"
                :x1="column.x + barWidth"
                :x2="columns[i + 1].x"
                :y1="y(column.to)"
                :y2="y(column.to)"
                stroke="var(--viz-axis)"
                stroke-width="1"
            />
            <g
                v-for="column in columns"
                :key="column.key"
                class="viz-hit"
                tabindex="0"
                :aria-label="`${column.label}: ${column.display}`"
                @pointerenter="hovered = column.key"
                @pointerleave="hovered = null"
                @focus="hovered = column.key"
                @blur="hovered = null"
            >
                <rect :x="column.x - 6" :y="margin.top" :width="barWidth + 12" :height="plotHeight" fill="transparent" />
                <path :d="column.d" :fill="column.colour" :opacity="hovered && hovered !== column.key ? 0.55 : 1" />
                <text
                    :x="column.x + barWidth / 2"
                    :y="column.labelY"
                    text-anchor="middle"
                    fill="var(--viz-ink)"
                    font-size="11"
                    class="font-mono"
                >
                    {{ compact(column.total ? column.value : -column.value) }}
                </text>
            </g>
            <text
                v-for="(column, i) in columns"
                :key="`l${column.key}`"
                :x="margin.left + band * (i + 0.5)"
                :y="height - 20"
                text-anchor="middle"
                fill="var(--viz-ink-2)"
                font-size="11"
            >
                <tspan
                    v-for="(line, n) in labelLines(column.label)"
                    :key="n"
                    :x="margin.left + band * (i + 0.5)"
                    :dy="n === 0 ? 0 : 13"
                >
                    {{ line }}
                </tspan>
            </text>
        </svg>

        <div
            v-if="hovered"
            class="viz-tooltip"
            :style="{
                left: `${(columns.find((c) => c.key === hovered)?.x ?? 0) + barWidth / 2}px`,
                top: `${columns.find((c) => c.key === hovered)?.top ?? 0}px`,
            }"
        >
            <div class="font-mono font-semibold">{{ columns.find((c) => c.key === hovered)?.display }}</div>
            <div class="text-muted-foreground">{{ columns.find((c) => c.key === hovered)?.label }}</div>
        </div>

        <div class="mt-1 flex flex-wrap gap-4 text-xs text-muted-foreground">
            <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: var(--viz-1)" />{{ totalLabel }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: var(--viz-2)" />{{ decreaseLabel }}</span>
        </div>
    </div>
</template>
