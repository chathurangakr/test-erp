<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ExpenseVoucherForm from '@/components/cash/ExpenseVoucherForm.vue';
import Heading from '@/components/Heading.vue';
import { index, show, update } from '@/routes/expense-vouchers';
import type { CashBankAccountOption, OptionRef } from '@/types';

const props = defineProps<{
    voucher: {
        id: number;
        voucher_number: string;
        voucher_date: string | null;
        cash_bank_account_id: number | null;
        supplier_id: number | null;
        payee_name: string | null;
        reference: string | null;
        description: string | null;
        lines: { expense_account_id: number; description: string | null; amount: string }[];
    };
    accounts: CashBankAccountOption[];
    expenseAccounts: { id: number; code: string; name: string }[];
    suppliers: OptionRef[];
    currency: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Expense vouchers', href: index() },
            { title: 'Edit', href: index() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${props.voucher.voucher_number}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="props.voucher.voucher_number" description="Draft — not yet posted." />

        <ExpenseVoucherForm
            :accounts="accounts"
            :expense-accounts="expenseAccounts"
            :suppliers="suppliers"
            :currency="currency"
            :submit-url="update(props.voucher.id).url"
            method="put"
            :cancel-url="show(props.voucher.id).url"
            submit-label="Save changes"
            :initial="props.voucher"
        />
    </div>
</template>
