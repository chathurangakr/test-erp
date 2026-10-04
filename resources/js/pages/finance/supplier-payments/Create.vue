<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/supplier-payments';
import PaymentMethodFields from '@/components/finance/PaymentMethodFields.vue';
import type { AccountOption, OptionRef, PaymentMethodOption, OutstandingInvoice } from '@/types';
import { businessToday } from '@/lib/businessDate';
import { useT } from '@/lib/i18n';
import { money, moneyInput } from '@/lib/money';

const props = defineProps<{
    suppliers: OptionRef[];
    paymentAccounts: AccountOption[];
    paymentMethods: PaymentMethodOption[];
    invoiceId: number | null;
    supplierId: number | null;
    outstandingInvoices: OutstandingInvoice[];
    currency: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Supplier payments', href: index() }, { title: 'New', href: index() }] },
});

const today = businessToday();
const supplierId = ref(props.supplierId ? String(props.supplierId) : '');

/** Choosing a supplier reloads the page so its outstanding invoices can be listed. */
watch(supplierId, (value) => {
    router.get(create().url, { supplier_id: value || undefined }, { preserveState: false, replace: true });
});

const form = useForm({
    supplier_id: props.supplierId,
    payment_date: today,
    payment_account_id: '' as string | number,
    payment_method: '',
    cheque_number: '',
    cheque_date: '',
    cheque_bank: '',
    reference: '',
    notes: '',
    amount: '',
    allocations: props.outstandingInvoices.map((invoice) => ({
        supplier_invoice_id: invoice.id,
        // "Record payment" from an invoice pre-fills that invoice with what it still owes.
        amount: invoice.id === props.invoiceId ? moneyInput(invoice.outstanding) : '',
    })),
});

