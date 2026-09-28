<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WarehouseController from '@/actions/App/Http/Controllers/Warehouses/WarehouseController';
import Heading from '@/components/Heading.vue';
import WarehouseForm from '@/components/masters/WarehouseForm.vue';
import { index } from '@/routes/warehouses';
import { useT } from '@/lib/i18n';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Warehouses', href: index() },
            { title: 'New warehouse', href: index() },
        ],
    },
});

const { t } = useT();
</script>

<template>
    <Head :title="t('New warehouse')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New warehouse" description="Add a stock location." />

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <WarehouseForm
                :action="WarehouseController.store.form()"
                submit-label="Create warehouse"
                :cancel-href="index().url"
            />
        </div>
    </div>
</template>
