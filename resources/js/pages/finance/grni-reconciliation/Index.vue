<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { index } from '@/routes/grni-reconciliation';
import type { ControlAccountCheck, GrniReconciliationReport } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

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

const { t } = useT();
</script>

<template>
    <Head :title="t('GRNI reconciliation')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Goods Received Not Invoiced"
            :description="t('Stock received but not yet invoiced, in :currency.', { currency })"
        />

        <div
            v-if="control && !control.reconciles"
            class="border-destructive text-destructive rounded-xl border p-4 text-sm"
        >
            <strong>{{ t('The accrual does not reconcile to the ledger.') }}</strong>
            {{ t('This report shows :remaining uninvoiced but :code :name carries :balance. GRNI is a control account that refuses manual posting, so a difference means something needs investigating.', { remaining: report.remaining_total, code: control.code ?? '', name: control.name ?? '', balance: control.balance ?? '' }) }}
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Accrued on receipt') }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(report.original_total) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Invoiced by suppliers') }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(report.matched_total) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Still uninvoiced') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(report.remaining_total) }}</p>
            </div>
        </div>

        <div
            v-if="report.rows.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No accrual to reconcile') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ t('No goods receipt has raised one yet — receipts before the accounting cutover never do.') }}
            </p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Goods receipt') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Received from') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Accrued') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Invoiced') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Remaining') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
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
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.original_accrual) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.matched_amount) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.remaining_amount) }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(row.status)">{{ t(label(row.status)) }}</Badge>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="bg-muted/30">
                    <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3 font-medium" colspan="3">{{ t('Totals') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.original_total) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.matched_total) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.remaining_total) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t("The accrued figure is read from each receipt's own posted journal, never re-derived from quantity and unit cost. A receipt may be invoiced by several suppliers — goods, transport and loading are commonly three different companies — so an amount left over here is normal until every one of them has billed. Nothing is written off automatically: if invoices never fully clear a receipt, the remainder stays visible.") }}
        </p>
    </div>
</template>
