<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/cash-bank-book';
import { index as journalsIndex } from '@/routes/journals';
import type { CashBankBook } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    accounts: { id: number; display_name: string; account_code: string | null; kind_label: string }[];
    selected: {
        id: number;
        display_name: string;
        account_code: string | null;
        account_name: string | null;
        kind_label: string;
    } | null;
    report: CashBankBook | null;
    ledger: { balance: string; reconciles: boolean } | null;
    filters: { cash_bank_account_id: number | null; from: string | null; to: string | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Cash / bank book', href: index() }] } });

const { t } = useT();

const accountId = ref(props.filters.cash_bank_account_id ? String(props.filters.cash_bank_account_id) : '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

watch([accountId, from, to], () => {
    router.get(
        index().url,
        {
            cash_bank_account_id: accountId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: false, replace: true },
    );
});

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head :title="t('Cash / bank book')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Cash / bank book"
            description="Every posted movement through one account, whatever recorded it."
        />

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="account">{{ t('Account') }}</Label>
                <select id="account" v-model="accountId" :class="selectClass">
                    <option v-for="a in accounts" :key="a.id" :value="String(a.id)">
                        {{ a.account_code }} — {{ a.display_name }} ({{ a.kind_label }})
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="from">{{ t('From') }}</Label>
                <Input id="from" v-model="from" type="date" />
            </div>
            <div class="grid gap-2">
                <Label for="to">{{ t('To') }}</Label>
                <Input id="to" v-model="to" type="date" />
            </div>
        </section>

        <div
            v-if="selected === null"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-8 text-center text-sm"
        >
            {{ t('No cash or bank account is configured yet.') }}
        </div>

        <template v-else-if="report">
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Opening (:currency)', { currency }) }}</p>
                    <p class="mt-1 font-mono text-lg">{{ report.opening_balance }}</p>
                </div>
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Money in') }}</p>
                    <p class="mt-1 font-mono text-lg">{{ report.money_in_total }}</p>
                </div>
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Money out') }}</p>
                    <p class="mt-1 font-mono text-lg">{{ report.money_out_total }}</p>
                </div>
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Closing') }}</p>
                    <p class="mt-1 font-mono text-lg font-medium">{{ report.closing_balance }}</p>
                </div>
            </div>

            <div
                v-if="ledger"
                class="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm"
            >
                <span>
                    {{ t('General ledger balance for') }}
                    <span class="font-mono text-xs">{{ selected.account_code }}</span>
                    <span class="ml-2 font-mono font-medium">{{ ledger.balance }}</span>
                </span>
                <Badge :variant="ledger.reconciles ? 'default' : 'destructive'">
                    {{ ledger.reconciles ? t('Reconciles exactly') : t('Does not reconcile') }}
                </Badge>
            </div>

            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Journal') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Source') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Description') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Money in') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Money out') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in report.rows"
                            :key="row.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">{{ row.date }}</td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="`${journalsIndex().url}/${row.journal_entry_id}`"
                                    class="font-mono text-xs hover:underline"
                                >
                                    {{ row.journal_number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                {{ row.source_label }}
                                <span v-if="row.source_number" class="text-muted-foreground block font-mono text-xs">
                                    {{ row.source_number }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ row.description ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ Number(row.money_in) > 0 ? row.money_in : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ Number(row.money_out) > 0 ? row.money_out : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-medium">{{ row.running_balance }}</td>
                        </tr>
                        <tr v-if="report.rows.length === 0">
                            <td colspan="7" class="text-muted-foreground px-4 py-8 text-center">
                                {{ t('Nothing moved through this account in the selected period.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Every row is a posted journal line touching this account — a supplier payment, a customer receipt, an expense voucher, a fund transfer or a journal written by hand. None of them is treated specially: they appear because each posted a line, which is also why the closing balance always equals the general ledger. Money in is a debit, money out a credit, and the running balance is worked out as this page loads. A reversed document stays visible with its reversal beside it; nothing is ever hidden.') }}
        </p>
    </div>
</template>
