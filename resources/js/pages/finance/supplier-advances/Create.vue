<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdvanceCreate from '@/components/advances/AdvanceCreate.vue';
import { useT } from '@/lib/i18n';
import { index, store } from '@/routes/supplier-advances';

defineProps<{
    parties: { id: number; code: string; name: string }[];
    accounts: { id: number; code: string | null; name: string; kind: string }[];
    paymentMethods: { value: string; label: string }[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier advances', href: index() }, { title: 'Pay an advance', href: '#' }] } });

const { t } = useT();
</script>

<template>
    <Head :title="t('Pay an advance')" />
    <AdvanceCreate
        party="supplier"
        title="Pay an advance"
        description="Money paid to suppliers before their invoice. Apply it to their invoices when they arrive."
        party-label="Supplier"
        account-label="Paid from"
        :parties="parties"
        :accounts="accounts"
        :payment-methods="paymentMethods"
        :today="today"
        :currency="currency"
        :action="store()"
    />
</template>
