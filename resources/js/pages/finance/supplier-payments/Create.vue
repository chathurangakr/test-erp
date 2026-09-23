<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/supplier-payments';
import type { AccountOption, OptionRef, OutstandingInvoice } from '@/types';

const props = defineProps<{
    suppliers: OptionRef[];
    paymentAccounts: AccountOption[];
    supplierId: number | null;
    outstandingInvoices: OutstandingInvoice[];
    currency: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Supplier payments', href: index() }, { title: 'New', href: index() }] },
});

const today = new Date().toISOString().slice(0, 10);
const supplierId = ref(props.supplierId ? String(props.supplierId) : '');

/** Choosing a supplier reloads the page so its outstanding invoices can be listed. */
watch(supplierId, (value) => {
    router.get(create().url, { supplier_id: value || undefined }, { preserveState: false, replace: true });
});

const form = useForm({
    supplier_id: props.supplierId,
    payment_date: today,
    payment_account_id: '' as string | number,
    reference: '',
    notes: '',
    amount: '',
    allocations: props.outstandingInvoices.map((invoice) => ({
        supplier_invoice_id: invoice.id,
        amount: '',
    })),
});

function amountOf(value: string): number {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

const allocatedTotal = computed(() =>
    form.allocations.reduce((sum, allocation) => sum + amountOf(allocation.amount), 0),
);

const paymentTotal = computed(() => amountOf(form.amount));
const difference = computed(() => paymentTotal.value - allocatedTotal.value);
const canPost = computed(() => paymentTotal.value > 0 && Math.abs(difference.value) < 0.0000005);

/** Fill an allocation with whatever that invoice still owes. */
function payInFull(index: number): void {
    form.allocations[index].amount = props.outstandingInvoices[index].outstanding;
}

function money(value: number): string {
    return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 });
}

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            supplier_id: supplierId.value === '' ? null : Number(supplierId.value),
            // Only the invoices actually being paid are sent.
            allocations: data.allocations.filter((allocation) => amountOf(allocation.amount) > 0),
        }))
        .post(index().url, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="New supplier payment" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New supplier payment"
            description="Saved as a draft. Nothing leaves the bank until you post it."
        />

        <form class="max-w-6xl space-y-8" @submit.prevent="submit">
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="supplier">Supplier <span class="text-destructive">*</span></Label>
                    <select id="supplier" v-model="supplierId" required :class="selectClass">
                        <option value="" disabled>Select a supplier…</option>
                        <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">
                            {{ s.code }} — {{ s.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.supplier_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="payment_date">Payment date <span class="text-destructive">*</span></Label>
                    <Input id="payment_date" v-model="form.payment_date" type="date" required />
                    <InputError :message="form.errors.payment_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="payment_account_id">Paid from <span class="text-destructive">*</span></Label>
                    <select id="payment_account_id" v-model="form.payment_account_id" required :class="selectClass">
                        <option value="" disabled>Cash or bank…</option>
                        <option v-for="a in paymentAccounts" :key="a.id" :value="a.id">
                            {{ a.code }} — {{ a.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.payment_account_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="reference">Reference</Label>
                    <Input id="reference" v-model="form.reference" maxlength="100" placeholder="Cheque or transfer no" />
                    <InputError :message="form.errors.reference" />
                </div>

                <div class="grid gap-2">
                    <Label for="amount">Payment amount ({{ currency }}) <span class="text-destructive">*</span></Label>
                    <Input id="amount" v-model="form.amount" type="number" step="0.000001" min="0" required class="text-right" />
                    <InputError :message="form.errors.amount" />
                </div>

                <div class="grid gap-2 sm:col-span-2 lg:col-span-3">
                    <Label for="notes">Notes</Label>
                    <Input id="notes" v-model="form.notes" maxlength="500" />
                </div>
            </section>

            <section v-if="supplierId" class="space-y-4">
                <h3 class="text-sm font-medium">Outstanding invoices</h3>

                <div
                    v-if="outstandingInvoices.length === 0"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-8 text-center text-sm"
                >
                    This supplier has nothing outstanding.
                </div>

                <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">Invoice</th>
                                <th class="px-4 py-3 font-medium">Due</th>
                                <th class="px-4 py-3 text-right font-medium">Total</th>
                                <th class="px-4 py-3 text-right font-medium">Paid</th>
                                <th class="px-4 py-3 text-right font-medium">Outstanding</th>
                                <th class="px-4 py-3 text-right font-medium">Allocate</th>
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
                                <td class="px-4 py-3 text-right font-mono">{{ invoice.total }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ invoice.paid }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ invoice.outstanding }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <Input
                                            v-model="form.allocations[index].amount"
                                            type="number"
                                            step="0.000001"
                                            min="0"
                                            class="text-right"
                                        />
                                        <Button type="button" variant="ghost" size="sm" @click="payInFull(index)">
                                            All
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
                        <p class="text-muted-foreground text-xs font-medium">Payment total</p>
                        <p class="mt-1 font-mono text-lg">{{ money(paymentTotal) }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">Allocated</p>
                        <p class="mt-1 font-mono text-lg">{{ money(allocatedTotal) }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs font-medium">Difference</p>
                        <p
                            class="mt-1 font-mono text-lg font-medium"
                            :class="canPost ? 'text-muted-foreground' : 'text-destructive'"
                        >
                            {{ money(difference) }}
                        </p>
                    </div>
                </div>

                <p v-if="!canPost" class="text-destructive text-xs">
                    Every rupee must be allocated to an invoice before this payment can be posted.
                    Payments on account are not supported.
                </p>
            </section>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Button type="submit" :disabled="form.processing || !supplierId">Save draft</Button>
                <Button variant="outline" as-child><a :href="index().url">Cancel</a></Button>
            </div>
        </form>
    </div>
</template>
