<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import CopraForm from '@/components/production/CopraForm.vue';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/copra';
import type { CopraFormValues, CopraRecipeInfo, OptionRef, ProductionLotOption } from '@/types';

const { t } = useT();

const props = defineProps<{
    order: CopraFormValues & { id: number; production_number: string };
    warehouses: OptionRef[];
    lots: ProductionLotOption[];
    recipe: CopraRecipeInfo;
    inputStock: string;
    inputUnitCost: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Copra production', href: index() }] } });
</script>

<template>
    <Head :title="t('Edit :reference', { reference: props.order.production_number })" />
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="t('Edit :reference', { reference: props.order.production_number })"
            description="Only a draft production order can be edited."
        />
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-5xl rounded-xl border p-4 sm:p-6">
            <CopraForm
                method="put"
                :action="`${index().url}/${props.order.id}`"
                :submit-label="t('Save changes')"
                :cancel-href="`${index().url}/${props.order.id}`"
                :warehouses="warehouses"
                :lots="lots"
                :recipe="recipe"
                :input-stock="inputStock"
                :input-unit-cost="inputUnitCost"
                :initial="props.order"
            />
        </div>
    </div>
</template>
