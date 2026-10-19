import type { Ref } from 'vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Helpers shared by the chart components in components/charts.
 *
 * Charts only DRAW figures the server already worked out. Bar lengths use ordinary numbers,
 * which is fine for pixels; every value shown as text comes from the server's decimal string
 * through money(), so no floating-point figure ever reaches a reader.
 */

/** A drawn value: the number for the geometry, the text for people. */
export type ChartValue = { value: number; display: string };

/** The width of an element, kept up to date as the screen resizes. */
export function useElementWidth(fallback = 600): { element: Ref<HTMLElement | null>; width: Ref<number> } {
    const element = ref<HTMLElement | null>(null);
    const width = ref(fallback);
    let observer: ResizeObserver | undefined;

    onMounted(() => {
        if (!element.value) {
            return;
        }

        width.value = element.value.clientWidth || fallback;
        observer = new ResizeObserver((entries) => {
            width.value = entries[0]?.contentRect.width || fallback;
        });
        observer.observe(element.value);
    });

    onBeforeUnmount(() => observer?.disconnect());

    return { element, width };
}

/** Axis numbers in short form: 950, 12K, 1.5M. */
export function compact(value: number): string {
    const size = Math.abs(value);
    const sign = value < 0 ? '-' : '';

    if (size >= 1_000_000) {
        return `${sign}${trim(size / 1_000_000)}M`;
    }

    if (size >= 1_000) {
        return `${sign}${trim(size / 1_000)}K`;
    }

    return `${sign}${trim(size)}`;
}

function trim(value: number): string {
    return value >= 100 ? Math.round(value).toString() : (Math.round(value * 10) / 10).toString();
}

/** Round axis ticks covering min..max (always including zero). */
export function niceTicks(min: number, max: number, count = 4): number[] {
    const low = Math.min(0, min);
    const high = Math.max(0, max);

    if (high === low) {
        return [0];
    }

    const rough = (high - low) / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= rough) ?? rough;
    const ticks: number[] = [];

    for (let tick = Math.floor(low / step) * step; tick <= high + step * 0.001; tick += step) {
        ticks.push(Math.round(tick / step) * step);
    }

    if (ticks[ticks.length - 1] < high) {
        ticks.push(ticks[ticks.length - 1] + step);
    }

    return ticks;
}

/**
 * A bar with a 4px rounded data end and a square baseline end.
 *
 * `end` says which side carries the data: 'top' / 'bottom' for columns, 'right' / 'left' for bars.
 */
export function barPath(x: number, y: number, w: number, h: number, end: 'top' | 'bottom' | 'left' | 'right'): string {
    const r = Math.max(0, Math.min(4, w / 2, h / 2));

    if (w <= 0 || h <= 0) {
        return '';
    }

    switch (end) {
        case 'top':
            return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
        case 'bottom':
            return `M${x},${y}V${y + h - r}Q${x},${y + h} ${x + r},${y + h}H${x + w - r}Q${x + w},${y + h} ${x + w},${y + h - r}V${y}Z`;
        case 'right':
            return `M${x},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h - r}Q${x + w},${y + h} ${x + w - r},${y + h}H${x}Z`;
        default:
            return `M${x + w},${y}H${x + r}Q${x},${y} ${x},${y + r}V${y + h - r}Q${x},${y + h} ${x + r},${y + h}H${x + w}Z`;
    }
}

/**
 * Adds decimal strings exactly (six decimals, as the server keeps them), so a total shown under
 * a chart matches the report to the cent.
 */
export function addDecimals(values: string[]): string {
    const scale = 6;
    let sum = 0n;

    for (const value of values) {
        const match = /^(-?)(\d*)(?:\.(\d*))?$/.exec(String(value).trim());

        if (!match) {
            continue;
        }

        const units = BigInt((match[2] || '0') + (match[3] ?? '').padEnd(scale, '0').slice(0, scale));
        sum += match[1] === '-' ? -units : units;
    }

    const negative = sum < 0n;
    const digits = (negative ? -sum : sum).toString().padStart(scale + 1, '0');

    return `${negative ? '-' : ''}${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}
