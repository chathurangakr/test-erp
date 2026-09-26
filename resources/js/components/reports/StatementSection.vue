<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { index as generalLedgerIndex } from '@/routes/general-ledger';
import type { StatementRow } from '@/types';

defineProps<{
    title: string;
    rows: StatementRow[];
    total: string;
    totalLabel: string;
    /** Passed through to the general-ledger drill-down so the window matches. */
    from?: string | null;
    to?: string | null;
    emptyMessage?: string;
}>();

/** Flatten the tree so one table can render the whole section with indents. */
function flatten(rows: StatementRow[]): StatementRow[] {
    return rows.flatMap((row) => [row, ...flatten(row.children)]);
}

function ledgerUrl(row: StatementRow, from?: string | null, to?: string | null): string {
    const params = new URLSearchParams({ account_id: String(row.account_id) });

    if (from) params.set('from', from);
    if (to) params.set('to', to);

    return `${generalLedgerIndex().url}?${params.toString()}`;
}
</script>

<template>
    <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
        <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
            <h3 class="text-sm font-medium">{{ title }}</h3>
        </div>

        <table class="w-full text-sm">
            <tbody>
                <tr
                    v-for="row in flatten(rows)"
                    :key="row.account_id"
                    class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    :class="row.is_group ? 'font-medium' : ''"
                >
                    <td class="px-4 py-2">
                        <span :style="{ paddingLeft: `${row.depth * 1.25}rem` }" class="inline-block">
                            <Link
                                v-if="!row.is_group"
                                :href="ledgerUrl(row, from, to)"
                                class="hover:underline"
                            >
                                <span class="text-muted-foreground font-mono text-xs">{{ row.code }}</span>
                                {{ row.name }}
                            </Link>
                            <template v-else>
                                <span class="text-muted-foreground font-mono text-xs">{{ row.code }}</span>
                                {{ row.name }}
                            </template>
                        </span>
                    </td>
                    <td class="px-4 py-2 text-right font-mono">{{ row.amount }}</td>
                </tr>

                <tr v-if="rows.length === 0">
                    <td colspan="2" class="text-muted-foreground px-4 py-6 text-center">
                        {{ emptyMessage ?? 'Nothing posted in this period.' }}
                    </td>
                </tr>

                <tr class="bg-muted/50 border-sidebar-border/70 dark:border-sidebar-border border-t">
                    <td class="px-4 py-3 font-medium">{{ totalLabel }}</td>
                    <td class="px-4 py-3 text-right font-mono font-medium">{{ total }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
