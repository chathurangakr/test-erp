<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { index } from '@/routes/general-ledger';
import { index as journalsIndex } from '@/routes/journals';
import type { AccountOption, GeneralLedgerReport } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    accounts: AccountOption[];
    account: {
        id: number;
        code: string;
        name: string;
        account_type_label: string;
        normal_balance_label: string;
    } | null;
    ledger: GeneralLedgerReport | null;
    filters: { account_id: number | null; from: string | null; to: string | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'General ledger', href: index() }] } });

const accountId = ref(props.filters.account_id ? String(props.filters.account_id) : '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

function applyFilters(): void {
    router.get(
        index().url,
        {
            account_id: accountId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([accountId, from, to], applyFilters);

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const { t } = useT();
</script>

<template>
    <Head :title="t('General ledger')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="General ledger"
            :description="t('Every posted line for one account, with a running balance. Amounts in :currency.', { currency })"
        />

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="grid gap-1.5">
                <label for="f-account" class="text-sm font-medium">{{ t('Account') }}</label>
                <SearchableSelect
                    id="f-account"
                    v-model="accountId"
                    :options="[{ value: '', label: t('Select an account…') }, ...(accounts).map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` }))]"
                />
            </div>
            <div class="grid gap-1.5">
                <label for="f-from" class="text-sm font-medium">{{ t('From') }}</label>
                <input id="f-from" v-model="from" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="f-to" class="text-sm font-medium">{{ t('To') }}</label>
                <input id="f-to" v-model="to" type="date" :class="controlClass" />
            </div>
        </div>

        <div
            v-if="!account"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('Choose an account') }}</p>
            <p class="text-muted-foreground text-sm">{{ t('Its posted lines and running balance appear here.') }}</p>
        </div>

        <template v-else-if="ledger">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
                <h3 class="text-sm font-medium">
                    <span class="font-mono">{{ account.code }}</span> — {{ account.name }}
                </h3>
                <p class="text-muted-foreground mt-1 text-xs">
                    {{ account.account_type_label }} · {{ t(':balance normal', { balance: account.normal_balance_label }) }}
                </p>
            </div>

            <div
                v-if="ledger.rows.length === 0"
                class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-12 text-center"
            >
                <p class="font-medium">{{ t('No postings in this range') }}</p>
            </div>

            <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Journal No') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Description') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Source') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Debit') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Credit') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-t">
                            <td class="px-4 py-3" colspan="6">{{ t('Opening balance') }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(ledger.opening_balance) }}</td>
                        </tr>
                        <tr
                            v-for="row in ledger.rows"
                            :key="row.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-4 py-3">{{ row.journal_date }}</td>
                            <td class="px-4 py-3 font-mono text-xs">
                                <Link :href="`${journalsIndex().url}/${row.journal_entry_id}`" class="hover:underline">
                                    {{ row.journal_number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">{{ row.description }}</td>
                            <td class="text-muted-foreground px-4 py-3 text-xs">{{ row.source }}</td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ Number(row.debit) > 0 ? money(row.debit) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ Number(row.credit) > 0 ? money(row.credit) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.running_balance) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-muted/30">
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-3 font-medium" colspan="4">{{ t('Totals') }}</td>
                            <td class="px-4 py-3 text-right font-mono font-medium">{{ money(ledger.debit_total) }}</td>
                            <td class="px-4 py-3 text-right font-mono font-medium">{{ money(ledger.credit_total) }}</td>
                            <td class="px-4 py-3 text-right font-mono font-medium">{{ money(ledger.closing_balance) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="text-muted-foreground max-w-3xl text-xs">
                {{ t('The running balance is calculated for this view and never stored. Anything before the chosen start date is folded into the opening balance, so the figures are true rather than starting from an arbitrary zero.') }}
            </p>
        </template>
    </div>
</template>
