<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { index } from '@/routes/grni-reconciliation';
import type { ControlAccountCheck, GrniReconciliationReport } from '@/types';

defineProps<{
    report: GrniReconciliationReport;
    control: ControlAccountCheck;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'GRNI reconciliation', href: index() }] } });

function badgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'FULLY_MATCHED') return 'secondary';
    if (status === 'PARTIALLY_MATCHED') return 'default';
    return 'outline';
}

function label(status: string): string {
    return { UNMATCHED: 'Unmatched', PARTIALLY_MATCHED: 'Partly invoiced', FULLY_MATCHED: 'Fully invoiced' }[status] ?? status;
}
</script>

<template>
    <Head title="GRNI reconciliation" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Goods Received Not Invoiced"
            :description="`Stock received but not yet invoiced, in ${currency}.`"
        />

        <div
            v-if="control && !control.reconciles"
            class="border-destructive text-destructive rounded-xl border p-4 text-sm"
        >
            <strong>The accrual does not reconcile to the ledger.</strong>
            This report shows {{ report.remaining_total }} uninvoiced but {{ control.code }}
            {{ control.name }} carries {{ control.balance }}. GRNI is a control account that refuses
            manual posting, so a difference means something needs investigating.
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Accrued on receipt</p>
                <p class="mt-1 font-mono text-lg">{{ report.original_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Invoiced by suppliers</p>
                <p class="mt-1 font-mono text-lg">{{ report.matched_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Still uninvoiced</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ report.remaining_total }}</p>
            </div>
        </div>

        <div
            v-if="report.rows.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">No accrual to reconcile</p>
            <p class="text-muted-foreground text-sm">
                No goods receipt has raised one yet — receipts before the accounting cutover never do.
            </p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Goods receipt</th>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium">Received from</th>
                        <th class="px-4 py-3 text-right font-medium">Accrued</th>
                        <th class="px-4 py-3 text-right font-medium">Invoiced</th>
                        <th class="px-4 py-3 text-right font-medium">Remaining</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in report.rows"
                        :key="row.goods_receipt_id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">{{ row.grn_number }}</td>
                        <td class="px-4 py-3">{{ row.receipt_date }}</td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.supplier_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.supplier_name }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.original_accrual }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.matched_amount }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ row.remaining_amount }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(row.status)">{{ label(row.status) }}</Badge>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="bg-muted/30">
                    <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3 font-medium" colspan="3">Totals</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ report.original_total }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ report.matched_total }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ report.remaining_total }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            The accrued figure is read from each receipt's own posted journal, never re-derived from
            quantity and unit cost. A receipt may be invoiced by <strong>several suppliers</strong> —
            goods, transport and loading are commonly three different companies — so an amount left
            over here is normal until every one of them has billed. Nothing is written off
            automatically: if invoices never fully clear a receipt, the remainder stays visible.
        </p>
    </div>
</template>
