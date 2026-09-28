<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PurchaseOrderForm from '@/components/purchasing/PurchaseOrderForm.vue';
import { index } from '@/routes/purchase-orders';
import type { OptionRef, ProductOption } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    order: {
        id: number;
        po_number: string;
        supplier_id: number | null;
        warehouse_id: number | null;
        order_date: string | null;
        expected_date: string | null;
        supplier_reference: string | null;
        notes: string | null;
        lines: { product_id: number; quantity: string; unit_price: string }[];
    };
    suppliers: OptionRef[];
    warehouses: OptionRef[];
    products: ProductOption[];
}>();

const { t } = useT();

defineOptions({ layout: { breadcrumbs: [{ title: 'Purchase orders', href: index() }] } });
</script>

<template>
    <Head :title="t('Edit :reference', { reference: props.order.po_number })" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit :reference', { reference: props.order.po_number })" description="Only a draft purchase order can be edited." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-5xl rounded-xl border p-4 sm:p-6">
            <PurchaseOrderForm method="put" :action="`${index().url}/${props.order.id}`" submit-label="Save changes" :cancel-href="`${index().url}/${props.order.id}`" :suppliers="suppliers" :warehouses="warehouses" :products="products" :initial="props.order" />
        </div>
    </div>
</template>
