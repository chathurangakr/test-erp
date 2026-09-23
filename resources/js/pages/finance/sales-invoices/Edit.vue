<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import SalesInvoiceForm from '@/components/receivables/SalesInvoiceForm.vue';
import { index } from '@/routes/sales-invoices';
import type { BillableDeliveryLine, OptionRef, SalesInvoiceFormValues } from '@/types';

const props = defineProps<{
    invoice: SalesInvoiceFormValues & { id: number; invoice_number: string };
    customers: (OptionRef & { credit_days?: number })[];
    billableLines: BillableDeliveryLine[];
    customerId: number | null;
    creditDays: number;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Sales invoices', href: index() }] } });
</script>

<template>
    <Head :title="`Edit ${props.invoice.invoice_number}`" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${props.invoice.invoice_number}`"
            description="Only a draft sales invoice can be edited."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <SalesInvoiceForm
                method="put"
                :action="`${index().url}/${props.invoice.id}`"
                :reload-url="`${index().url}/${props.invoice.id}/edit`"
                submit-label="Save changes"
                :cancel-href="`${index().url}/${props.invoice.id}`"
                :customers="customers"
                :billable-lines="billableLines"
                :credit-days="creditDays"
                :customer-id="customerId"
                :currency="currency"
                :initial="props.invoice"
            />
        </div>
    </div>
</template>
