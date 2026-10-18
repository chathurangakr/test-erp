<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import Heading from '@/components/Heading.vue';
import CustomerForm from '@/components/masters/CustomerForm.vue';
import { index } from '@/routes/customers';
import { useT } from '@/lib/i18n';

defineProps<{ nextCode: string }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Customers', href: index() },
            { title: 'New customer', href: index() },
        ],
    },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('New customer')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New customer" description="Add a party the factory sells products to." />

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <CustomerForm
                :action="CustomerController.store.form()"
                :next-code="nextCode"
                submit-label="Create customer"
                :cancel-href="index().url"
            />
        </div>
    </div>
</template>
