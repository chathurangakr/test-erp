<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import GoodsReceiptForm from '@/components/purchasing/GoodsReceiptForm.vue';
import { index } from '@/routes/goods-receipts';
import type { OptionRef, ProductOption, PurchaseOrderOption } from '@/types';

defineProps<{
    suppliers: OptionRef[];
    warehouses: OptionRef[];
    products: ProductOption[];
    purchaseOrders: PurchaseOrderOption[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Goods receipts', href: index() }, { title: 'New', href: index() }] },
});
</script>

<template>
    <Head title="New goods receipt" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New goods receipt" description="Saved as a draft. Stock moves only when you post it." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <GoodsReceiptForm method="post" :action="index().url" submit-label="Save draft" :cancel-href="index().url" :suppliers="suppliers" :warehouses="warehouses" :products="products" :purchase-orders="purchaseOrders" />
        </div>
    </div>
</template>
