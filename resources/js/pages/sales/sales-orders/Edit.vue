<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import SalesOrderForm from '@/components/sales/SalesOrderForm.vue';
import { index } from '@/routes/sales-orders';
import type { OptionRef, SalesProductOption, StatusOption } from '@/types';

const props = defineProps<{
    order: {
        id: number;
        so_number: string;
        customer_id: number | null;
        warehouse_id: number | null;
        order_date: string | null;
        expected_delivery_date: string | null;
        customer_reference: string | null;
        notes: string | null;
        lines: {
            product_id: number;
            quantity: string;
            secondary_quantity: string | null;
            pricing_basis: string;
            unit_price: string;
        }[];
    };
    customers: OptionRef[];
    warehouses: OptionRef[];
    products: SalesProductOption[];
    pricingBases: StatusOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Sales orders', href: index() }] } });
</script>

<template>
    <Head :title="`Edit ${props.order.so_number}`" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="`Edit ${props.order.so_number}`" description="Only a draft sales order can be edited." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-6xl rounded-xl border p-4 sm:p-6">
            <SalesOrderForm method="put" :action="`${index().url}/${props.order.id}`" submit-label="Save changes" :cancel-href="`${index().url}/${props.order.id}`" :customers="customers" :warehouses="warehouses" :products="products" :pricing-bases="pricingBases" :initial="props.order" />
        </div>
    </div>
</template>
