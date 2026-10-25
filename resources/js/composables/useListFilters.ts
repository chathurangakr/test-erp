import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

/** The controls every list shares — see App\Support\ListQuery. */
export type ListControls = {
    per_page: number | 'all';
    sort: string;
    direction: 'asc' | 'desc';
    from: string | null;
    to: string | null;
};

type FilterValue = string | number | null | undefined;

/**
 * A list's filters, kept in the URL: any change reloads the list (after a short pause, so typing
 * in a search box does not reload on every key) and goes back to page 1.
 */
export function useListFilters<T extends Record<string, FilterValue>>(url: () => string, initial: T & ListControls) {
    const filters = reactive({ ...initial }) as T & ListControls;
    let pause: ReturnType<typeof setTimeout> | undefined;

    function visit(): void {
        const query: Record<string, string | number> = {};

        for (const [key, value] of Object.entries(filters)) {
            if (value !== null && value !== undefined && value !== '' && !(key === 'per_page' && value === 25)) {
                query[key] = value as string | number;
            }
        }

        router.get(url(), query, { preserveState: true, preserveScroll: true, replace: true });
    }

    watch(
        () => ({ ...filters }),
        () => {
            clearTimeout(pause);
            pause = setTimeout(visit, 300);
        },
    );

    /** Click a column: sort by it, or turn the order round when it is already the sort. */
    function sortBy(key: string): void {
        if (filters.sort === key) {
            filters.direction = filters.direction === 'asc' ? 'desc' : 'asc';
        } else {
            filters.sort = key;
            filters.direction = 'asc';
        }
    }

    /** Back to how the list first opened: no filters, the usual order, 25 rows. */
    function clear(): void {
        clearTimeout(pause);
        router.get(url(), {}, { preserveState: false, preserveScroll: true, replace: true });
    }

    return { filters, sortBy, clear };
}
