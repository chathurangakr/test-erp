<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductController from '@/actions/App/Http/Controllers/Products/ProductController';
import Heading from '@/components/Heading.vue';
import ProductForm from '@/components/products/ProductForm.vue';
import { index } from '@/routes/products';
import type { UnitOfMeasure } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{
    unitsOfMeasure: UnitOfMeasure[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Products', href: index() },
            { title: 'New product', href: index() },
        ],
    },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('New product')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New product"
            description="Add a product to the master. Stock is tracked separately through inventory transactions."
        />

        <div
            class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6"
        >
            <ProductForm
                :action="ProductController.store.form()"
                :units-of-measure="unitsOfMeasure"
                submit-label="Create product"
                :cancel-href="index().url"
            />
        </div>
    </div>
</template>
