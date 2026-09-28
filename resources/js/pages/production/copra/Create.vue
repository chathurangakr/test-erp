<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import CopraForm from '@/components/production/CopraForm.vue';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/copra';
import type { CopraRecipeInfo, OptionRef, ProductionLotOption } from '@/types';

const { t } = useT();

defineProps<{
    warehouses: OptionRef[];
    lots: ProductionLotOption[];
    recipe: CopraRecipeInfo;
    inputStock: string;
    inputUnitCost: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Copra production', href: index() }, { title: 'New', href: index() }] },
});
</script>

<template>
    <Head :title="t('New copra production')" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New copra production"
            description="Saved as a draft. Stock converts only when you post it."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-5xl rounded-xl border p-4 sm:p-6">
            <CopraForm
                method="post"
                :action="index().url"
                :submit-label="t('Save draft')"
                :cancel-href="index().url"
                :warehouses="warehouses"
                :lots="lots"
                :recipe="recipe"
                :input-stock="inputStock"
                :input-unit-cost="inputUnitCost"
            />
        </div>
    </div>
</template>
