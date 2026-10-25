<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/assets';
import type { CashBankAccountOption, PaymentMethodOption } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

type Funding = 'CASH_BANK' | 'LOAN' | 'EXISTING';

const props = defineProps<{
    categories: { value: string; label: string }[];
    fundings: { value: Funding; label: string }[];
    accounts: CashBankAccountOption[];
    paymentMethods: PaymentMethodOption[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Assets', href: index() }, { title: 'Add an asset' }] } });

const { t } = useT();

const form = useForm({
    name: '',
    category: 'MACHINERY',
    description: '',
    purchase_date: props.today,
    purchase_price: '',
    funding: 'CASH_BANK' as Funding,
    down_payment: '',
    cash_bank_account_id: null as number | null,
    payment_method: 'BANK_TRANSFER',
    reference: '',
    lender: '',
    has_interest: true,
    interest_rate: '',
    installment_amount: '',
    due_day: '',
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
const paysNow = computed(() => form.funding === 'CASH_BANK' || (form.funding === 'LOAN' && Number(form.down_payment) > 0));
const borrowed = computed(() => Math.max(0, (Number(form.purchase_price) || 0) - (Number(form.down_payment) || 0)));

function submit(): void {
    form.transform((data) => {
        const loan = data.funding === 'LOAN';

        return {
            name: data.name,
            category: data.category,
            description: data.description,
            purchase_date: data.purchase_date,
            purchase_price: data.purchase_price,
            funding: data.funding,
            down_payment: loan ? data.down_payment || '0' : undefined,
            cash_bank_account_id: paysNow.value ? data.cash_bank_account_id : undefined,
            payment_method: paysNow.value ? data.payment_method : undefined,
            reference: data.reference,
            lender: loan ? data.lender : undefined,
            has_interest: data.has_interest,
            interest_rate: loan && data.has_interest ? data.interest_rate || null : undefined,
            installment_amount: loan ? data.installment_amount || null : undefined,
            due_day: loan ? data.due_day || null : undefined,
        };
    }).submit(store());
}
</script>

<template>
    <Head :title="t('Add an asset')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Add an asset" description="Record what it cost and how it was paid for. It stays at that cost until it is sold." />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">{{ t('Name') }}</Label>
                    <Input id="name" v-model="form.name" required maxlength="150" :placeholder="t('e.g. Tata lorry WP-1234, Dehusking machine')" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="category">{{ t('Kind') }}</Label>
                    <SearchableSelect id="category" v-model="form.category" :options="categories" />
                    <InputError :message="form.errors.category" />
                </div>
                <div class="grid gap-2">
                    <Label for="purchase_date">{{ t('Bought on') }}</Label>
                    <Input id="purchase_date" v-model="form.purchase_date" type="date" required />
                    <InputError :message="form.errors.purchase_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="purchase_price">{{ t('Cost (:currency)', { currency }) }}</Label>
                    <Input id="purchase_price" v-model="form.purchase_price" required inputmode="decimal" />
                    <InputError :message="form.errors.purchase_price" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="description">{{ t('Description') }}</Label>
                    <Input id="description" v-model="form.description" maxlength="2000" :placeholder="t('e.g. serial number, where it is kept')" />
                </div>
            </div>

            <div class="space-y-3">
                <Label>{{ t('How was it paid for?') }}</Label>
                <div class="grid gap-2 sm:grid-cols-3" role="radiogroup">
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
                <p v-if="form.funding === 'EXISTING'" class="text-muted-foreground text-xs">{{ t('For something owned before you started using this system. No money moves now.') }}</p>
            </div>

            <div v-if="form.funding === 'LOAN'" class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="lender">{{ t('Lender') }}</Label>
                    <Input id="lender" v-model="form.lender" required maxlength="150" :placeholder="t('e.g. the leasing company or the seller')" />
                    <InputError :message="form.errors.lender" />
                </div>
                <div class="grid gap-2">
                    <Label for="down_payment">{{ t('Down payment (:currency)', { currency }) }}</Label>
                    <Input id="down_payment" v-model="form.down_payment" inputmode="decimal" placeholder="0" />
                    <InputError :message="form.errors.down_payment" />
                </div>
                <div class="grid gap-2">
                    <Label for="installment_amount">{{ t('Usual installment (:currency)', { currency }) }}</Label>
                    <Input id="installment_amount" v-model="form.installment_amount" inputmode="decimal" />
                </div>
                <div class="grid gap-2">
                    <Label for="due_day">{{ t('Due day of the month') }}</Label>
                    <Input id="due_day" v-model="form.due_day" type="number" min="1" max="31" />
                    <InputError :message="form.errors.due_day" />
                </div>
                <label class="flex items-center gap-3 text-sm sm:col-span-2">
                    <input v-model="form.has_interest" type="checkbox" class="border-input size-4 rounded" />
                    {{ t('This loan carries interest') }}
                </label>
                <div v-if="form.has_interest" class="grid gap-2">
                    <Label for="interest_rate">{{ t('Interest rate (% a year)') }}</Label>
                    <Input id="interest_rate" v-model="form.interest_rate" inputmode="decimal" :placeholder="t('e.g. 18')" />
                    <InputError :message="errors.interest_rate" />
                </div>
                <p class="text-sm sm:col-span-2"><strong>{{ t('Loan: :amount', { amount: money(borrowed) }) }}</strong> — {{ t('it appears under Loans, with reminders before each installment.') }}</p>
            </div>

            <div v-if="paysNow" class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="payment_method">{{ t('Paid by') }}</Label>
                    <SearchableSelect id="payment_method" v-model="form.payment_method" :options="paymentMethods.map((m) => ({ value: m.value, label: m.label ?? '' }))" />
                </div>
                <div class="grid gap-2">
                    <Label for="cash_bank_account_id">{{ t('Paid from') }}</Label>
                    <SearchableSelect
                        id="cash_bank_account_id"
                        v-model="form.cash_bank_account_id"
                        required
                        :options="accounts.map((a) => ({ value: a.id, label: `${a.code} — ${a.name} (${a.kind})` }))"
                        :placeholder="t('Select a cash or bank account…')"
                    />
                    <InputError :message="form.errors.cash_bank_account_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="reference">{{ t('Reference') }}</Label>
                    <Input id="reference" v-model="form.reference" maxlength="100" />
                </div>
            </div>

            <InputError :message="errors.fixed_assets_account_id" />
            <InputError :message="errors.loans_account_id" />
            <InputError :message="errors.opening_balance_account_id" />

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ t('Add asset') }}</Button>
                <Button variant="outline" as-child><Link :href="index()">{{ t('Cancel') }}</Link></Button>
            </div>
        </form>
    </div>
</template>
