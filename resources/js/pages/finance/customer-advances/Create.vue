<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdvanceCreate from '@/components/advances/AdvanceCreate.vue';
import { useT } from '@/lib/i18n';
import { index, store } from '@/routes/customer-advances';

defineProps<{
    parties: { id: number; code: string; name: string }[];
    accounts: { id: number; code: string | null; name: string; kind: string }[];
    paymentMethods: { value: string; label: string }[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Customer advances', href: index() }, { title: 'Record an advance', href: '#' }] } });

const { t } = useT();
</script>

<template>
    <Head :title="t('Record an advance')" />
    <AdvanceCreate
        party="customer"
        title="Record an advance"
        description="Money customers paid before their invoice. Apply it to their invoices as they are raised."
        party-label="Customer"
        account-label="Received into"
        :parties="parties"
        :accounts="accounts"
        :payment-methods="paymentMethods"
        :today="today"
        :currency="currency"
        :action="store()"
    />
</template>
