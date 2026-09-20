<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import HuskChippingForm from '@/components/production/HuskChippingForm.vue';
import { index } from '@/routes/husk-chipping';
import type {
    HuskChippingFormValues,
    HuskChippingRecipeInfo,
    OptionRef,
    ProductionLotOption,
} from '@/types';

const props = defineProps<{
    order: HuskChippingFormValues & { id: number; production_number: string };
    warehouses: OptionRef[];
    lots: ProductionLotOption[];
    recipe: HuskChippingRecipeInfo;
    wasteUoms: OptionRef[];
    inputStock: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Husk chipping', href: index() }] } });
</script>

<template>
    <Head :title="`Edit ${props.order.production_number}`" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${props.order.production_number}`"
            description="Only a draft production order can be edited."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-5xl rounded-xl border p-4 sm:p-6">
            <HuskChippingForm
                method="put"
                :action="`${index().url}/${props.order.id}`"
                submit-label="Save changes"
                :cancel-href="`${index().url}/${props.order.id}`"
                :warehouses="warehouses"
                :lots="lots"
                :recipe="recipe"
                :waste-uoms="wasteUoms"
                :input-stock="inputStock"
                :initial="props.order"
            />
        </div>
    </div>
</template>
