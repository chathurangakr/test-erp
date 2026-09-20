<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import HuskChippingForm from '@/components/production/HuskChippingForm.vue';
import { index } from '@/routes/husk-chipping';
import type { HuskChippingRecipeInfo, OptionRef, ProductionLotOption } from '@/types';

defineProps<{
    warehouses: OptionRef[];
    lots: ProductionLotOption[];
    recipe: HuskChippingRecipeInfo;
    wasteUoms: OptionRef[];
    inputStock: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Husk chipping', href: index() }, { title: 'New', href: index() }] },
});
</script>

<template>
    <Head title="New husk chipping" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New husk chipping"
            description="Saved as a draft. Stock converts only when you post it."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-5xl rounded-xl border p-4 sm:p-6">
            <HuskChippingForm
                method="post"
                :action="index().url"
                submit-label="Save draft"
                :cancel-href="index().url"
                :warehouses="warehouses"
                :lots="lots"
                :recipe="recipe"
                :waste-uoms="wasteUoms"
                :input-stock="inputStock"
            />
        </div>
    </div>
</template>
