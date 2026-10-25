<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { useClientList } from '@/composables/useClientList';
import { Input } from '@/components/ui/input';
import ClientPagination from '@/components/lists/ClientPagination.vue';
import ListControls from '@/components/lists/ListControls.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import ExportButtons from '@/components/print/ExportButtons.vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Badge } from '@/components/ui/badge';
import { index } from '@/routes/grni-reconciliation';
import type { ControlAccountCheck, GrniReconciliationReport } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { exportMethod as exportReport } from '@/routes/grni-reconciliation';

const props = defineProps<{
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
const { filters, visible, pageCount, range, sortBy, clear } = useClientList(() => props.report.rows, {
    search: (row) => `${row.grn_number} ${row.supplier_code} ${row.supplier_name}`,
    date: (row) => row.receipt_date,
    sorts: {
        receipt: (row) => row.grn_number,
        date: (row) => row.receipt_date,
        supplier: (row) => row.supplier_code,
        accrued: (row) => Number(row.original_accrual),
        invoiced: (row) => Number(row.matched_amount),
        remaining: (row) => Number(row.remaining_amount),
        status: (row) => t(label(row.status)),
    },
    defaultSort: 'date',
    defaultDirection: 'desc',
});
</script>

<template>
    <Head :title="t('GRNI reconciliation')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Goods Received Not Invoiced"
                :description="t('Stock received but not yet invoiced, in :currency.', { currency })"
            />
            <ExportButtons :url="exportReport.url" />
            <PrintButton />
        </div>

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

        <ListControls class="print:hidden" v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search goods receipt or supplier…')" class="pl-9" />
            </div>
        </ListControls>

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
                        <SortHeader label="Goods receipt" column="receipt" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Received from" column="supplier" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Accrued" column="accrued" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Invoiced" column="invoiced" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Remaining" column="remaining" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in visible"
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

        <ClientPagination class="print:hidden" v-model:page="filters.page" :range="range" :page-count="pageCount" />

        <p class="text-muted-foreground max-w-3xl text-xs print:hidden">
            {{ t("The accrued figure is read from each receipt's own posted journal, never re-derived from quantity and unit cost. A receipt may be invoiced by several suppliers — goods, transport and loading are commonly three different companies — so an amount left over here is normal until every one of them has billed. Nothing is written off automatically: if invoices never fully clear a receipt, the remainder stays visible.") }}
        </p>
    </div>
</template>
