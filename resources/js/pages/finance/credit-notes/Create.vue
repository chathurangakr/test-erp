<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { index, store } from '@/routes/credit-notes';

const props = defineProps<{
    invoices: { id: number; invoice_number: string; invoice_date: string; customer: string | null; outstanding: string; refundable: string }[];
    invoiceId: number | null;
    kinds: { value: string; label: string }[];
    settlements: { value: string; label: string }[];
    accounts: { id: number; code: string | null; name: string; kind: string }[];
    paymentMethods: { value: string; label: string }[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Credit notes', href: index() }, { title: 'Give a discount or allowance', href: '#' }] } });

const { t } = useT();

const form = useForm({
    sales_invoice_id: props.invoiceId ? String(props.invoiceId) : '',
    credit_date: props.today,
    kind: 'DISCOUNT',
    settlement: 'CREDIT',
    cash_bank_account_id: props.accounts[0] ? String(props.accounts[0].id) : '',
    payment_method: 'CASH',
    amount: '',
    reason: '',
});

const isRefund = computed(() => form.settlement === 'REFUND');
const choices = computed(() => props.invoices.filter((i) => Number(isRefund.value ? i.refundable : i.outstanding) > 0));
const invoice = computed(() => choices.value.find((i) => String(i.id) === form.sales_invoice_id) ?? null);

function save(): void {
    form.submit(store(), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Give a discount or allowance')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Give a discount or allowance" description="Reduces what the customer owes on one invoice, or gives money back if it is already paid. The amount is charged to Sales Discounts and Allowances." />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-2xl space-y-5 rounded-xl border p-4 sm:p-6" @submit.prevent="save">
            <div class="grid gap-2">
                <Label>{{ t('How') }}</Label>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label v-for="option in settlements" :key="option.value" class="flex items-center gap-2">
                        <input v-model="form.settlement" type="radio" name="settlement" :value="option.value" />{{ option.label }}
                    </label>
                </div>
                <InputError :message="form.errors.settlement" />
            </div>

            <div class="grid gap-2">
                <Label>{{ t('Invoice') }} <span class="text-destructive">*</span></Label>
                <SearchableSelect
                    v-model="form.sales_invoice_id"
                    :placeholder="isRefund ? t('Choose an invoice the customer has paid') : t('Choose an invoice that is still owed')"
                    :options="
                        choices.map((i) => ({
                            value: String(i.id),
                            label: `${i.invoice_number} — ${i.customer} — ${isRefund ? t('paid :amount', { amount: money(i.refundable) }) : t('owed :amount', { amount: money(i.outstanding) })}`,
                        }))
                    "
                />
                <p v-if="invoice && !isRefund" class="text-muted-foreground text-xs">{{ t('Dated :date. Still owed :amount :currency.', { date: invoice.invoice_date, amount: money(invoice.outstanding), currency }) }}</p>
                <p v-if="invoice && isRefund" class="text-muted-foreground text-xs">{{ t('Dated :date. Up to :amount :currency can be given back.', { date: invoice.invoice_date, amount: money(invoice.refundable), currency }) }}</p>
                <InputError :message="form.errors.sales_invoice_id" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="kind">{{ t('Kind') }}</Label>
                    <select id="kind" v-model="form.kind" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option v-for="kind in kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                    </select>
                    <InputError :message="form.errors.kind" />
                </div>
                <div class="grid gap-2">
                    <Label for="amount">{{ t('Amount (:currency)', { currency }) }} <span class="text-destructive">*</span></Label>
                    <Input id="amount" v-model="form.amount" inputmode="decimal" placeholder="0.00" />
                    <InputError :message="form.errors.amount" />
                </div>
                <div class="grid gap-2">
                    <Label for="credit_date">{{ t('Date') }}</Label>
                    <Input id="credit_date" v-model="form.credit_date" type="date" />
                    <InputError :message="form.errors.credit_date" />
                </div>
            </div>

            <div v-if="isRefund" class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="payment_method">{{ t('Method') }}</Label>
                    <select id="payment_method" v-model="form.payment_method" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option v-for="method in paymentMethods" :key="method.value" :value="method.value">{{ method.label }}</option>
                    </select>
                    <InputError :message="form.errors.payment_method" />
                </div>
                <div class="grid gap-2">
                    <Label for="cash_bank_account_id">{{ t('Paid from') }}</Label>
                    <select id="cash_bank_account_id" v-model="form.cash_bank_account_id" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option v-for="account in accounts" :key="account.id" :value="String(account.id)">{{ account.name }} ({{ account.kind }})</option>
                    </select>
                    <InputError :message="form.errors.cash_bank_account_id" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="reason">{{ t('Reason') }} <span class="text-destructive">*</span></Label>
                <Input id="reason" v-model="form.reason" maxlength="255" :placeholder="t('For example: copra moisture above the agreed level')" />
                <InputError :message="form.errors.reason" />
            </div>

            <InputError :message="(form.errors as Record<string, string>).status" />
            <Button type="submit" :disabled="form.processing">{{ t('Record') }}</Button>
        </form>
    </div>
</template>
