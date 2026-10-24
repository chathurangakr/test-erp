<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import CopraForm from '@/components/production/CopraForm.vue';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/copra';
import type { CopraRecipeOption, OptionRef } from '@/types';

const { t } = useT();

defineProps<{
    warehouses: OptionRef[];
    recipes: CopraRecipeOption[];
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
                :recipes="recipes"
            />
        </div>
    </div>
</template>
