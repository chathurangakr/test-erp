import { router } from '@inertiajs/vue3';

/**
 * How many pages of this system lie behind the current one in the browser's history, so the Back
 * button (BackButton.vue) steps back only while it stays inside the system — otherwise it goes to
 * the page's parent list. Inertia announces `navigate` once for the first page, then only when a
 * visit adds a history entry (filter changes replace theirs) and on the browser's own back /
 * forward. Back / forward is counted as a step back: when unsure, the button errs towards the
 * parent list rather than leaving the system.
 */
let depth = 0;
let firstPageSeen = false;
let fromBrowserHistory = false;

export function initializeBackHistory(): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.addEventListener('popstate', () => {
        fromBrowserHistory = true;
        depth = Math.max(0, depth - 1);
    });

    router.on('navigate', () => {
        if (!firstPageSeen) {
            firstPageSeen = true;
        } else if (fromBrowserHistory) {
            fromBrowserHistory = false;
        } else {
            depth += 1;
        }
    });
}

/** Whether the browser's Back would land on another page of this system. */
export function canStepBack(): boolean {
    return depth > 0;
}
