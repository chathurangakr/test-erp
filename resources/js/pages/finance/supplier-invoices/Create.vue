<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import SupplierInvoiceForm from '@/components/payables/SupplierInvoiceForm.vue';
import { index } from '@/routes/supplier-invoices';
import type { AccountOption, MatchableGoodsReceipt, OptionRef, StatusOption } from '@/types';

defineProps<{
    suppliers: OptionRef[];
    goodsReceipts: MatchableGoodsReceipt[];
    expenseAccounts: AccountOption[];
    lineTypes: StatusOption[];
    currency: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Supplier invoices', href: index() }, { title: 'New', href: index() }] },
});
</script>

<template>
    <Head title="New supplier invoice" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New supplier invoice"
            description="Saved as a draft. Nothing is owed until you post it."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <SupplierInvoiceForm
                method="post"
                :action="index().url"
                submit-label="Save draft"
                :cancel-href="index().url"
                :suppliers="suppliers"
                :goods-receipts="goodsReceipts"
                :expense-accounts="expenseAccounts"
                :line-types="lineTypes"
                :currency="currency"
            />
        </div>
    </div>
</template>
