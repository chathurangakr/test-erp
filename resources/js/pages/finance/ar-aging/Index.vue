<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { useClientList } from '@/composables/useClientList';
import { Input } from '@/components/ui/input';
import ClientPagination from '@/components/lists/ClientPagination.vue';
import ListControls from '@/components/lists/ListControls.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import ExportButtons from '@/components/print/ExportButtons.vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { index } from '@/routes/ar-aging';
import type { ArAgingReport, ControlAccountCheck } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { exportMethod as exportReport } from '@/routes/ar-aging';

const props = defineProps<{
    report: ArAgingReport;
    control: ControlAccountCheck;
    filters: { as_of: string | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'AR aging', href: index() }] } });

const asOf = ref(props.filters.as_of ?? '');

watch(asOf, (value) => {
    router.get(index().url, { as_of: value || undefined }, { preserveState: true, replace: true, preserveScroll: true });
});

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const { t } = useT();
const { filters: list, visible, pageCount, range, sortBy, clear } = useClientList(() => props.report.rows, {
    search: (row) => `${row.customer_code} ${row.customer_name}`,
    sorts: {
        party: (row) => row.customer_code,
        current: (row) => Number(row.current),
        days_1_30: (row) => Number(row.days_1_30),
        days_31_60: (row) => Number(row.days_31_60),
        days_61_90: (row) => Number(row.days_61_90),
        days_90_plus: (row) => Number(row.days_90_plus),
        total: (row) => Number(row.total),
    },
    defaultSort: 'total',
    defaultDirection: 'desc',
});
</script>

<template>
    <Head :title="t('AR aging')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Accounts receivable aging"
                :description="t('What each customer owes, in :currency, bucketed by how overdue it is.', { currency })"
            />
            <ExportButtons :url="exportReport.url" :query="filters" />
            <PrintButton />
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="grid gap-1.5">
                <label for="f-asof" class="text-sm font-medium">{{ t('As of') }}</label>
                <input id="f-asof" v-model="asOf" type="date" :class="controlClass" />
            </div>
        </div>

        <div
            v-if="control && !control.reconciles"
            class="border-destructive text-destructive rounded-xl border p-4 text-sm"
        >
            <strong>{{ t('Accounts receivable does not reconcile to the ledger.') }}</strong>
            {{ t('The aging totals :total but :code :name carries :balance. Since Trade Receivables is a control account that refuses manual posting, a difference means something needs investigating.', { total: report.totals.total, code: control.code ?? '', name: control.name ?? '', balance: control.balance ?? '' }) }}
        </div>

        <ListControls class="print:hidden" v-model:per-page="list.per_page" v-model:from="list.from" v-model:to="list.to" :period="false" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="list.search" type="search" :placeholder="t('Search customer code or name…')" class="pl-9" />
            </div>
        </ListControls>

        <div
            v-if="report.rows.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('Nothing outstanding') }}</p>
            <p class="text-muted-foreground text-sm">{{ t('Every sales invoice has been settled or reversed.') }}</p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Customer" column="party" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="Current" column="current" align="right" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="1–30" column="days_1_30" align="right" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="31–60" column="days_31_60" align="right" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="61–90" column="days_61_90" align="right" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="90+" column="days_90_plus" align="right" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="Total" column="total" align="right" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in visible"
                        :key="row.customer_id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.customer_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.customer_name }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.current) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.days_1_30) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.days_31_60) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.days_61_90) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.days_90_plus) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.total) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-muted/30">
                    <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3 font-medium">{{ t('Totals') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.current) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.days_1_30) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.days_31_60) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.days_61_90) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.days_90_plus) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <ClientPagination class="print:hidden" v-model:page="list.page" :range="range" :page-count="pageCount" />

        <div v-if="control" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 text-sm">
            <span class="font-medium">{{ control.code }} {{ control.name }}</span>
            carries <span class="font-mono">{{ money(control.balance) }}</span> in the general ledger, against
            an aging total of <span class="font-mono">{{ money(report.totals.total) }}</span> —
            <span :class="control.reconciles ? '' : 'text-destructive font-medium'">
                {{ control.reconciles ? 'reconciled' : 'not reconciled' }}
            </span>.
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs print:hidden">
            {{ t('Every figure is derived from posted invoices less the receipts still allocated to them — nothing is stored, and no customer carries a balance column. A reversed invoice disappears from this report entirely, and a reversed receipt stops reducing what is owed. No revenue or tax is modelled anywhere in this phase.') }}
        </p>
    </div>
</template>
