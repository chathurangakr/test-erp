<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdvanceShow from '@/components/advances/AdvanceShow.vue';
import { usePermissions } from '@/composables/usePermissions';
import { apply, index, refund, reverse, reverseApplication, reverseRefund } from '@/routes/customer-advances';

const props = defineProps<{
    advance: InstanceType<typeof AdvanceShow>['$props']['advance'];
    invoices: { id: number; number: string; date: string; outstanding: string }[];
    canRefund: boolean;
    accounts: { id: number; code: string | null; name: string; kind: string }[];
    paymentMethods: { value: string; label: string }[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Customer advances', href: index() }, { title: 'Advance', href: '#' }] } });

const { can } = usePermissions();
</script>

<template>
    <Head :title="props.advance.advance_number" />
    <AdvanceShow
        party-label="Customer"
        :advance="advance"
        :invoices="invoices"
        :can-refund="canRefund"
        :accounts="accounts"
        :payment-methods="paymentMethods"
        refund-title="Give money back to the customer"
        :refund-action="refund(advance.id)"
        :reverse-refund-url="(id: number) => reverseRefund(id).url"
        :today="today"
        :currency="currency"
        :can-record="can('finance.ar.advance_record')"
        :can-reverse="can('finance.ar.advance_reverse')"
        :apply-action="apply(advance.id)"
        :reverse-url="reverse(advance.id).url"
        :reverse-application-url="(id: number) => reverseApplication(id).url"
    />
</template>
