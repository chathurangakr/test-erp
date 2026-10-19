import type { Ref } from 'vue';
import { ref } from 'vue';

/**
 * Whether report screens show their charts — a per-device choice, switched from Settings →
 * Appearance or the "Charts" button on any screen that has them. On unless someone turns it off.
 * The tables beside the charts always show, so hiding charts never hides a figure.
 */
const STORAGE_KEY = 'charts-visible';

function readStored(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) !== 'off';
    } catch {
        return true;
    }
}

const visible = ref(typeof window === 'undefined' ? true : readStored());

export function useChartsVisible(): { chartsVisible: Ref<boolean>; setChartsVisible: (value: boolean) => void } {
    function setChartsVisible(value: boolean): void {
        visible.value = value;

        try {
            localStorage.setItem(STORAGE_KEY, value ? 'on' : 'off');
        } catch {
            // Storage blocked: the choice still applies until the page is reloaded.
        }
    }

    return { chartsVisible: visible, setChartsVisible };
}
