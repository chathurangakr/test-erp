<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import EmployeeForm from '@/components/payroll/EmployeeForm.vue';
import { index } from '@/routes/employees';
import type { EmployeeDetail } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{ employee: EmployeeDetail }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Employees', href: index() }] } });

const { t } = useT();
</script>

<template>
    <Head :title="t('Edit employee — :name', { name: employee.name })" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit employee — :name', { name: employee.name })" />
        <EmployeeForm :employee="employee" />
    </div>
</template>
