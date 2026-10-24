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
    invoices: { id: number; invoice_number: string; invoice_date: string; customer: string | null; outstanding: string }[];
    invoiceId: number | null;
    kinds: { value: string; label: string }[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Credit notes', href: index() }, { title: 'Give a discount or allowance', href: '#' }] } });

const { t } = useT();

const form = useForm({
    sales_invoice_id: props.invoiceId ? String(props.invoiceId) : '',
    credit_date: props.today,
    kind: 'DISCOUNT',
    amount: '',
    reason: '',
});

const invoice = computed(() => props.invoices.find((i) => String(i.id) === form.sales_invoice_id) ?? null);

function save(): void {
    form.submit(store(), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Give a discount or allowance')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Give a discount or allowance" description="Reduces what the customer owes on one invoice. The amount is charged to Sales Discounts and Allowances." />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-2xl space-y-5 rounded-xl border p-4 sm:p-6" @submit.prevent="save">
            <div class="grid gap-2">
                <Label>{{ t('Invoice') }} <span class="text-destructive">*</span></Label>
                <SearchableSelect
                    v-model="form.sales_invoice_id"
                    :placeholder="t('Choose an invoice that is still owed')"
                    :options="invoices.map((i) => ({ value: String(i.id), label: `${i.invoice_number} — ${i.customer} — ${t('owed :amount', { amount: money(i.outstanding) })}` }))"
                />
                <p v-if="invoice" class="text-muted-foreground text-xs">{{ t('Dated :date. Still owed :amount :currency.', { date: invoice.invoice_date, amount: money(invoice.outstanding), currency }) }}</p>
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
