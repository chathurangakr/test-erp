import { usePage } from '@inertiajs/vue3';

/**
 * Today's date (YYYY-MM-DD) in the factory's timezone, as decided by the server.
 *
 * Never use `new Date().toISOString()` for a default date: that is the UTC date, which is
 * still yesterday in Sri Lanka until 05:30.
 */
export function businessToday(): string {
    return usePage().props.businessToday;
}
