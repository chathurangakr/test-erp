<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdvanceIndex from '@/components/advances/AdvanceIndex.vue';
import type { AdvanceRow } from '@/components/advances/types';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { create, index, show } from '@/routes/customer-advances';
import type { Paginated } from '@/types';

defineProps<{
    advances: Paginated<AdvanceRow>;
    filters: InstanceType<typeof AdvanceIndex>['$props']['filters'];
    statuses: { value: string; label: string }[];
    parties: { id: number; code: string; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Customer advances', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();
</script>

<template>
    <Head :title="t('Customer advances')" />
    <AdvanceIndex
        title="Customer advances"
        description="Money customers paid before their invoice. Apply it to their invoices as they are raised."
        party-label="Customer"
        new-label="Record an advance"
        :can-record="can('finance.ar.advance_record')"
        :advances="advances"
        :filters="filters"
        :statuses="statuses"
        :parties="parties"
        all-parties-label="All customers"
        :index-url="index().url"
        :currency="currency"
        :create-href="create().url"
        :show-href="(id: number) => show(id).url"
    />
</template>
