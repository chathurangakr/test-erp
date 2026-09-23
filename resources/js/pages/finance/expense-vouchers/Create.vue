<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ExpenseVoucherForm from '@/components/cash/ExpenseVoucherForm.vue';
import Heading from '@/components/Heading.vue';
import { index } from '@/routes/expense-vouchers';
import type { CashBankAccountOption, OptionRef } from '@/types';

defineProps<{
    accounts: CashBankAccountOption[];
    expenseAccounts: { id: number; code: string; name: string }[];
    suppliers: OptionRef[];
    currency: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Expense vouchers', href: index() },
            { title: 'New', href: index() },
        ],
    },
});
</script>

<template>
    <Head title="New expense voucher" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New expense voucher"
            description="An expense paid straight away. Saved as a draft; nothing leaves the bank until you post it."
        />

        <ExpenseVoucherForm
            :accounts="accounts"
            :expense-accounts="expenseAccounts"
            :suppliers="suppliers"
            :currency="currency"
            :submit-url="index().url"
            method="post"
            :cancel-url="index().url"
            submit-label="Save draft"
        />

        <p class="text-muted-foreground max-w-3xl text-xs">
            Use this when the money goes out at the same moment the expense is incurred. If the
            supplier invoices you and you pay later, record a supplier invoice instead and settle
            it with a supplier payment — that route raises a payable, and this one deliberately
            does not.
        </p>
    </div>
</template>
