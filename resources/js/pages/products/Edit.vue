<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductController from '@/actions/App/Http/Controllers/Products/ProductController';
import Heading from '@/components/Heading.vue';
import ProductForm from '@/components/products/ProductForm.vue';
import { index } from '@/routes/products';
import type { Product, UnitOfMeasure } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    product: Product;
    unitsOfMeasure: UnitOfMeasure[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Products', href: index() }],
    },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('Edit :code', { code: product.code })" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="t('Edit :code', { code: product.code })"
            description="Update the product master record."
        />

        <div
            class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6"
        >
            <ProductForm
                :action="ProductController.update.form(props.product.id)"
                :product="props.product"
                :units-of-measure="unitsOfMeasure"
                submit-label="Save changes"
                :cancel-href="`${index().url}/${props.product.id}`"
            />
        </div>
    </div>
</template>
