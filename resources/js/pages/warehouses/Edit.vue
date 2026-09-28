<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WarehouseController from '@/actions/App/Http/Controllers/Warehouses/WarehouseController';
import Heading from '@/components/Heading.vue';
import WarehouseForm from '@/components/masters/WarehouseForm.vue';
import { index } from '@/routes/warehouses';
import type { Warehouse } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    warehouse: Warehouse;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Warehouses', href: index() }],
    },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('Edit :code', { code: warehouse.code })" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit :code', { code: warehouse.code })" description="Update the warehouse master record." />

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <WarehouseForm
                :action="WarehouseController.update.form(props.warehouse.id)"
                :warehouse="props.warehouse"
                submit-label="Save changes"
                :cancel-href="`${index().url}/${props.warehouse.id}`"
            />
        </div>
    </div>
</template>
