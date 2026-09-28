<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import Heading from '@/components/Heading.vue';
import CustomerForm from '@/components/masters/CustomerForm.vue';
import { index } from '@/routes/customers';
import type { Customer } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    customer: Customer;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Customers', href: index() }],
    },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('Edit :code', { code: customer.code })" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit :code', { code: customer.code })" description="Update the customer master record." />

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <CustomerForm
                :action="CustomerController.update.form(props.customer.id)"
                :customer="props.customer"
                submit-label="Save changes"
                :cancel-href="`${index().url}/${props.customer.id}`"
            />
        </div>
    </div>
</template>
