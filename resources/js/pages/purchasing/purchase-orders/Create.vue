<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PurchaseOrderForm from '@/components/purchasing/PurchaseOrderForm.vue';
import { index } from '@/routes/purchase-orders';
import type { OptionRef, ProductOption } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{ suppliers: OptionRef[]; warehouses: OptionRef[]; products: ProductOption[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Purchase orders', href: index() }, { title: 'New', href: index() }] },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('New purchase order')" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New purchase order" description="Record an intention to buy. No stock is created until goods are received." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-5xl rounded-xl border p-4 sm:p-6">
            <PurchaseOrderForm method="post" :action="index().url" submit-label="Create purchase order" :cancel-href="index().url" :suppliers="suppliers" :warehouses="warehouses" :products="products" />
        </div>
    </div>
</template>
