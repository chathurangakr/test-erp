<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import SalesOrderForm from '@/components/sales/SalesOrderForm.vue';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/sales-orders';
import type { OptionRef, SalesProductOption, StatusOption } from '@/types';

const { t } = useT();

defineProps<{
    customers: OptionRef[];
    warehouses: OptionRef[];
    products: SalesProductOption[];
    pricingBases: StatusOption[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Sales orders', href: index() }, { title: 'New', href: index() }] },
});
</script>

<template>
    <Head :title="t('New sales order')" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New sales order" description="Record an intention to sell. No stock moves until a delivery is posted." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <SalesOrderForm method="post" :action="index().url" :submit-label="t('Create sales order')" :cancel-href="index().url" :customers="customers" :warehouses="warehouses" :products="products" :pricing-bases="pricingBases" />
        </div>
    </div>
</template>
