<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import DeliveryForm from '@/components/sales/DeliveryForm.vue';
import { index } from '@/routes/deliveries';
import type { LotOption, OptionRef, SalesOrderOption, SalesProductOption, StatusOption } from '@/types';

const props = defineProps<{
    delivery: {
        id: number;
        delivery_number: string;
        customer_id: number | null;
        sales_order_id: number | null;
        warehouse_id: number | null;
        delivery_date: string | null;
        customer_reference: string | null;
        vehicle_number: string | null;
        driver_name: string | null;
        notes: string | null;
        lines: {
            product_id: number;
            sales_order_line_id: number | null;
            lot_id: number | null;
            quantity: string;
            secondary_quantity: string | null;
            pricing_basis: string;
            unit_price: string;
        }[];
    };
    customers: OptionRef[];
    warehouses: OptionRef[];
    products: SalesProductOption[];
    lots: LotOption[];
    salesOrders: SalesOrderOption[];
    pricingBases: StatusOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Deliveries', href: index() }] } });
</script>

<template>
    <Head :title="`Edit ${props.delivery.delivery_number}`" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="`Edit ${props.delivery.delivery_number}`" description="Only a draft delivery can be edited." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <DeliveryForm method="put" :action="`${index().url}/${props.delivery.id}`" submit-label="Save changes" :cancel-href="`${index().url}/${props.delivery.id}`" :customers="customers" :warehouses="warehouses" :products="products" :lots="lots" :sales-orders="salesOrders" :pricing-bases="pricingBases" :initial="props.delivery" />
        </div>
    </div>
</template>
