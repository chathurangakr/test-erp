<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import StatementSection from '@/components/reports/StatementSection.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { index as generalLedgerIndex } from '@/routes/general-ledger';
import { balanceSheet, profitLoss } from '@/routes/reports';
import type { BalanceSheetStatement, ProfitLossStatement } from '@/types';

const props = defineProps<{
    statement: BalanceSheetStatement;
    earnings: ProfitLossStatement;
    retainedEarnings: { account_id: number; code: string; name: string; balance: string } | null;
    filters: { as_of: string | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Balance sheet', href: balanceSheet() }] } });

const { t } = useT();

const asOf = ref(props.filters.as_of ?? '');

watch(asOf, () => {
    router.get(balanceSheet().url, { as_of: asOf.value || undefined }, { preserveState: false, replace: true });
});
</script>

<template>
    <Head :title="t('Balance sheet')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Balance sheet" :description="t('As at :date', { date: statement.as_of })" />

        <div class="grid gap-4 sm:max-w-xs">
            <div class="grid gap-2">
                <Label for="as_of">{{ t('As at') }}</Label>
                <Input id="as_of" v-model="asOf" type="date" />
            </div>
        </div>

        <StatementSection
            title="Assets"
            :rows="statement.assets.rows"
            :total="statement.assets.total"
            :total-label="t('Total assets (:currency)', { currency })"
            :to="statement.as_of"
        />

        <StatementSection
            title="Liabilities"
            :rows="statement.liabilities.rows"
            :total="statement.liabilities.total"
            total-label="Total liabilities"
            :to="statement.as_of"
        />

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">{{ t('Equity') }}</h3>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    <tr
                        v-for="row in statement.equity.rows"
                        :key="row.account_id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-2">
                            <Link
                                :href="`${generalLedgerIndex().url}?account_id=${row.account_id}&to=${statement.as_of}`"
                                class="hover:underline"
                            >
                                <span class="text-muted-foreground font-mono text-xs">{{ row.code }}</span>
                                {{ row.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ row.amount }}</td>
                    </tr>

                    <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-2">
                            <Link :href="`${profitLoss().url}?to=${statement.as_of}`" class="hover:underline">
                                {{ t('Current earnings') }}
                            </Link>
                            <span class="text-muted-foreground block text-xs">
                                {{ t('Computed from unclosed profit — not posted to any account') }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ statement.current_earnings }}</td>
                    </tr>

                    <tr class="bg-muted/50 border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3 font-medium">{{ t('Total equity') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ statement.total_equity }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Liabilities + equity (:currency)', { currency }) }}</p>
                    <p class="mt-1 font-mono text-xl font-medium">{{ statement.liabilities_and_equity }}</p>
                </div>
                <div>
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Total assets') }}</p>
                    <p class="mt-1 font-mono text-xl font-medium">{{ statement.assets.total }}</p>
                </div>
                <Badge :variant="statement.balanced ? 'default' : 'destructive'">
                    {{ statement.balanced ? t('Balanced') : t('Out by :difference', { difference: statement.difference }) }}
                </Badge>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <h3 class="text-sm font-medium">{{ t('Current earnings and retained earnings') }}</h3>
            <dl class="mt-4 grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Current earnings (computed)') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ statement.current_earnings }}</dd>
                    <dd class="text-muted-foreground mt-1 text-xs">
                        {{
                            t('Revenue :revenue less cost of sales :cost less expenses :expenses.', {
                                revenue: earnings.revenue.total,
                                cost: earnings.cost_of_sales.total,
                                expenses: earnings.operating_expenses.total,
                            })
                        }}
                    </dd>
                </div>
                <div v-if="retainedEarnings">
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ retainedEarnings.code }} {{ retainedEarnings.name }} {{ t('(actual ledger balance)') }}
                    </dt>
                    <dd class="mt-1 font-mono text-sm">{{ retainedEarnings.balance }}</dd>
                    <dd class="text-muted-foreground mt-1 text-xs">
                        {{ t('Only what has genuinely been posted to it.') }}
                    </dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Assets equal liabilities plus equity plus current earnings. That identity is not a coincidence: because every posted journal balances, it follows arithmetically, so a difference would mean the trial balance itself is broken.') }}
            <strong>{{ t('Current earnings is computed, never posted.') }}</strong>
            {{ t('Conventionally a year-end closing entry moves profit into retained earnings, but year-end closing is not implemented and this report will not invent it — retained earnings shows only what was genuinely posted to it. Current earnings accumulates from the beginning of recorded history, which is correct while no close has ever happened; once closing exists it must start from the last close instead.') }}
        </p>
    </div>
</template>
