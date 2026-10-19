<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BarList from '@/components/charts/BarList.vue';
import Heading from '@/components/Heading.vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { index as cashBankBookIndex } from '@/routes/cash-bank-book';
import { cashBankPosition } from '@/routes/reports';
import type { CashBankAccountRow, CashBankSummary } from '@/types';
import { money } from '@/lib/money';

const props = defineProps<{
    summary: CashBankSummary;
    filters: { as_of: string | null; show_inactive: boolean };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Cash / bank position', href: cashBankPosition() }] } });

const { t } = useT();

const asOf = ref(props.filters.as_of ?? '');
const showInactive = ref(props.filters.show_inactive);

watch([asOf, showInactive], () => {
    router.get(
        cashBankPosition().url,
        { as_of: asOf.value || undefined, show_inactive: showInactive.value ? 1 : undefined },
        { preserveState: false, replace: true },
    );
});

const cashRows = computed(() => props.summary.rows.filter((row) => row.kind === 'CASH'));
const bankRows = computed(() => props.summary.rows.filter((row) => row.kind === 'BANK'));

const groups = computed(() => [
    { title: 'Cash accounts', rows: cashRows.value, total: props.summary.cash_total, totalLabel: 'Total cash' },
    { title: 'Bank accounts', rows: bankRows.value, total: props.summary.bank_total, totalLabel: 'Total bank' },
]);

function bookUrl(row: CashBankAccountRow): string {
    const params = new URLSearchParams({ cash_bank_account_id: String(row.id) });

    if (props.filters.as_of) params.set('to', props.filters.as_of);

    return `${cashBankBookIndex().url}?${params.toString()}`;
}

/** Each account's balance, largest first (an overdrawn account draws to the left). */
const balances = computed(() =>
    [...props.summary.rows]
        .sort((a, b) => Number(b.balance) - Number(a.balance))
        .map((row) => ({ key: row.id, label: row.display_name, sublabel: row.kind_label, value: Number(row.balance), display: money(row.balance) })),
);
</script>

<template>
    <Head :title="t('Cash / bank position')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Cash / bank position"
                :description="filters.as_of ? t('Money held, as at :date.', { date: filters.as_of }) : t('Money held, as at today.')"
            />
            <PrintButton />
        </div>

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="as_of">{{ t('As at') }}</Label>
                <Input id="as_of" v-model="asOf" type="date" />
            </div>
            <label class="flex items-end gap-2 pb-2 text-sm">
                <input v-model="showInactive" type="checkbox" class="size-4" />
                {{ t('Include inactive accounts') }}
            </label>
        </section>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total cash (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(summary.cash_total) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total bank (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(summary.bank_total) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Cash + bank (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(summary.combined_total) }}</p>
            </div>
        </div>

        <div v-if="balances.length > 0" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
            <h3 class="mb-3 text-sm font-medium">{{ t('Balance by account (:currency)', { currency }) }}</h3>
            <BarList :rows="balances" :chart-label="t('Balance by account (:currency)', { currency })" />
        </div>

        <div
            v-for="group in groups"
            :key="group.title"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">{{ t(group.title) }}</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('GL code') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Kind') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in group.rows"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <Link :href="bookUrl(row)" class="font-medium hover:underline">
                                {{ row.display_name }}
                            </Link>
                            <span v-if="row.bank_name" class="text-muted-foreground block text-xs">
                                {{ row.bank_name }}<template v-if="row.account_number"> · {{ row.account_number }}</template>
                            </span>
                            <Badge v-if="!row.is_active" variant="secondary" class="mt-1">{{ t('Inactive') }}</Badge>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ row.account_code }}</td>
                        <td class="px-4 py-3">{{ row.kind_label }}</td>
                        <td
                            class="px-4 py-3 text-right font-mono"
                            :class="Number(row.balance) < 0 ? 'text-destructive' : ''"
                        >
                            {{ money(row.balance) }}
                        </td>
                    </tr>
                    <tr v-if="group.rows.length === 0">
                        <td colspan="4" class="text-muted-foreground px-4 py-6 text-center">
                            {{ t('No accounts of this kind are configured.') }}
                        </td>
                    </tr>
                    <tr class="bg-muted/50 border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td colspan="3" class="px-4 py-3 font-medium">{{ t(group.totalLabel) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(group.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs print:hidden">
            {{ t('Every balance is derived from posted journal lines on each visit — nothing is stored — using the same calculation as the cash / bank book, so the two always agree. Click an account to open its book. A negative balance is shown as it stands: no overdraft policy has been decided yet.') }}
        </p>
    </div>
</template>
