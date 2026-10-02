<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import SalesInvoiceForm from '@/components/receivables/SalesInvoiceForm.vue';
import { create, index } from '@/routes/sales-invoices';
import type { BillableDeliveryLine, OptionRef } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{
    customers: (OptionRef & { credit_days?: number })[];
    billableLines: BillableDeliveryLine[];
    customerId: number | null;
    creditDays: number;
    currency: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Sales invoices', href: index() }, { title: 'New', href: index() }] },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('New sales invoice')" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New sales invoice"
            description="Saved as a draft. No revenue is recognised until you post it."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <SalesInvoiceForm
                method="post"
                :action="index().url"
                :reload-url="create().url"
                submit-label="Save draft"
                :cancel-href="index().url"
                :customers="customers"
                :billable-lines="billableLines"
                :credit-days="creditDays"
                :customer-id="customerId"
                :currency="currency"
            />
        </div>
    </div>
</template>
