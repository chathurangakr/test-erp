<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DehuskingForm from '@/components/production/DehuskingForm.vue';
import Heading from '@/components/Heading.vue';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/dehusking';
import type { OptionRef, ProductionRecipeOption } from '@/types';

const { t } = useT();

const props = defineProps<{
    order: {
        id: number;
        production_number: string;
        production_date: string | null;
        warehouse_id: number | null;
        input_product_id: number | null;
        lot_id: number | null;
        quantity: string | null;
        notes: string | null;
    };
    warehouses: OptionRef[];
    recipes: ProductionRecipeOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dehusking', href: index() }] } });
</script>

<template>
    <Head :title="t('Edit :reference', { reference: props.order.production_number })" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit :reference', { reference: props.order.production_number })" description="Only a draft production order can be edited." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-5xl rounded-xl border p-4 sm:p-6">
            <DehuskingForm method="put" :action="`${index().url}/${props.order.id}`" :submit-label="t('Save changes')" :cancel-href="`${index().url}/${props.order.id}`" :warehouses="warehouses" :recipes="recipes" :initial="props.order" />
        </div>
    </div>
</template>
