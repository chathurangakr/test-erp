import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The signed-in user's effective permissions, for deciding what to SHOW.
 *
 * Hiding a button is a courtesy, not security: every route checks the same permission on the
 * server and answers 403 without it. Never treat `can()` as the thing that protects an action.
 */
export function usePermissions() {
    const page = usePage();
    const permissions = computed(() => new Set(page.props.auth?.permissions ?? []));

    function can(permission: string | undefined): boolean {
        return permission === undefined || permissions.value.has(permission);
    }

    function canAny(...codes: string[]): boolean {
        return codes.some((code) => permissions.value.has(code));
    }

    return { can, canAny };
}
