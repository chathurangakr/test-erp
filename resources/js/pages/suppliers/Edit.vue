<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SupplierController from '@/actions/App/Http/Controllers/Suppliers/SupplierController';
import Heading from '@/components/Heading.vue';
import SupplierForm from '@/components/masters/SupplierForm.vue';
import { index } from '@/routes/suppliers';
import type { Supplier } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    supplier: Supplier;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Suppliers', href: index() }],
    },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('Edit :code', { code: supplier.code })" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit :code', { code: supplier.code })" description="Update the supplier master record." />

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <SupplierForm
                :action="SupplierController.update.form(props.supplier.id)"
                :supplier="props.supplier"
                submit-label="Save changes"
                :cancel-href="`${index().url}/${props.supplier.id}`"
            />
        </div>
    </div>
</template>
