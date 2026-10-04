<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { index as accountsIndex } from '@/routes/accounts';
import { index } from '@/routes/trial-balance';
import type { TrialBalanceReport } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    report: TrialBalanceReport;
    periods: { id: number; name: string }[];
    filters: { fiscal_period_id: number | null; as_of: string | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Trial balance', href: index() }] } });

const periodId = ref(props.filters.fiscal_period_id ? String(props.filters.fiscal_period_id) : '');
const asOf = ref(props.filters.as_of ?? '');

function applyFilters(): void {
    router.get(
        index().url,
        {
            fiscal_period_id: periodId.value || undefined,
            as_of: asOf.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([periodId, asOf], applyFilters);

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const { t } = useT();
</script>

<template>
    <Head :title="t('Trial balance')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Trial balance"
            :description="t('Every account with a balance, in :currency. The two totals must be identical.', { currency })"
        />

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="grid gap-1.5">
                <label for="f-asof" class="text-sm font-medium">{{ t('As of date') }}</label>
                <input id="f-asof" v-model="asOf" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="f-period" class="text-sm font-medium">{{ t('Fiscal period') }}</label>
                <select id="f-period" v-model="periodId" :class="controlClass">
                    <option value="">{{ t('All periods') }}</option>
                    <option v-for="p in periods" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                </select>
            </div>
        </div>

        <div
            v-if="!report.balanced"
            class="border-destructive text-destructive rounded-xl border p-4 text-sm"
        >
            <strong>{{ t('The trial balance does not balance.') }}</strong>
            {{ t('Debits total :debit against credits of :credit. Every posted journal balances individually, so a difference here means something is wrong that needs investigating rather than presenting.', { debit: report.debit_total, credit: report.credit_total }) }}
        </div>

        <div
            v-if="report.rows.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('Nothing posted yet') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ t('The books are empty, so every total is zero — which balances.') }}
            </p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Code') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Account') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Type') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Debit') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in report.rows"
                        :key="row.account_id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="`${accountsIndex().url}/${row.account_id}`" class="hover:underline">
                                {{ row.code }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">{{ row.name }}</td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">{{ row.type_label }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ Number(row.debit) > 0 ? money(row.debit) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ Number(row.credit) > 0 ? money(row.credit) : '—' }}
                        </td>
                    </tr>
                </tbody>
                <tfoot class="bg-muted/30">
                    <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3 font-medium" colspan="3">{{ t('Totals') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.debit_total) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.credit_total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Balances are derived from posted journal lines on request — nothing here is stored. An account whose debits and credits cancel out is omitted, because it has no balance to report.') }}
        </p>
    </div>
</template>
