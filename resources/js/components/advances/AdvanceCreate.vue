<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { RouteDefinition } from '@/wayfinder';

const props = defineProps<{
    party: 'customer' | 'supplier';
    title: string;
    description: string;
    partyLabel: string;
    accountLabel: string;
    parties: { id: number; code: string; name: string }[];
    accounts: { id: number; code: string | null; name: string; kind: string }[];
    paymentMethods: { value: string; label: string }[];
    today: string;
    currency: string;
    action: RouteDefinition<'post'>;
}>();

const { t } = useT();

const form = useForm({
    [`${props.party}_id`]: '',
    advance_date: props.today,
    amount: '',
    cash_bank_account_id: props.accounts[0] ? String(props.accounts[0].id) : '',
    payment_method: 'CASH',
    cheque_number: '',
    cheque_date: '',
    cheque_bank: '',
    reference: '',
    note: '',
} as Record<string, string>);

const errors = computed(() => form.errors as Record<string, string | undefined>);

function save(): void {
    form.submit(props.action, { preserveScroll: true });
}
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading :title="title" :description="description" />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-2xl space-y-5 rounded-xl border p-4 sm:p-6" @submit.prevent="save">
            <div class="grid gap-2">
                <Label>{{ t(partyLabel) }} <span class="text-destructive">*</span></Label>
                <SearchableSelect v-model="form[`${party}_id`]" :options="parties.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))" />
                <InputError :message="errors[`${party}_id`]" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="amount">{{ t('Amount (:currency)', { currency }) }} <span class="text-destructive">*</span></Label>
                    <Input id="amount" v-model="form.amount" inputmode="decimal" placeholder="0.00" />
                    <InputError :message="errors.amount" />
                </div>
                <div class="grid gap-2">
                    <Label for="advance_date">{{ t('Date') }}</Label>
                    <Input id="advance_date" v-model="form.advance_date" type="date" />
                    <InputError :message="errors.advance_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="payment_method">{{ t('Method') }}</Label>
                    <select id="payment_method" v-model="form.payment_method" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option v-for="method in paymentMethods" :key="method.value" :value="method.value">{{ method.label }}</option>
                    </select>
                    <InputError :message="errors.payment_method" />
                </div>
                <div class="grid gap-2">
                    <Label for="cash_bank_account_id">{{ t(accountLabel) }}</Label>
                    <select id="cash_bank_account_id" v-model="form.cash_bank_account_id" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option v-for="account in accounts" :key="account.id" :value="String(account.id)">{{ account.name }} ({{ account.kind }})</option>
                    </select>
                    <InputError :message="errors.cash_bank_account_id" />
                </div>
            </div>

            <div v-if="form.payment_method === 'CHEQUE'" class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="cheque_number">{{ t('Cheque number') }}</Label>
                    <Input id="cheque_number" v-model="form.cheque_number" />
                    <InputError :message="errors.cheque_number" />
                </div>
                <div class="grid gap-2">
                    <Label for="cheque_date">{{ t('Cheque date') }}</Label>
                    <Input id="cheque_date" v-model="form.cheque_date" type="date" />
                    <InputError :message="errors.cheque_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="cheque_bank">{{ t('Bank') }}</Label>
                    <Input id="cheque_bank" v-model="form.cheque_bank" />
                    <InputError :message="errors.cheque_bank" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="reference">{{ t('Reference') }}</Label>
                    <Input id="reference" v-model="form.reference" maxlength="100" />
                </div>
                <div class="grid gap-2">
                    <Label for="note">{{ t('Note') }}</Label>
                    <Input id="note" v-model="form.note" maxlength="500" />
                </div>
            </div>

            <InputError :message="errors.status" />
            <Button type="submit" :disabled="form.processing">{{ t('Record') }}</Button>
        </form>
    </div>
</template>
