<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import SupplierInvoiceForm from '@/components/payables/SupplierInvoiceForm.vue';
import { index } from '@/routes/supplier-invoices';
import type {
    AccountOption,
    MatchableGoodsReceipt,
    OptionRef,
    StatusOption,
    SupplierInvoiceFormValues,
} from '@/types';

const props = defineProps<{
    invoice: SupplierInvoiceFormValues & { id: number; internal_invoice_number: string };
    suppliers: OptionRef[];
    goodsReceipts: MatchableGoodsReceipt[];
    expenseAccounts: AccountOption[];
    lineTypes: StatusOption[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier invoices', href: index() }] } });
</script>

<template>
    <Head :title="`Edit ${props.invoice.internal_invoice_number}`" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${props.invoice.internal_invoice_number}`"
            description="Only a draft supplier invoice can be edited."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <SupplierInvoiceForm
                method="put"
                :action="`${index().url}/${props.invoice.id}`"
                submit-label="Save changes"
                :cancel-href="`${index().url}/${props.invoice.id}`"
                :suppliers="suppliers"
                :goods-receipts="goodsReceipts"
                :expense-accounts="expenseAccounts"
                :line-types="lineTypes"
                :currency="currency"
                :initial="props.invoice"
            />
        </div>
    </div>
</template>
