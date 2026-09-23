<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, index, show } from '@/routes/expense-vouchers';
import type { CashBankAccountOption, ExpenseVoucherRow, Paginated } from '@/types';

const props = defineProps<{
    vouchers: Paginated<ExpenseVoucherRow>;
    filters: { search: string; status: string; cash_bank_account_id: number | null };
    statuses: { value: string; label: string }[];
    accounts: CashBankAccountOption[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Expense vouchers', href: index() }] } });

const search = ref(props.filters.search);
const status = ref(props.filters.status);

watch([search, status], () => {
    router.get(
        index().url,
        { search: search.value || undefined, status: status.value || undefined },
        { preserveState: true, replace: true },
    );
});

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Expense vouchers" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Expense vouchers" description="Expenses paid immediately from cash or the bank." />
            <Button as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    New voucher
                </Link>
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Input v-model="search" placeholder="Search number, payee or reference" />
            <select v-model="status" :class="selectClass">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Voucher</th>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium">Payee</th>
                        <th class="px-4 py-3 font-medium">Paid from</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Amount ({{ currency }})</th>
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
                        <td class="px-4 py-3 text-right font-mono">{{ voucher.total }}</td>
                    </tr>
                    <tr v-if="vouchers.data.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">
                            No expense vouchers yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            An expense voucher is for money that has already gone — it debits the expense and
            credits cash or the bank in one step, and raises no payable. A supplier who invoices
            you and is paid later belongs on a supplier invoice instead.
        </p>
    </div>
</template>
