<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DehuskingForm from '@/components/production/DehuskingForm.vue';
import Heading from '@/components/Heading.vue';
import { index } from '@/routes/dehusking';
import type { OptionRef, ProductionLotOption, ProductionRecipeInfo } from '@/types';

defineProps<{
    warehouses: OptionRef[];
    lots: ProductionLotOption[];
    recipe: ProductionRecipeInfo;
    inputStock: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dehusking', href: index() }, { title: 'New', href: index() }] },
});
</script>

<template>
    <Head title="New dehusking" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New dehusking" description="Saved as a draft. Stock converts only when you post it." />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-5xl rounded-xl border p-4 sm:p-6">
            <DehuskingForm method="post" :action="index().url" submit-label="Save draft" :cancel-href="index().url" :warehouses="warehouses" :lots="lots" :recipe="recipe" :input-stock="inputStock" />
        </div>
    </div>
</template>
