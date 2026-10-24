<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import WaterfallChart from '@/components/charts/WaterfallChart.vue';
import Heading from '@/components/Heading.vue';
import ChartsButton from '@/components/charts/ChartsButton.vue';
import ExportButtons from '@/components/print/ExportButtons.vue';
import PrintButton from '@/components/print/PrintButton.vue';
import StatementSection from '@/components/reports/StatementSection.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { useChartsVisible } from '@/composables/useChartsVisible';
import { useT } from '@/lib/i18n';
import { profitLoss } from '@/routes/reports';
import type { ProfitLossStatement } from '@/types';
import { money } from '@/lib/money';
import { exportMethod as exportReport } from '@/routes/reports/profit-loss';

const props = defineProps<{
    statement: ProfitLossStatement;
    periods: { id: number; name: string }[];
    filters: { fiscal_period_id: number | null; from: string | null; to: string | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Profit & loss', href: profitLoss() }] } });

const { t } = useT();
const { chartsVisible } = useChartsVisible();

const periodId = ref(props.filters.fiscal_period_id ? String(props.filters.fiscal_period_id) : '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

watch([periodId, from, to], () => {
    router.get(
        profitLoss().url,
        {
            fiscal_period_id: periodId.value || undefined,
            from: periodId.value ? undefined : from.value || undefined,
            to: periodId.value ? undefined : to.value || undefined,
        },
        { preserveState: false, replace: true },
    );
});


/** The profit bridge: revenue, less each cost, down to net profit. */
const bridge = computed(() => [
    { key: 'revenue', label: t('Revenue'), value: Number(props.statement.revenue.total), display: money(props.statement.revenue.total), total: true },
    { key: 'cost', label: t('Cost of sales'), value: Number(props.statement.cost_of_sales.total), display: money(props.statement.cost_of_sales.total), total: false },
    { key: 'gross', label: t('Gross profit'), value: Number(props.statement.gross_profit), display: money(props.statement.gross_profit), total: true },
    { key: 'opex', label: t('Operating expenses'), value: Number(props.statement.operating_expenses.total), display: money(props.statement.operating_expenses.total), total: false },
    { key: 'net', label: t('Net profit'), value: Number(props.statement.net_profit), display: money(props.statement.net_profit), total: true },
]);
</script>

<template>
    <Head :title="t('Profit & loss')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Profit & loss"
                :description="
                    statement.period
                        ? statement.period
                        : t(':from to :to', { from: statement.from ?? t('the beginning'), to: statement.to ?? t('today') })
                "
            />
            <div class="flex gap-2">
                <ChartsButton />
                <ExportButtons :url="exportReport.url" :query="filters" />
                <PrintButton />
            </div>
        </div>

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="period">{{ t('Fiscal period') }}</Label>
                <SearchableSelect
                    id="period"
                    v-model="periodId"
                    :options="[{ value: '', label: t('All — use dates') }, ...(periods).map((p) => ({ value: String(p.id), label: `${(p.name) ?? ''}` }))]"
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

        <div v-if="chartsVisible" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
            <h3 class="mb-3 text-sm font-medium">{{ t('From revenue to net profit') }}</h3>
            <WaterfallChart
                :steps="bridge"
                :total-label="t('Total')"
                :decrease-label="t('Cost')"
                :chart-label="t('From revenue to net profit')"
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <StatementSection
                title="Revenue"
                :rows="statement.revenue.rows"
                :total="statement.revenue.total"
                :total-label="t('Total revenue (:currency)', { currency })"
                :from="statement.from"
                :to="statement.to"
                empty-message="No revenue recognised in this period."
            />
            <StatementSection
                title="Cost of sales"
                :rows="statement.cost_of_sales.rows"
                :total="statement.cost_of_sales.total"
                total-label="Total cost of sales"
                :from="statement.from"
                :to="statement.to"
            />
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-baseline justify-between gap-3 rounded-xl border p-4">
            <div>
                <p class="text-muted-foreground text-xs font-medium">{{ t('Gross profit') }}</p>
                <p class="mt-1 font-mono text-xl font-medium">{{ money(statement.gross_profit) }}</p>
            </div>
            <p class="text-muted-foreground text-sm">
                {{ t('Margin') }} <span class="font-mono">{{ statement.gross_margin_percent }}%</span>
            </p>
        </div>

        <StatementSection
            title="Operating expenses"
            :rows="statement.operating_expenses.rows"
            :total="statement.operating_expenses.total"
            total-label="Total operating expenses"
            :from="statement.from"
            :to="statement.to"
        />

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <p class="text-muted-foreground text-xs font-medium">
                {{ Number(statement.net_profit) < 0 ? t('Net loss') : t('Net profit') }} ({{ currency }})
            </p>
            <p
                class="mt-1 font-mono text-2xl font-medium"
                :class="Number(statement.net_profit) < 0 ? 'text-destructive' : ''"
            >
                {{ money(statement.net_profit) }}
            </p>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs print:hidden">
            {{ t('Revenue less cost of sales is gross profit; less operating expenses is net profit. Every figure comes from posted journals, so this shows revenue that has actually been recognised — a delivery that has not been invoiced appears here as cost with no revenue beside it, which is why the unbilled-deliveries report exists. Accounts are grouped by the chart\'s own hierarchy and classified by account type, never by account number. Click any account to open its ledger.') }}
        </p>
    </div>
</template>
