<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { index as generalLedgerIndex } from '@/routes/general-ledger';
import { inventoryGlReconciliation, inventoryValuation } from '@/routes/reports';
import type { InventoryGlReconciliationReport } from '@/types';

defineProps<{ report: InventoryGlReconciliationReport; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Inventory vs GL', href: inventoryGlReconciliation() }] } });
</script>

<template>
    <Head title="Inventory vs general ledger" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading
                title="Inventory vs general ledger"
                description="Two independent records of the same value, compared to six decimals."
            />
            <Badge :variant="report.reconciled ? 'default' : 'destructive'" class="w-fit">
                {{ report.reconciled ? 'Reconciled' : 'Differences found' }}
            </Badge>
        </div>

        <div
            v-if="!report.accounting_enabled"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            <strong>Operational accounting is not switched on.</strong> No cutover date is set,
            so stock movements post no journals at all. Every difference below is expected: the
            inventory is real, and the books simply have not been opened on it.
        </div>
        <div
            v-else-if="report.cutover_date"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            Accounting applies from <strong>{{ report.cutover_date }}</strong>. Stock that moved
            before that date raised no journal, so it can legitimately show a difference here.
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Inventory ledger ({{ currency }})</p>
                <p class="mt-1 font-mono text-lg">{{ report.inventory_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">General ledger</p>
                <p class="mt-1 font-mono text-lg">{{ report.gl_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Difference</p>
                <p
                    class="mt-1 font-mono text-lg font-medium"
                    :class="Number(report.difference_total) !== 0 ? 'text-destructive' : ''"
                >
                    {{ report.difference_total }}
                </p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">GL account</th>
                        <th class="px-4 py-3 font-medium">Products</th>
                        <th class="px-4 py-3 text-right font-medium">Inventory ledger</th>
                        <th class="px-4 py-3 text-right font-medium">GL balance</th>
                        <th class="px-4 py-3 text-right font-medium">Difference</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in report.rows"
                        :key="row.account_id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="`${generalLedgerIndex().url}?account_id=${row.account_id}`"
                                class="font-mono text-xs font-medium hover:underline"
                            >
                                {{ row.account_code }}
                            </Link>
                            <span class="text-muted-foreground block text-xs">{{ row.account_name }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ row.products.join(', ') || '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.inventory_value }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.gl_balance }}</td>
                        <td
                            class="px-4 py-3 text-right font-mono"
                            :class="row.status === 'DIFFERENCE' ? 'text-destructive font-medium' : ''"
                        >
                            {{ row.difference }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="row.status === 'MATCHED' ? 'default' : 'destructive'">
                                {{ row.status }}
                            </Badge>
                        </td>
                    </tr>
                    <tr v-if="report.rows.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">
                            No inventory value and no inventory account balances.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="Number(report.unmapped_value) !== 0"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 text-sm"
        >
            <p class="font-medium">Stock with no accounting mapping</p>
            <p class="mt-1">
                <span class="font-mono">{{ report.unmapped_value }}</span>
                held by
                <span class="font-mono text-xs">{{ report.unmapped_products.join(', ') }}</span
                >. Real value with no ledger account to compare against — reported here rather
                than absorbed into someone else's account.
            </p>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            The inventory ledger says value moved because stock moved; the general ledger says
            it moved because a journal was posted. After accounting-integrated operations the
            two agree exactly, because every stock movement writes its journal inside the same
            database transaction. <strong>A difference is shown, never corrected.</strong> The
            usual honest cause is stock that predates the accounting cutover; the remedy is an
            opening-balance process, which is not built. Journals are never backfilled
            automatically — inventing history to tidy a report destroys the one thing an audit
            trail is for. Figures are shown to six decimals so a small difference can be
            diagnosed rather than rounded away.
            <Link :href="inventoryValuation()" class="underline">See the valuation detail</Link>.
        </p>
    </div>
</template>
