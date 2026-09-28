<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import DeliveryForm from '@/components/sales/DeliveryForm.vue';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/deliveries';
import type { LotOption, OptionRef, SalesOrderOption, SalesProductOption, StatusOption } from '@/types';

const { t } = useT();

defineProps<{
    customers: OptionRef[];
    warehouses: OptionRef[];
    products: SalesProductOption[];
    lots: LotOption[];
    salesOrders: SalesOrderOption[];
    pricingBases: StatusOption[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Deliveries', href: index() }, { title: 'New', href: index() }] },
});
</script>

<template>
    <Head :title="t('New delivery')" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New delivery" description="Saved as a draft. Stock leaves only when you post it." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <DeliveryForm method="post" :action="index().url" :submit-label="t('Save draft')" :cancel-href="index().url" :customers="customers" :warehouses="warehouses" :products="products" :lots="lots" :sales-orders="salesOrders" :pricing-bases="pricingBases" />
        </div>
    </div>
</template>
