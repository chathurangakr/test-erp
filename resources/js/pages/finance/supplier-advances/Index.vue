<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdvanceIndex from '@/components/advances/AdvanceIndex.vue';
import type { AdvanceRow } from '@/components/advances/types';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { create, index, show } from '@/routes/supplier-advances';
import type { Paginated } from '@/types';

defineProps<{
    advances: Paginated<AdvanceRow>;
    filters: InstanceType<typeof AdvanceIndex>['$props']['filters'];
    statuses: { value: string; label: string }[];
    parties: { id: number; code: string; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier advances', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();
</script>

<template>
    <Head :title="t('Supplier advances')" />
    <AdvanceIndex
        title="Supplier advances"
        description="Money paid to suppliers before their invoice. Apply it to their invoices when they arrive."
        party-label="Supplier"
        new-label="Pay an advance"
        :can-record="can('finance.ap.advance_record')"
        :advances="advances"
        :filters="filters"
        :statuses="statuses"
        :parties="parties"
        all-parties-label="All suppliers"
        :index-url="index().url"
        :currency="currency"
        :create-href="create().url"
        :show-href="(id: number) => show(id).url"
    />
</template>
