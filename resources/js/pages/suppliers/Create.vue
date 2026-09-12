<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SupplierController from '@/actions/App/Http/Controllers/Suppliers/SupplierController';
import Heading from '@/components/Heading.vue';
import SupplierForm from '@/components/masters/SupplierForm.vue';
import { index } from '@/routes/suppliers';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Suppliers', href: index() },
            { title: 'New supplier', href: index() },
        ],
    },
});
</script>

<template>
    <Head title="New supplier" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New supplier" description="Add a party the factory buys materials from." />

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <SupplierForm
                :action="SupplierController.store.form()"
                submit-label="Create supplier"
                :cancel-href="index().url"
            />
        </div>
    </div>
</template>
