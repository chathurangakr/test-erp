<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import EmployeeForm from '@/components/payroll/EmployeeForm.vue';
import { index } from '@/routes/employees';
import { useT } from '@/lib/i18n';

defineOptions({ layout: { breadcrumbs: [{ title: 'Employees', href: index() }] } });

const { t } = useT();
</script>

<template>
    <Head :title="t('Add employee')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Add employee" description="Someone the factory pays by the day." />
        <EmployeeForm />
    </div>
</template>
