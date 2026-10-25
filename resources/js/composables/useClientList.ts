import { computed, reactive, watch } from 'vue';

type SortValue = string | number | null | undefined;

export type ClientListOptions<T> = {
    /** The words a row is searched by. */
    search?: (row: T) => string;
    /** The row's date (YYYY-MM-DD) the From / To period applies to. */
    date?: (row: T) => string | null | undefined;
    /** The columns a user may sort by, and the value each sorts on. */
    sorts: Record<string, (row: T) => SortValue>;
    defaultSort: string;
    defaultDirection?: 'asc' | 'desc';
    /** Extra filters of the screen's own: return false to hide a row. */
    keep?: (row: T) => boolean;
};

/**
 * The same controls as a server list (rows per page, All, sorting, a From / To period, search)
 * for a short list the page already holds whole — roles, loans, stock on hand. Nothing is saved:
 * reopening the page starts again from the usual order.
 */
export function useClientList<T>(rows: () => T[], options: ClientListOptions<T>) {
    const filters = reactive({
        search: '',
        from: null as string | null,
        to: null as string | null,
        per_page: 25 as number | 'all',
        sort: options.defaultSort,
        direction: (options.defaultDirection ?? 'asc') as 'asc' | 'desc',
        page: 1,
    });

    const matching = computed(() => {
        const term = filters.search.trim().toLowerCase();

        const kept = rows().filter((row) => {
            if (options.keep && !options.keep(row)) {
                return false;
            }

            if (term !== '' && options.search && !options.search(row).toLowerCase().includes(term)) {
                return false;
            }

            if (options.date && (filters.from || filters.to)) {
                const date = options.date(row) ?? '';

                if ((filters.from && date < filters.from) || (filters.to && date > filters.to)) {
                    return false;
                }
            }

            return true;
        });

        const value = options.sorts[filters.sort] ?? options.sorts[options.defaultSort];
        const sign = filters.direction === 'asc' ? 1 : -1;

        return [...kept].sort((a, b) => {
            const x = value(a);
            const y = value(b);

            if (x === y) return 0;
            if (x === null || x === undefined || x === '') return 1;
            if (y === null || y === undefined || y === '') return -1;

            const numeric = typeof x === 'number' || (typeof x === 'string' && x !== '' && !isNaN(Number(x)) && !/^\d{4}-\d{2}-\d{2}/.test(x));

            return (numeric ? Number(x) - Number(y) : String(x).localeCompare(String(y))) * sign;
        });
    });

    const size = computed(() => (filters.per_page === 'all' ? Math.max(matching.value.length, 1) : filters.per_page));
    const pageCount = computed(() => Math.max(1, Math.ceil(matching.value.length / size.value)));
    const visible = computed(() => matching.value.slice((filters.page - 1) * size.value, filters.page * size.value));
    const range = computed(() => ({
        from: matching.value.length === 0 ? 0 : (filters.page - 1) * size.value + 1,
        to: Math.min(filters.page * size.value, matching.value.length),
        total: matching.value.length,
    }));

    // Any change of filter goes back to page 1.
    watch(
        () => [filters.search, filters.from, filters.to, filters.per_page, filters.sort, filters.direction],
        () => (filters.page = 1),
    );

    function sortBy(key: string): void {
        if (filters.sort === key) {
            filters.direction = filters.direction === 'asc' ? 'desc' : 'asc';
        } else {
            filters.sort = key;
            filters.direction = 'asc';
        }
    }

    function clear(): void {
        Object.assign(filters, { search: '', from: null, to: null, per_page: 25, sort: options.defaultSort, direction: options.defaultDirection ?? 'asc', page: 1 });
    }

    return { filters, visible, pageCount, range, sortBy, clear };
}
