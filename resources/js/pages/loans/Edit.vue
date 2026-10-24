<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index, show, update } from '@/routes/loans';
import { useT } from '@/lib/i18n';
import LoanScheduleFields from '@/components/loans/LoanScheduleFields.vue';

const props = defineProps<{
    currency: string;
    loan: { id: number; loan_number: string; lender: string; purpose: string | null; has_interest: boolean; installment_amount: string | null; due_day: number | null; notes: string | null };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Loans', href: index() }] } });

const { t } = useT();

const form = useForm({
    lender: props.loan.lender,
    purpose: props.loan.purpose ?? '',
    has_interest: props.loan.has_interest,
    installment_amount: props.loan.installment_amount ? String(Number(props.loan.installment_amount).toFixed(2)) : '',
    due_day: props.loan.due_day ? String(props.loan.due_day) : '',
    notes: props.loan.notes ?? '',
});

function submit(): void {
    form.transform((data) => ({ ...data, installment_amount: data.installment_amount || null, due_day: data.due_day || null })).submit(update(props.loan.id));
}
</script>

<template>
    <Head :title="t('Edit :reference', { reference: loan.loan_number })" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="t('Edit :reference', { reference: loan.loan_number })" description="Correct who lent it and the reminder schedule. Amounts are corrected by reversing." />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <LoanScheduleFields
                v-model:lender="form.lender"
                v-model:purpose="form.purpose"
                v-model:has-interest="form.has_interest"
                v-model:installment="form.installment_amount"
                v-model:due-day="form.due_day"
                v-model:notes="form.notes"
                :errors="form.errors as Record<string, string | undefined>"
                :currency="currency"
            />
            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ t('Save changes') }}</Button>
                <Button variant="outline" as-child><Link :href="show(loan.id)">{{ t('Cancel') }}</Link></Button>
            </div>
        </form>
    </div>
</template>
