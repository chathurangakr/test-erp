<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/salary-advances';
import type { CashBankAccountOption, PaymentMethodOption } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    employees: { id: number; code: string; name: string }[];
    employeeId: number | null;
    accounts: CashBankAccountOption[];
    paymentMethods: PaymentMethodOption[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Salary advances', href: index() }] } });

const form = useForm({
    employee_id: props.employeeId,
    advance_date: props.today,
    amount: '',
    payment_method: 'CASH',
    cash_bank_account_id: null as number | null,
    reference: '',
    note: '',
});

const { t } = useT();

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head :title="t('Pay advance')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Pay advance" description="The money leaves the chosen cash or bank account now. It is taken back in full from the next salary paid." />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="form.submit(store())">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="employee_id">{{ t('Employee') }}</Label>
                    <select id="employee_id" v-model="form.employee_id" required :class="selectClass">
                        <option :value="null" disabled>{{ t('Select an employee…') }}</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
                    </select>
                    <InputError :message="form.errors.employee_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="advance_date">{{ t('Date') }}</Label>
                    <Input id="advance_date" v-model="form.advance_date" type="date" required />
                    <InputError :message="form.errors.advance_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="amount">{{ t('Amount (:currency)', { currency }) }}</Label>
                    <Input id="amount" v-model="form.amount" required inputmode="decimal" />
                    <InputError :message="form.errors.amount" />
                </div>
                <div class="grid gap-2">
                    <Label for="payment_method">{{ t('Paid by') }}</Label>
                    <select id="payment_method" v-model="form.payment_method" :class="selectClass">
                        <option v-for="m in paymentMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                    <InputError :message="form.errors.payment_method" />
                </div>
                <div class="grid gap-2">
                    <Label for="cash_bank_account_id">{{ t('Paid from') }}</Label>
                    <select id="cash_bank_account_id" v-model="form.cash_bank_account_id" required :class="selectClass">
                        <option :value="null" disabled>{{ t('Select a cash or bank account…') }}</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }} ({{ a.kind }})</option>
                    </select>
                    <InputError :message="form.errors.cash_bank_account_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="reference">{{ t('Reference') }}</Label>
                    <Input id="reference" v-model="form.reference" maxlength="100" />
                    <InputError :message="form.errors.reference" />
                </div>
                <div class="grid gap-2">
                    <Label for="note">{{ t('Note') }}</Label>
                    <Input id="note" v-model="form.note" maxlength="255" />
                    <InputError :message="form.errors.note" />
                </div>
            </div>

            <InputError :message="(form.errors as Record<string, string | undefined>).employee_advances_account_id" />

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ t('Pay advance') }}</Button>
                <Button variant="outline" as-child><Link :href="index()">{{ t('Cancel') }}</Link></Button>
            </div>
        </form>
    </div>
</template>
