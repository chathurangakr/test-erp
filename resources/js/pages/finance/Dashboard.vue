<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

const props = defineProps<{
    summary: FinanceDashboardSummary;
    periods: { id: number; name: string }[];
    filters: { fiscal_period_id: number | null; from: string | null; to: string | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Finance dashboard', href: financeDashboardIndex() }] } });

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

const asOf = computed(() => props.summary.period.to ?? undefined);

const performanceCards = computed(() => [
    { label: 'Revenue', value: props.summary.performance.revenue },
    { label: 'Cost of sales', value: props.summary.performance.cost_of_sales },
    {
        label: 'Gross profit',
        value: props.summary.performance.gross_profit,
        note: `${props.summary.performance.gross_margin_percent}% margin`,
    },
    { label: 'Operating expenses', value: props.summary.performance.operating_expenses },
    {
        label: Number(props.summary.performance.net_profit) < 0 ? 'Net loss' : 'Net profit',
        value: props.summary.performance.net_profit,
        emphasis: true,
    },
]);

const positionCards = computed(() => [
    {
        label: 'Cash + bank',
        value: props.summary.position.cash_and_bank,
        note: `Cash ${props.summary.position.cash_total} · Bank ${props.summary.position.bank_total}`,
        href: cashBankPosition({ query: { as_of: asOf.value } }),
    },
    {
        label: 'Accounts receivable',
        value: props.summary.position.receivables,
        note: `${props.summary.receivables.overdue} overdue`,
        href: arAgingIndex({ query: { as_of: asOf.value } }),
    },
    {
        label: 'Accounts payable',
        value: props.summary.position.payables,
        note: `${props.summary.payables.overdue} overdue`,
        href: apAgingIndex({ query: { as_of: asOf.value } }),
    },
    {
        label: 'Inventory value',
        value: props.summary.position.inventory_value,
        note: 'Current, from the inventory ledger',
        href: inventoryValuation(),
    },
]);

const agingBuckets: { key: keyof AgingSummary; label: string }[] = [
    { key: 'current', label: 'Current' },
    { key: 'days_1_30', label: '1–30' },
    { key: 'days_31_60', label: '31–60' },
    { key: 'days_61_90', label: '61–90' },
    { key: 'days_90_plus', label: '90+' },
];

const agingTables = computed(() => [
    { title: 'Receivables aging', aging: props.summary.receivables, href: arAgingIndex({ query: { as_of: asOf.value } }) },
    { title: 'Payables aging', aging: props.summary.payables, href: apAgingIndex({ query: { as_of: asOf.value } }) },
]);

const counterpartyLists = computed(() => [
    {
        title: 'Top customers by receivable',
        rows: props.summary.top_customers,
        empty: 'Nothing owed by customers.',
        href: arAgingIndex({ query: { as_of: asOf.value } }),
    },
    {
        title: 'Top suppliers by payable',
        rows: props.summary.top_suppliers,
        empty: 'Nothing owed to suppliers.',
        href: apAgingIndex({ query: { as_of: asOf.value } }),
    },
]);

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Finance dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Finance dashboard"
            :description="
                summary.period.name
                    ? `Performance for ${summary.period.name}; position as at its end.`
                    : `Performance from ${summary.period.from ?? 'the beginning'} to ${summary.period.to ?? 'today'}; position as at ${summary.period.to ?? 'today'}.`
            "
        />

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="period">Fiscal period</Label>
                <select id="period" v-model="periodId" :class="selectClass">
                    <option value="">None — use dates</option>
                    <option v-for="p in periods" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="from">From</Label>
                <Input id="from" v-model="from" type="date" :disabled="periodId !== ''" />
            </div>
            <div class="grid gap-2">
                <Label for="to">To</Label>
                <Input id="to" v-model="to" type="date" :disabled="periodId !== ''" />
            </div>
        </section>

        <div>
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="text-sm font-medium">Performance ({{ currency }})</h2>
                <Link :href="profitLoss({ query: windowQuery })" class="text-muted-foreground text-xs hover:underline">
                    Open profit &amp; loss →
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
                        {{ card.value }}
                    </p>
                    <p v-if="card.note" class="text-muted-foreground mt-1 text-xs">{{ card.note }}</p>
                </div>
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="text-sm font-medium">Position ({{ currency }})</h2>
                <Link :href="balanceSheet({ query: { as_of: asOf } })" class="text-muted-foreground text-xs hover:underline">
                    Open balance sheet →
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
                        {{ card.value }}
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
                    <Link :href="table.href" class="text-muted-foreground text-xs hover:underline">Detail →</Link>
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="bucket in agingBuckets"
                            :key="bucket.key"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t first:border-t-0"
                        >
                            <td class="px-4 py-2">{{ bucket.label }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ table.aging[bucket.key] }}</td>
                        </tr>
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-2 font-medium">Overdue</td>
                            <td class="px-4 py-2 text-right font-mono font-medium">{{ table.aging.overdue }}</td>
                        </tr>
                        <tr class="bg-muted/50 border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-3 font-medium">Total</td>
                            <td class="px-4 py-3 text-right font-mono font-medium">{{ table.aging.total }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <div class="border-sidebar-border/70 dark:border-sidebar-border flex items-baseline justify-between border-b px-4 py-3">
                    <h3 class="text-sm font-medium">Top products by sales value</h3>
                    <Link
                        :href="productProfitability({ query: { from: summary.period.from ?? undefined, to: summary.period.to ?? undefined } })"
                        class="text-muted-foreground text-xs hover:underline"
                    >
                        Detail →
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
                                <span class="text-muted-foreground block text-xs">{{ p.gross_margin_percent }}% margin</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">{{ p.sales_value }}</td>
                        </tr>
                        <tr v-if="summary.top_products.length === 0">
                            <td colspan="2" class="text-muted-foreground px-4 py-6 text-center">No posted deliveries.</td>
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
                    <Link :href="list.href" class="text-muted-foreground text-xs hover:underline">Detail →</Link>
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
                            <td class="px-4 py-2 text-right font-mono">{{ row.outstanding }}</td>
                        </tr>
                        <tr v-if="list.rows.length === 0">
                            <td colspan="2" class="text-muted-foreground px-4 py-6 text-center">{{ list.empty }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Performance comes from the general ledger for the chosen window — recognised revenue,
            not deliveries. Cash, receivables, payables and aging are as at the window's end.
            Inventory value is always the current position from the inventory ledger, because
            historical stock valuation is not supported. Top products are operational figures
            from posted deliveries. Every number is fetched from the report that owns it, so the
            dashboard can never disagree with the detail behind it.
        </p>
    </div>
</template>
