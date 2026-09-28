<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import GoodsReceiptForm from '@/components/purchasing/GoodsReceiptForm.vue';
import { index } from '@/routes/goods-receipts';
import type { OptionRef, ProductOption, PurchaseOrderOption } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    receipt: {
        id: number;
        grn_number: string;
        supplier_id: number | null;
        purchase_order_id: number | null;
        warehouse_id: number | null;
        receipt_date: string | null;
        supplier_delivery_reference: string | null;
        transport_cost: string;
        loading_cost: string;
        other_cost: string;
        notes: string | null;
        lines: {
            product_id: number;
            purchase_order_line_id: number | null;
            quantity: string;
            secondary_quantity: string | null;
            unit_price: string;
            supplier_lot_number: string | null;
        }[];
    };
    suppliers: OptionRef[];
    warehouses: OptionRef[];
    products: ProductOption[];
    purchaseOrders: PurchaseOrderOption[];
}>();

const { t } = useT();

defineOptions({ layout: { breadcrumbs: [{ title: 'Goods receipts', href: index() }] } });
</script>

<template>
    <Head :title="t('Edit :reference', { reference: props.receipt.grn_number })" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit :reference', { reference: props.receipt.grn_number })" description="Only a draft goods receipt can be edited." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <GoodsReceiptForm method="put" :action="`${index().url}/${props.receipt.id}`" submit-label="Save changes" :cancel-href="`${index().url}/${props.receipt.id}`" :suppliers="suppliers" :warehouses="warehouses" :products="products" :purchase-orders="purchaseOrders" :initial="props.receipt" />
        </div>
    </div>
</template>
