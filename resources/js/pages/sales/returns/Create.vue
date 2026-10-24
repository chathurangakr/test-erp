<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/sales-returns';
import type { CashBankAccountOption, PaymentMethodOption, ReturnableLine } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

type Settlement = 'CREDIT' | 'EXCHANGE' | 'REFUND';

const props = defineProps<{
    lines: ReturnableLine[];
    deliveryLineId: number | null;
    invoices: { id: number; invoice_number: string; customer_id: number; invoice_date: string; outstanding: string }[];
    settlements: { value: Settlement; label: string }[];
    warehouses: { id: number; code: string; name: string; is_default: boolean }[];
    accounts: CashBankAccountOption[];
    paymentMethods: PaymentMethodOption[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Returns', href: index() }, { title: 'Record a return' }] } });

const { t } = useT();

const preset = props.lines.find((l) => l.id === props.deliveryLineId);
const customerId = ref<number | null>(preset?.customer_id ?? null);

const customers = computed(() => {
    const seen = new Map<number, string>();
    props.lines.forEach((l) => seen.set(l.customer_id, `${l.customer_code ?? ''} — ${l.customer_name ?? ''}`));

    return [...seen.entries()].map(([value, label]) => ({ value, label }));
});

const form = useForm({
    return_date: props.today,
    delivery_line_id: preset?.id ?? (null as number | null),
    good_quantity: '',
    damaged_quantity: '',
    secondary_quantity: '',
    warehouse_id: null as number | null,
    settlement: 'CREDIT' as Settlement,
    credit_invoice_id: null as number | null,
    cash_bank_account_id: null as number | null,
    payment_method: 'CASH',
    reference: '',
    reason: '',
});

const customerLines = computed(() => props.lines.filter((l) => l.customer_id === customerId.value));
const line = computed(() => props.lines.find((l) => l.id === form.delivery_line_id) ?? null);
const customerInvoices = computed(() => props.invoices.filter((i) => i.customer_id === customerId.value));

/** The store the good goods go back to: the one they left from, when it is still a store. */
watch(line, (chosen) => {
    form.warehouse_id = props.warehouses.some((w) => w.id === chosen?.warehouse_id)
        ? chosen!.warehouse_id
        : ((props.warehouses.find((w) => w.is_default) ?? props.warehouses[0])?.id ?? null);
    form.credit_invoice_id = form.settlement === 'CREDIT' ? (chosen?.invoice?.id ?? null) : null;
}, { immediate: true });

watch(() => form.settlement, (settlement) => {
    form.credit_invoice_id = settlement === 'CREDIT' ? (line.value?.invoice?.id ?? null) : null;
});

watch(customerId, () => {
    if (line.value && line.value.customer_id !== customerId.value) {
        form.delivery_line_id = null;
    }
});

const total = computed(() => (Number(form.good_quantity) || 0) + (Number(form.damaged_quantity) || 0));
const credit = computed(() => {
    if (!line.value) return 0;
    const priced = line.value.priced_by_weight ? Number(form.secondary_quantity) || 0 : total.value;

    return Math.round(priced * Number(line.value.unit_price) * 100) / 100;
});
const tooMany = computed(() => line.value !== null && total.value > Number(line.value.returnable));

function submit(): void {
    form.transform((data) => {
        const payload: Record<string, unknown> = {
            return_date: data.return_date,
            delivery_line_id: data.delivery_line_id,
            good_quantity: data.good_quantity || '0',
            damaged_quantity: data.damaged_quantity || '0',
            secondary_quantity: line.value?.priced_by_weight ? data.secondary_quantity : null,
            warehouse_id: Number(data.good_quantity) > 0 ? data.warehouse_id : null,
            settlement: data.settlement,
            reference: data.reference,
            reason: data.reason,
        };

        if (data.settlement === 'REFUND') {
            payload.cash_bank_account_id = data.cash_bank_account_id;
            payload.payment_method = data.payment_method;
        } else {
            payload.credit_invoice_id = data.credit_invoice_id;
        }

        return payload;
    }).submit(store());
}
</script>

<template>
    <Head :title="t('Record a return')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Record a return" description="Choose the delivery the goods came from. The customer is credited at the price charged on it." />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-4xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="customer">{{ t('Customer') }}</Label>
                    <SearchableSelect id="customer" v-model="customerId" required :options="customers" :placeholder="t('Select a customer…')" />
                </div>
                <div class="grid gap-2">
                    <Label for="return_date">{{ t('Date') }}</Label>
                    <Input id="return_date" v-model="form.return_date" type="date" required />
                    <InputError :message="form.errors.return_date" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="delivery_line_id">{{ t('Delivery the goods came from') }}</Label>
                    <SearchableSelect
                        id="delivery_line_id"
                        v-model="form.delivery_line_id"
                        required
                        :disabled="customerId === null"
                        :options="customerLines.map((l) => ({ value: l.id, label: `${l.delivery_number} · ${l.delivery_date} · ${l.product_name} — ${t(':left can come back', { left: Number(l.returnable) })}` }))"
                        :placeholder="customerId === null ? t('Choose a customer first') : t('Select a delivery…')"
                    />
                    <p v-if="customerId !== null && customerLines.length === 0" class="text-muted-foreground text-xs">{{ t('This customer has no invoiced deliveries from the last six months to return against.') }}</p>
                    <InputError :message="form.errors.delivery_line_id" />
                </div>
            </div>

            <div v-if="line" class="bg-muted/40 grid gap-2 rounded-lg p-3 text-sm sm:grid-cols-3">
                <div>
                    <span class="text-muted-foreground block text-xs">{{ t('Price charged') }}</span>
                    <span class="font-mono">{{ money(line.unit_price) }} / {{ line.priced_by_weight ? line.secondary_uom : line.uom }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground block text-xs">{{ t('Invoice') }}</span>
                    <span class="font-mono">{{ line.invoice?.invoice_number }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground block text-xs">{{ t('Paid on it / still owed') }}</span>
                    <span class="font-mono">{{ money(line.invoice?.received ?? '0') }} / {{ money(line.invoice?.outstanding ?? '0') }}</span>
                </div>
            </div>

            <div v-if="line" class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="good_quantity">{{ t('Good — back to stock (:uom)', { uom: line.uom ?? '' }) }}</Label>
                    <Input id="good_quantity" v-model="form.good_quantity" inputmode="decimal" />
                    <InputError :message="form.errors.good_quantity" />
                </div>
                <div class="grid gap-2">
                    <Label for="damaged_quantity">{{ t('Damaged — thrown away (:uom)', { uom: line.uom ?? '' }) }}</Label>
                    <Input id="damaged_quantity" v-model="form.damaged_quantity" inputmode="decimal" />
                    <InputError :message="form.errors.damaged_quantity" />
                </div>
                <div v-if="line.priced_by_weight" class="grid gap-2">
                    <Label for="secondary_quantity">{{ t('Weight returned (:uom)', { uom: line.secondary_uom ?? '' }) }}</Label>
                    <Input id="secondary_quantity" v-model="form.secondary_quantity" inputmode="decimal" required />
                    <InputError :message="form.errors.secondary_quantity" />
                </div>
                <div v-if="Number(form.good_quantity) > 0" class="grid gap-2">
                    <Label for="warehouse_id">{{ t('Back to store') }}</Label>
                    <SearchableSelect id="warehouse_id" v-model="form.warehouse_id" required :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` }))" />
                    <InputError :message="form.errors.warehouse_id" />
                </div>
                <p class="text-sm sm:col-span-3" :class="tooMany ? 'text-destructive' : 'text-muted-foreground'">
                    {{ t(':left of this delivery can still come back.', { left: Number(line.returnable) }) }}
                    <strong v-if="credit > 0" class="text-foreground ml-1">{{ t('Credit to the customer: :amount', { amount: money(credit) }) }}</strong>
                </p>
            </div>

            <div v-if="line" class="space-y-3">
                <Label>{{ t('How is the customer paid back?') }}</Label>
                <div class="grid gap-2 sm:grid-cols-3" role="radiogroup">
                    <button
                        v-for="option in settlements"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.settlement === option.value"
                        class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                        :class="form.settlement === option.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        @click="form.settlement = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <InputError :message="form.errors.settlement" />

                <div v-if="form.settlement !== 'REFUND'" class="grid gap-2 sm:max-w-md">
                    <Label for="credit_invoice_id">{{ form.settlement === 'EXCHANGE' ? t('Invoice for the goods given in exchange') : t('Invoice to reduce') }}</Label>
                    <SearchableSelect
                        id="credit_invoice_id"
                        v-model="form.credit_invoice_id"
                        required
                        :options="customerInvoices.map((i) => ({ value: i.id, label: `${i.invoice_number} · ${i.invoice_date} — ${t('owed :amount', { amount: money(i.outstanding) })}` }))"
                        :placeholder="t('Select an invoice…')"
                    />
                    <p v-if="form.settlement === 'EXCHANGE'" class="text-muted-foreground text-xs">
                        {{ t('Record the delivery and invoice for the replacement goods first (for example husk). This return then pays that invoice.') }}
                    </p>
                    <InputError :message="form.errors.credit_invoice_id" />
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-3">
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
            </div>

            <div v-if="line" class="grid gap-2">
                <Label for="reason">{{ t('Reason') }}</Label>
                <Input id="reason" v-model="form.reason" required maxlength="255" :placeholder="t('e.g. Some coconuts were spoiled')" />
                <InputError :message="form.errors.reason" />
            </div>

            <InputError :message="(form.errors as Record<string, string | undefined>).stock_loss_account_id" />
            <InputError :message="(form.errors as Record<string, string | undefined>).account_id" />

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || !line || tooMany || total <= 0">{{ t('Record return') }}</Button>
                <Button variant="outline" as-child><Link :href="index()">{{ t('Cancel') }}</Link></Button>
            </div>
        </form>
    </div>
</template>