function amountOf(value: string): number {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

if (props.invoiceId !== null) {
    form.amount = moneyInput(props.outstandingInvoices.find((invoice) => invoice.id === props.invoiceId)?.outstanding);
}

/** Cash goes through a till; a cheque or a transfer through a bank account. */
const methodKind = computed(
    () => props.paymentMethods.find((m) => m.value === form.payment_method)?.account_kind ?? null,
);

const accountChoices = computed(() =>
    methodKind.value === null
        ? props.paymentAccounts
        : props.paymentAccounts.filter((a) => a.account_kind === methodKind.value),
);

watch(methodKind, () => {
    if (!accountChoices.value.some((a) => a.id === form.payment_account_id)) {
        form.payment_account_id = accountChoices.value.length === 1 ? accountChoices.value[0].id : '';
    }
});

const allocatedTotal = computed(() =>
    form.allocations.reduce((sum, allocation) => sum + amountOf(allocation.amount), 0),
);

const paymentTotal = computed(() => amountOf(form.amount));
const difference = computed(() => paymentTotal.value - allocatedTotal.value);
const canPost = computed(() => paymentTotal.value > 0 && Math.abs(difference.value) < 0.0000005);

/** Fill an allocation with whatever that invoice still owes. */
function payInFull(index: number): void {
    form.allocations[index].amount = moneyInput(props.outstandingInvoices[index].outstanding);
}

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            supplier_id: supplierId.value === '' ? null : Number(supplierId.value),
            cheque_number: data.payment_method === 'CHEQUE' ? data.cheque_number : null,
            cheque_date: data.payment_method === 'CHEQUE' ? data.cheque_date : null,
            cheque_bank: data.payment_method === 'CHEQUE' ? data.cheque_bank : null,
            // Only the invoices actually being paid are sent.
            allocations: data.allocations.filter((allocation) => amountOf(allocation.amount) > 0),
        }))
        .post(index().url, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const { t } = useT();
</script>

<template>
    <Head :title="t('New supplier payment')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New supplier payment"
            description="Saved as a draft. Nothing leaves the bank until you post it."
        />

        <form class="max-w-6xl space-y-8" @submit.prevent="submit">
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="supplier">{{ t('Supplier') }} <span class="text-destructive">*</span></Label>
                    <select id="supplier" v-model="supplierId" required :class="selectClass">
                        <option value="" disabled>{{ t('Select a supplier…') }}</option>
                        <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">
                            {{ s.code }} — {{ s.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.supplier_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="payment_date">{{ t('Payment date') }} <span class="text-destructive">*</span></Label>
                    <Input id="payment_date" v-model="form.payment_date" type="date" required />
                    <InputError :message="form.errors.payment_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="payment_account_id">{{ t('Paid from') }} <span class="text-destructive">*</span></Label>
                    <select id="payment_account_id" v-model="form.payment_account_id" required :class="selectClass">
                        <option value="" disabled>{{ t('Cash or bank…') }}</option>
                        <option v-for="a in accountChoices" :key="a.id" :value="a.id">
                            {{ a.code }} — {{ a.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.payment_account_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="reference">{{ t('Reference') }}</Label>
                    <Input id="reference" v-model="form.reference" maxlength="100" :placeholder="t('Cheque or transfer no')" />
                    <InputError :message="form.errors.reference" />
                </div>

                <div class="grid gap-2">
                    <Label for="amount">{{ t('Payment amount (:currency)', { currency }) }} <span class="text-destructive">*</span></Label>
                    <Input id="amount" v-model="form.amount" type="number" step="0.01" min="0" required class="text-right" />
                    <InputError :message="form.errors.amount" />
                </div>

                <div class="sm:col-span-2 lg:col-span-4">
                    <PaymentMethodFields
                        v-model:method="form.payment_method"
                        v-model:cheque-number="form.cheque_number"
                        v-model:cheque-date="form.cheque_date"
                        v-model:cheque-bank="form.cheque_bank"
                        :methods="paymentMethods"
                        :errors="form.errors"
                    />
                </div>

                <div class="grid gap-2 sm:col-span-2 lg:col-span-3">
                    <Label for="notes">{{ t('Notes') }}</Label>
                    <Input id="notes" v-model="form.notes" maxlength="500" />
                </div>
            </section>

            <section v-if="supplierId" class="space-y-4">
                <h3 class="text-sm font-medium">{{ t('Outstanding invoices') }}</h3>

                <div
                    v-if="outstandingInvoices.length === 0"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-8 text-center text-sm"
                >
                    {{ t('This supplier has nothing outstanding.') }}
                </div>

                <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">{{ t('Invoice') }}</th>
                                <th class="px-4 py-3 font-medium">{{ t('Due') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ t('Total') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ t('Paid') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ t('Outstanding') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ t('Allocate') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(invoice, index) in outstandingInvoices"
                                :key="invoice.id"
                                class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                            >
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs font-medium">{{ invoice.internal_invoice_number }}</span>
                                    <span class="text-muted-foreground block text-xs">
                                        {{ invoice.supplier_invoice_number }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ invoice.due_date }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ money(invoice.total) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ money(invoice.paid) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ money(invoice.outstanding) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <Input
                                            v-model="form.allocations[index].amount"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="text-right"
                                        />
                                        <Button type="button" variant="ghost" size="sm" @click="payInFull(index)">
                                            {{ t('All') }}
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <InputError :message="form.errors.allocations" />

                <div class="border-sidebar-border/70 dark:border-sidebar-border grid gap-3 rounded-xl border p-4 sm:grid-cols-3">
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">{{ t('Payment total') }}</p>
                        <p class="mt-1 font-mono text-lg">{{ money(paymentTotal) }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">{{ t('Allocated') }}</p>
                        <p class="mt-1 font-mono text-lg">{{ money(allocatedTotal) }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">{{ t('Difference') }}</p>
                        <p
                            class="mt-1 font-mono text-lg font-medium"
                            :class="canPost ? 'text-muted-foreground' : 'text-destructive'"
                        >
                            {{ money(difference) }}
                        </p>
                    </div>
                </div>

                <p v-if="!canPost" class="text-destructive text-xs">
                    {{ t('Every rupee must be allocated to an invoice before this payment can be posted. Payments on account are not supported.') }}
                </p>
            </section>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Button type="submit" :disabled="form.processing || !supplierId">{{ t('Save draft') }}</Button>
                <Button variant="outline" as-child><a :href="index().url">{{ t('Cancel') }}</a></Button>
            </div>
        </form>
    </div>
</template>
