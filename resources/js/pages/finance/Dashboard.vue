<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ColumnChart from '@/components/charts/ColumnChart.vue';
import StackedBar from '@/components/charts/StackedBar.vue';
import WaterfallChart from '@/components/charts/WaterfallChart.vue';
import Heading from '@/components/Heading.vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { index as apAgingIndex } from '@/routes/ap-aging';
import { index as arAgingIndex } from '@/routes/ar-aging';
import { index as financeDashboardIndex } from '@/routes/finance-dashboard';
import {
    balanceSheet,
    cashBankPosition,
    inventoryValuation,
    productProfitability,
    profitLoss,
} from '@/routes/reports';
import type { AgingSummary, FinanceDashboardSummary } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    summary: FinanceDashboardSummary;
    periods: { id: number; name: string }[];
    filters: { fiscal_period_id: number | null; from: string | null; to: string | null };
    currency: string;
    trend: { month: string; from: string; to: string; revenue: string; costs: string; net_profit: string }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Finance dashboard', href: financeDashboardIndex() }] } });

const { t } = useT();

const periodId = ref(props.filters.fiscal_period_id ? String(props.filters.fiscal_period_id) : '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

watch([periodId, from, to], () => {
    router.get(
        financeDashboardIndex().url,
        {
            fiscal_period_id: periodId.value || undefined,
            from: periodId.value ? undefined : from.value || undefined,
            to: periodId.value ? undefined : to.value || undefined,
        },
        { preserveState: false, replace: true },
    );
});

/** The period window, passed through to each drill-down so the numbers match. */
const windowQuery = computed(() => ({
    fiscal_period_id: props.filters.fiscal_period_id ?? undefined,
    from: props.filters.fiscal_period_id ? undefined : (props.summary.period.from ?? undefined),
    to: props.filters.fiscal_period_id ? undefined : (props.summary.period.to ?? undefined),
}));

/** The profit bridge: revenue, less each cost, down to net profit. */
const bridge = computed(() => {
    const p = props.summary.performance;

    return [
        { key: 'revenue', label: t('Revenue'), value: Number(p.revenue), display: money(p.revenue), total: true },
        { key: 'cost', label: t('Cost of sales'), value: Number(p.cost_of_sales), display: money(p.cost_of_sales), total: false },
        { key: 'gross', label: t('Gross profit'), value: Number(p.gross_profit), display: money(p.gross_profit), total: true },
        { key: 'opex', label: t('Operating expenses'), value: Number(p.operating_expenses), display: money(p.operating_expenses), total: false },
        { key: 'net', label: t('Net profit'), value: Number(p.net_profit), display: money(p.net_profit), total: true },
    ];
});

const locale = computed(() => String(usePage().props.locale ?? 'en'));

/** "2026-09" as a short month name in the screen's language. */
function monthLabel(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(year, number - 1, 1).toLocaleString(locale.value === 'si' ? 'si-LK' : 'en-GB', { month: 'short' });
}

const trendChart = computed(() => ({
    categories: props.trend.map((m) => monthLabel(m.month)),
    series: [
        { name: t('Revenue'), colour: 'var(--viz-1)', values: props.trend.map((m) => ({ value: Number(m.revenue), display: money(m.revenue) })) },
        { name: t('Costs'), colour: 'var(--viz-2)', values: props.trend.map((m) => ({ value: Number(m.costs), display: money(m.costs) })) },
    ],
    extra: [{ label: t('Net profit'), values: props.trend.map((m) => money(m.net_profit)) }],
}));

const asOf = computed(() => props.summary.period.to ?? undefined);

const performanceCards = computed(() => [
    { label: t('Revenue'), value: props.summary.performance.revenue },
    { label: t('Cost of sales'), value: props.summary.performance.cost_of_sales },
    {
        label: t('Gross profit'),
        value: props.summary.performance.gross_profit,
        note: t(':percent% margin', { percent: props.summary.performance.gross_margin_percent }),
    },
    { label: t('Operating expenses'), value: props.summary.performance.operating_expenses },
    {
        label: Number(props.summary.performance.net_profit) < 0 ? t('Net loss') : t('Net profit'),
        value: props.summary.performance.net_profit,
        emphasis: true,
    },
]);

const positionCards = computed(() => [
    {
        label: t('Cash + bank'),
        value: props.summary.position.cash_and_bank,
        note: t('Cash :cash · Bank :bank', { cash: money(props.summary.position.cash_total), bank: money(props.summary.position.bank_total) }),
        href: cashBankPosition({ query: { as_of: asOf.value } }),
    },
    {
        label: t('Accounts receivable'),
        value: props.summary.position.receivables,
        note: t(':amount overdue', { amount: money(props.summary.receivables.overdue) }),
        href: arAgingIndex({ query: { as_of: asOf.value } }),
    },
    {
        label: t('Accounts payable'),
        value: props.summary.position.payables,
        note: t(':amount overdue', { amount: money(props.summary.payables.overdue) }),
        href: apAgingIndex({ query: { as_of: asOf.value } }),
    },
    {
        label: t('Inventory value'),
        value: props.summary.position.inventory_value,
        note: t('Current, from the inventory ledger'),
        href: inventoryValuation(),
    },
]);

const agingBuckets: { key: keyof AgingSummary; label: string }[] = [
    { key: 'current', label: t('Current') },
    { key: 'days_1_30', label: '1–30' },
    { key: 'days_31_60', label: '31–60' },
    { key: 'days_61_90', label: '61–90' },
    { key: 'days_90_plus', label: '90+' },
];

const agingTables = computed(() => [
    { title: t('Receivables aging'), aging: props.summary.receivables, href: arAgingIndex({ query: { as_of: asOf.value } }) },
    { title: t('Payables aging'), aging: props.summary.payables, href: apAgingIndex({ query: { as_of: asOf.value } }) },
]);

const counterpartyLists = computed(() => [
    {
        title: t('Top customers by receivable'),
        rows: props.summary.top_customers,
        empty: t('Nothing owed by customers.'),
        href: arAgingIndex({ query: { as_of: asOf.value } }),
    },
    {
        title: t('Top suppliers by payable'),
        rows: props.summary.top_suppliers,
        empty: t('Nothing owed to suppliers.'),
        href: apAgingIndex({ query: { as_of: asOf.value } }),
    },
]);

</script>

<template>
    <Head :title="t('Finance dashboard')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Finance dashboard"
                :description="
                    summary.period.name
                        ? t('Performance for :period; position as at its end.', { period: summary.period.name })
                        : t('Performance from :from to :to; position as at :to.', { from: summary.period.from ?? t('the beginning'), to: summary.period.to ?? t('today') })
                "
            />
            <PrintButton />
        </div>

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="period">{{ t('Fiscal period') }}</Label>
                <SearchableSelect
                    id="period"
                    v-model="periodId"
                    :options="[{ value: '', label: t('None — use dates') }, ...(periods).map((p) => ({ value: String(p.id), label: `${(p.name) ?? ''}` }))]"
                />
            </div>
            <div class="grid gap-2">
                <Label for="from">{{ t('From') }}</Label>
                <Input id="from" v-model="from" type="date" :disabled="periodId !== ''" />
            </div>
            <div class="grid gap-2">
                <Label for="to">{{ t('To') }}</Label>
                <Input id="to" v-model="to" type="date" :disabled="periodId !== ''" />
            </div>
        </section>

        <div>
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="text-sm font-medium">{{ t('Performance (:currency)', { currency }) }}</h2>
                <Link :href="profitLoss({ query: windowQuery })" class="text-muted-foreground text-xs hover:underline">
                    {{ t('Open profit & loss →') }}
                </Link>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div
                    v-for="card in performanceCards"
                    :key="card.label"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <p class="text-muted-foreground text-xs font-medium">{{ card.label }}</p>
                    <p
                        class="mt-1 font-mono text-lg"
                        :class="[card.emphasis ? 'font-medium' : '', Number(card.value) < 0 ? 'text-destructive' : '']"
                    >
                        {{ money(card.value) }}
                    </p>
                    <p v-if="card.note" class="text-muted-foreground mt-1 text-xs">{{ card.note }}</p>
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <h3 class="text-sm font-medium">{{ t('From revenue to net profit') }}</h3>
                <p class="text-muted-foreground mb-3 text-xs">{{ t('What each cost takes out of revenue in this window.') }}</p>
                <WaterfallChart
                    :steps="bridge"
                    :total-label="t('Total')"
                    :decrease-label="t('Cost')"
                    :chart-label="t('From revenue to net profit')"
                />
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <h3 class="text-sm font-medium">{{ t('Revenue and costs, last 6 months') }}</h3>
                <p class="text-muted-foreground mb-3 text-xs">{{ t('Costs are cost of sales plus operating expenses. Point at a month for its net profit.') }}</p>
                <ColumnChart
                    :categories="trendChart.categories"
                    :series="trendChart.series"
                    :extra="trendChart.extra"
                    :chart-label="t('Revenue and costs, last 6 months')"
                />
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="text-sm font-medium">{{ t('Position (:currency)', { currency }) }}</h2>
                <Link :href="balanceSheet({ query: { as_of: asOf } })" class="text-muted-foreground text-xs hover:underline">
                    {{ t('Open balance sheet →') }}
                </Link>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="card in positionCards"
                    :key="card.label"
                    :href="card.href"
                    class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 rounded-xl border p-4"
                >
                    <p class="text-muted-foreground text-xs font-medium">{{ card.label }}</p>
                    <p class="mt-1 font-mono text-lg" :class="Number(card.value) < 0 ? 'text-destructive' : ''">
                        {{ money(card.value) }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">{{ card.note }}</p>
                </Link>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div
                v-for="table in agingTables"
                :key="table.title"
                class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
            >
                <div class="border-sidebar-border/70 dark:border-sidebar-border flex items-baseline justify-between border-b px-4 py-3">
                    <h3 class="text-sm font-medium">{{ table.title }}</h3>
                    <Link :href="table.href" class="text-muted-foreground text-xs hover:underline">{{ t('Detail →') }}</Link>
                </div>
                <StackedBar
                    v-if="Number(table.aging.total) > 0"
                    class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3"
                    :segments="agingBuckets.map((bucket) => ({ key: bucket.key, label: bucket.label, value: Number(table.aging[bucket.key]), display: money(table.aging[bucket.key]) }))"
                    :chart-label="table.title"
                />
                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="bucket in agingBuckets"
                            :key="bucket.key"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t first:border-t-0"
                        >
                            <td class="px-4 py-2">{{ bucket.label }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(table.aging[bucket.key]) }}</td>
                        </tr>
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-2 font-medium">{{ t('Overdue') }}</td>
                            <td class="px-4 py-2 text-right font-mono font-medium">{{ money(table.aging.overdue) }}</td>
                        </tr>
                        <tr class="bg-muted/50 border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-3 font-medium">{{ t('Total') }}</td>
                            <td class="px-4 py-3 text-right font-mono font-medium">{{ money(table.aging.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <div class="border-sidebar-border/70 dark:border-sidebar-border flex items-baseline justify-between border-b px-4 py-3">
                    <h3 class="text-sm font-medium">{{ t('Top products by sales value') }}</h3>
                    <Link
                        :href="productProfitability({ query: { from: summary.period.from ?? undefined, to: summary.period.to ?? undefined } })"
                        class="text-muted-foreground text-xs hover:underline"
                    >
                        {{ t('Detail →') }}
                    </Link>
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="p in summary.top_products"
                            :key="p.product_id"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t first:border-t-0"
                        >
                            <td class="px-4 py-2">
                                <span class="font-mono text-xs">{{ p.product_code }}</span>
                                <span class="text-muted-foreground block text-xs">{{ t(':percent% margin', { percent: p.gross_margin_percent }) }}</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(p.sales_value) }}</td>
                        </tr>
                        <tr v-if="summary.top_products.length === 0">
                            <td colspan="2" class="text-muted-foreground px-4 py-6 text-center">{{ t('No posted deliveries.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-for="list in counterpartyLists"
                :key="list.title"
                class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
            >
                <div class="border-sidebar-border/70 dark:border-sidebar-border flex items-baseline justify-between border-b px-4 py-3">
                    <h3 class="text-sm font-medium">{{ list.title }}</h3>
                    <Link :href="list.href" class="text-muted-foreground text-xs hover:underline">{{ t('Detail →') }}</Link>
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="row in list.rows"
                            :key="row.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t first:border-t-0"
                        >
                            <td class="px-4 py-2">
                                <span class="font-mono text-xs">{{ row.code }}</span>
                                <span class="text-muted-foreground block text-xs">{{ row.name }}</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(row.outstanding) }}</td>
                        </tr>
                        <tr v-if="list.rows.length === 0">
                            <td colspan="2" class="text-muted-foreground px-4 py-6 text-center">{{ list.empty }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs print:hidden">
            {{ t("Performance comes from the general ledger for the chosen window — recognised revenue, not deliveries. Cash, receivables, payables and aging are as at the window's end. Inventory value is always the current position from the inventory ledger, because historical stock valuation is not supported. Top products are operational figures from posted deliveries. Every number is fetched from the report that owns it, so the dashboard can never disagree with the detail behind it.") }}
        </p>
    </div>
</template>
