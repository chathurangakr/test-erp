<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/loans';
import type { CashBankAccountOption } from '@/types';
import { useT } from '@/lib/i18n';
import LoanScheduleFields from '@/components/loans/LoanScheduleFields.vue';

const props = defineProps<{
    fundings: { value: 'RECEIVED' | 'EXISTING'; label: string }[];
    accounts: CashBankAccountOption[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Loans', href: index() }, { title: 'Add a loan' }] } });

const { t } = useT();

const form = useForm({
    funding: 'EXISTING' as 'RECEIVED' | 'EXISTING',
    start_date: props.today,
    amount: '',
    cash_bank_account_id: null as number | null,
    lender: '',
    purpose: '',
    has_interest: true,
    interest_rate: '',
    installment_amount: '',
    due_day: '',
    notes: '',
});

const errors = form.errors as Record<string, string | undefined>;

function submit(): void {
    form.transform((data) => ({
        ...data,
        cash_bank_account_id: data.funding === 'RECEIVED' ? data.cash_bank_account_id : undefined,
        interest_rate: data.interest_rate || null, installment_amount: data.installment_amount || null,
        due_day: data.due_day || null,
    })).submit(store());
}
</script>

<template>
    <Head :title="t('Add a loan')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Add a loan" description="A new loan just received, or one already running that you are paying now." />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid grid-cols-2 gap-2" role="radiogroup">
                <button
                    v-for="option in fundings"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="form.funding === option.value"
                    class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                    :class="form.funding === option.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                    @click="form.funding = option.value"
                >
                    {{ option.label }}
                </button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="amount">{{ form.funding === 'EXISTING' ? t('Still owed today (:currency)', { currency }) : t('Amount borrowed (:currency)', { currency }) }}</Label>
                    <Input id="amount" v-model="form.amount" required inputmode="decimal" />
                    <InputError :message="form.errors.amount" />
                </div>
                <div class="grid gap-2">
                    <Label for="start_date">{{ form.funding === 'EXISTING' ? t('As of') : t('Received on') }}</Label>
                    <Input id="start_date" v-model="form.start_date" type="date" required />
                    <InputError :message="form.errors.start_date" />
                </div>
                <div v-if="form.funding === 'RECEIVED'" class="grid gap-2 sm:col-span-2">
                    <Label for="cash_bank_account_id">{{ t('Paid into') }}</Label>
                    <SearchableSelect
                        id="cash_bank_account_id"
                        v-model="form.cash_bank_account_id"
                        required
                        :options="accounts.map((a) => ({ value: a.id, label: `${a.code} — ${a.name} (${a.kind})` }))"
                        :placeholder="t('Select a cash or bank account…')"
                    />
                    <InputError :message="form.errors.cash_bank_account_id" />
                </div>
                <p v-else class="text-muted-foreground text-xs sm:col-span-2">
                    {{ t('For a loan taken before you started using this system: enter only what is still owed now. Payments made before today are not entered.') }}
                </p>
            </div>

            <LoanScheduleFields
                v-model:lender="form.lender"
                v-model:purpose="form.purpose"
                v-model:has-interest="form.has_interest"
                v-model:interest-rate="form.interest_rate"
                v-model:installment="form.installment_amount"
                v-model:due-day="form.due_day"
                v-model:notes="form.notes"
                :errors="errors"
                :currency="currency"
            />

            <InputError :message="errors.loans_account_id" />
            <InputError :message="errors.opening_balance_account_id" />

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ t('Add loan') }}</Button>
                <Button variant="outline" as-child><Link :href="index()">{{ t('Cancel') }}</Link></Button>
            </div>
        </form>
    </div>
</template>
