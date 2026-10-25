<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/expense-vouchers';
import type { CashBankAccountOption, ExpenseVoucherRow, Paginated } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    vouchers: Paginated<ExpenseVoucherRow>;
    filters: { search: string; status: string; cash_bank_account_id: number | null } & ListControlValues;
    statuses: { value: string; label: string }[];
    accounts: CashBankAccountOption[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Expense vouchers', href: index() }] } });

const { t } = useT();

const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    cash_bank_account_id: props.filters.cash_bank_account_id ? String(props.filters.cash_bank_account_id) : '',
});

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

</script>

<template>
    <Head :title="t('Expense vouchers')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Expense vouchers" description="Expenses paid immediately from cash or the bank." />
            <Button v-if="can('finance.cash.expense_create')" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    {{ t('New voucher') }}
                </Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="grid gap-1 text-xs w-full sm:w-56">
                <span class="text-muted-foreground">{{ t('Search') }}</span>
                <Input v-model="filters.search" type="search" :placeholder="t('Search number, payee or reference')" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-56">
                <span class="text-muted-foreground">{{ t('Paid from') }}</span>
                <SearchableSelect v-model="filters.cash_bank_account_id" :options="[{ value: '', label: t('All accounts') }, ...accounts.map((a) => ({ value: String(a.id), label: a.name }))]" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-48">
                <span class="text-muted-foreground">{{ t('Status') }}</span>
                <SearchableSelect v-model="filters.status" :options="[{ value: '', label: t('All statuses') }, ...statuses.map((s) => ({ value: s.value, label: s.label }))]" />
            </div>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Voucher" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Payee" column="payee" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Paid from" column="account" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Amount" column="amount" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Amount (:currency)', { currency }) }}</SortHeader>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="voucher in vouchers.data"
                        :key="voucher.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <Link :href="show(voucher.id)" class="font-mono text-xs font-medium hover:underline">
                                {{ voucher.voucher_number }}
                            </Link>
                            <span v-if="voucher.reference" class="text-muted-foreground block text-xs">
                                {{ voucher.reference }}
                            </span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ voucher.voucher_date }}</td>
                        <td class="px-4 py-3">{{ voucher.payee ?? '—' }}</td>
                        <td class="px-4 py-3">{{ voucher.payment_account ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(voucher.status)">{{ voucher.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(voucher.total) }}</td>
                    </tr>
                    <tr v-if="vouchers.data.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">
                            {{ t('No expense vouchers yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="vouchers" />

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('An expense voucher is for money that has already gone — it debits the expense and credits cash or the bank in one step, and raises no payable. A supplier who invoices you and is paid later belongs on a supplier invoice instead.') }}
        </p>
    </div>
</template>
