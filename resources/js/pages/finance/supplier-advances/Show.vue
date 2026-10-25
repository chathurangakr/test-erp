<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdvanceShow from '@/components/advances/AdvanceShow.vue';
import { usePermissions } from '@/composables/usePermissions';
import { apply, index, reverse, reverseApplication } from '@/routes/supplier-advances';

const props = defineProps<{
    advance: InstanceType<typeof AdvanceShow>['$props']['advance'];
    invoices: { id: number; number: string; date: string; outstanding: string }[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier advances', href: index() }, { title: 'Advance', href: '#' }] } });

const { can } = usePermissions();
</script>

<template>
    <Head :title="props.advance.advance_number" />
    <AdvanceShow
        party-label="Supplier"
        :advance="advance"
        :invoices="invoices"
        :today="today"
        :currency="currency"
        :can-record="can('finance.ap.advance_record')"
        :can-reverse="can('finance.ap.advance_reverse')"
        :apply-action="apply(advance.id)"
        :reverse-url="reverse(advance.id).url"
        :reverse-application-url="(id: number) => reverseApplication(id).url"
    />
</template>
