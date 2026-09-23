<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BillableDeliveryLine, OptionRef, SalesInvoiceFormValues } from '@/types';

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    reloadUrl: string;
    submitLabel: string;
    cancelHref: string;
    customers: (OptionRef & { credit_days?: number })[];
    billableLines: BillableDeliveryLine[];
    creditDays: number;
    customerId: number | null;
    currency: string;
    initial?: SalesInvoiceFormValues | null;
}>();

const today = new Date().toISOString().slice(0, 10);
const customerId = ref(props.customerId ? String(props.customerId) : '');

/** Choosing a customer reloads the page so its unbilled delivered lines can be listed. */
watch(customerId, (value) => {
    router.get(props.reloadUrl, { customer_id: value || undefined }, { preserveState: false, replace: true });
});

/** The customer's agreed terms suggest a due date; it stays editable. */
function defaultDueDate(invoiceDate: string): string {
    const date = new Date(invoiceDate);
    date.setDate(date.getDate() + (props.creditDays ?? 0));

    return date.toISOString().slice(0, 10);
}

const form = useForm({
    customer_id: props.customerId,
    invoice_date: props.initial?.invoice_date ?? today,
    due_date: props.initial?.due_date ?? defaultDueDate(props.initial?.invoice_date ?? today),
    customer_reference: props.initial?.customer_reference ?? '',
    description: props.initial?.description ?? '',
    // One row per billable delivered line, pre-filled from the draft being edited.
    lines: props.billableLines.map((line) => ({
        delivery_line_id: line.delivery_line_id,
        description: '',
        amount:
            props.initial?.lines?.find((l) => l.delivery_line_id === line.delivery_line_id)?.amount ?? '',
    })),
});

// Changing the invoice date re-suggests the due date, until someone edits it themselves.
const dueDateTouched = ref(props.initial?.due_date != null);

watch(
    () => form.invoice_date,
    (value) => {
        if (! dueDateTouched.value && value) {
            form.due_date = defaultDueDate(value);
        }
    },
);

function amountOf(value: string): number {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

const total = computed(() => form.lines.reduce((sum, line) => sum + amountOf(line.amount), 0));

/** Bill everything still outstanding on that delivered line. */
function billInFull(index: number): void {
    form.lines[index].amount = props.billableLines[index].remaining_amount;
}

function exceedsRemaining(index: number): boolean {
    return amountOf(form.lines[index].amount) > Number(props.billableLines[index].remaining_amount);
}

function money(value: number): string {
    return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 });
}

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            customer_id: customerId.value === '' ? null : Number(customerId.value),
            // Only lines actually being billed are sent.
            lines: data.lines.filter((line) => amountOf(line.amount) > 0),
        }))
        .submit(props.method, props.action, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="grid gap-2">
                <Label for="customer_id">Customer <span class="text-destructive">*</span></Label>
                <select id="customer_id" v-model="customerId" required :class="selectClass">
                    <option value="" disabled>Select a customer…</option>
                    <option v-for="c in customers" :key="c.id" :value="String(c.id)">
                        {{ c.code }} — {{ c.name }}
                    </option>
                </select>
                <InputError :message="form.errors.customer_id" />
            </div>

            <div class="grid gap-2">
                <Label for="invoice_date">Invoice date <span class="text-destructive">*</span></Label>
                <Input id="invoice_date" v-model="form.invoice_date" type="date" required />
                <InputError :message="form.errors.invoice_date" />
            </div>

            <div class="grid gap-2">
                <Label for="due_date">Due date <span class="text-destructive">*</span></Label>
                <Input id="due_date" v-model="form.due_date" type="date" required @input="dueDateTouched = true" />
                <p v-if="creditDays > 0 && !dueDateTouched" class="text-muted-foreground text-xs">
                    {{ creditDays }} days from the invoice date, per this customer's terms.
                </p>
                <InputError :message="form.errors.due_date" />
            </div>

            <div class="grid gap-2">
                <Label for="customer_reference">Customer reference</Label>
                <Input id="customer_reference" v-model="form.customer_reference" maxlength="100" />
                <InputError :message="form.errors.customer_reference" />
            </div>

            <div class="grid gap-2 sm:col-span-2 lg:col-span-4">
                <Label for="description">Description</Label>
                <Input id="description" v-model="form.description" maxlength="500" />
                <InputError :message="form.errors.description" />
            </div>
        </section>

        <section v-if="customerId" class="space-y-4">
            <h3 class="text-sm font-medium">Delivered but not yet invoiced</h3>

            <div
                v-if="billableLines.length === 0"
                class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-8 text-center text-sm"
            >
                This customer has nothing left to invoice. A delivery becomes billable once it is
                posted and its cost of sales has reached the ledger.
            </div>

            <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Delivery</th>
                            <th class="px-4 py-3 font-medium">Product</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 text-right font-medium">Delivered value</th>
                            <th class="px-4 py-3 text-right font-medium">Already invoiced</th>
                            <th class="px-4 py-3 text-right font-medium">Remaining</th>
                            <th class="px-4 py-3 text-right font-medium">Invoice now</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(line, index) in billableLines"
                            :key="line.delivery_line_id"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-medium">{{ line.delivery_number }}</td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ line.product_code }}</span>
                                <span class="text-muted-foreground block text-xs">{{ line.product_name }}</span>
                            </td>
                            <td class="px-4 py-3">{{ line.delivery_date }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ line.original_value }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ line.billed_amount }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ line.remaining_amount }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Input
                                        v-model="form.lines[index].amount"
                                        type="number"
                                        step="0.000001"
                                        min="0"
                                        class="text-right"
                                    />
                                    <Button type="button" variant="ghost" size="sm" @click="billInFull(index)">
                                        All
                                    </Button>
                                </div>
                                <InputError
                                    :message="(form.errors as Record<string, string | undefined>)[`lines.${index}.amount`]"
                                />
                                <p v-if="exceedsRemaining(index)" class="text-destructive mt-1 text-xs">
                                    More than this line has left to invoice.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <InputError :message="form.errors.lines" />

            <div class="border-sidebar-border/70 dark:border-sidebar-border flex items-center justify-between rounded-xl border p-4">
                <span class="text-sm font-medium">Invoice total ({{ currency }})</span>
                <span class="font-mono text-lg font-medium">{{ money(total) }}</span>
            </div>

            <p class="text-muted-foreground text-xs">
                Each line bills part of what was delivered, at the value the delivery already
                recorded — nothing is repriced here. The revenue account comes from the product's
                mapping, so there is no account to choose. Billing less than the remaining amount is
                fine; the rest stays available for a later invoice.
            </p>
        </section>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="form.processing || !customerId">{{ submitLabel }}</Button>
            <Button variant="outline" as-child><a :href="cancelHref">Cancel</a></Button>
        </div>
    </form>
</template>
