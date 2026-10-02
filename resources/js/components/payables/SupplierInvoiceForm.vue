<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    AccountOption,
    MatchableGoodsReceipt,
    OptionRef,
    StatusOption,
    SupplierInvoiceFormValues,
} from '@/types';
import { businessToday } from '@/lib/businessDate';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    suppliers: OptionRef[];
    goodsReceipts: MatchableGoodsReceipt[];
    expenseAccounts: AccountOption[];
    lineTypes: StatusOption[];
    currency: string;
    initial?: SupplierInvoiceFormValues | null;
}>();

const today = businessToday();

type Line = {
    line_type: string;
    goods_receipt_id: number | null;
    debit_account_id: number | null;
    description: string;
    amount: string;
};

function blankLine(): Line {
    return { line_type: 'GRNI_MATCH', goods_receipt_id: null, debit_account_id: null, description: '', amount: '' };
}

const form = useForm({
    supplier_id: props.initial?.supplier_id ? String(props.initial.supplier_id) : '',
    supplier_invoice_number: props.initial?.supplier_invoice_number ?? '',
    invoice_date: props.initial?.invoice_date ?? today,
    due_date: props.initial?.due_date ?? today,
    description: props.initial?.description ?? '',
    lines: (props.initial?.lines?.length
        ? props.initial.lines.map((line) => ({
              line_type: line.line_type,
              goods_receipt_id: line.goods_receipt_id,
              debit_account_id: line.debit_account_id,
              description: line.description ?? '',
              amount: line.amount,
          }))
        : [blankLine()]) as Line[],
});

function addLine(): void {
    form.lines.push(blankLine());
}

function removeLine(index: number): void {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1);
    }
}

/** Switching the line type clears whatever the other kind was pointing at. */
function onTypeChange(index: number): void {
    const line = form.lines[index];

    if (line.line_type === 'GRNI_MATCH') {
        line.debit_account_id = null;

        return;
    }

    line.goods_receipt_id = null;
}

function receiptFor(line: Line): MatchableGoodsReceipt | undefined {
    return props.goodsReceipts.find((receipt) => receipt.id === line.goods_receipt_id);
}

/** Filling in a receipt offers its remaining accrual, which is the common case. */
function onReceiptChange(index: number): void {
    const line = form.lines[index];
    const receipt = receiptFor(line);

    if (receipt && line.amount === '') {
        line.amount = receipt.remaining_amount;
    }
}

function amount(value: string): number {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

const total = computed(() => form.lines.reduce((sum, line) => sum + amount(line.amount), 0));

/** A line claiming more than the receipt has left will be refused at posting. */
function exceedsRemaining(line: Line): boolean {
    if (line.line_type !== 'GRNI_MATCH') {
        return false;
    }

    const receipt = receiptFor(line);

    return receipt !== undefined && amount(line.amount) > Number(receipt.remaining_amount);
}

function money(value: number): string {
    return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 });
}

function lineError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`lines.${index}.${field}`];
}

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            supplier_id: data.supplier_id === '' ? null : Number(data.supplier_id),
        }))
        .submit(props.method, props.action, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

const { t } = useT();
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="grid gap-2">
                <Label for="supplier_id">{{ t('Supplier') }} <span class="text-destructive">*</span></Label>
                <select id="supplier_id" v-model="form.supplier_id" required :class="selectClass">
                    <option value="" disabled>{{ t('Select a supplier…') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">
                        {{ s.code }} — {{ s.name }}
                    </option>
                </select>
                <InputError :message="form.errors.supplier_id" />
            </div>

            <div class="grid gap-2">
                <Label for="supplier_invoice_number">
                    {{ t("Supplier's invoice number") }} <span class="text-destructive">*</span>
                </Label>
                <Input id="supplier_invoice_number" v-model="form.supplier_invoice_number" required maxlength="100" />
                <InputError :message="form.errors.supplier_invoice_number" />
            </div>

            <div class="grid gap-2">
                <Label for="invoice_date">{{ t('Invoice date') }} <span class="text-destructive">*</span></Label>
                <Input id="invoice_date" v-model="form.invoice_date" type="date" required />
                <InputError :message="form.errors.invoice_date" />
            </div>

            <div class="grid gap-2">
                <Label for="due_date">{{ t('Due date') }} <span class="text-destructive">*</span></Label>
                <Input id="due_date" v-model="form.due_date" type="date" required />
                <InputError :message="form.errors.due_date" />
            </div>

            <div class="grid gap-2 sm:col-span-2 lg:col-span-4">
                <Label for="description">{{ t('Description') }}</Label>
                <Input id="description" v-model="form.description" maxlength="500" />
                <InputError :message="form.errors.description" />
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-medium">{{ t('Lines') }}</h3>
                <Button type="button" variant="outline" size="sm" @click="addLine">
                    <Plus class="size-4" />
                    {{ t('Add line') }}
                </Button>
            </div>

            <div class="space-y-3">
                <div
                    v-for="(line, index) in form.lines"
                    :key="index"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="grid gap-2">
                            <Label :for="`type-${index}`">{{ t('Type') }}</Label>
                            <select
                                :id="`type-${index}`"
                                v-model="line.line_type"
                                :class="selectClass"
                                @change="onTypeChange(index)"
                            >
                                <option v-for="lineType in lineTypes" :key="lineType.value" :value="lineType.value">{{ lineType.label }}</option>
                            </select>
                        </div>

                        <template v-if="line.line_type === 'GRNI_MATCH'">
                            <div class="grid gap-2 lg:col-span-2">
                                <Label :for="`grn-${index}`">{{ t('Goods receipt') }}</Label>
                                <select
                                    :id="`grn-${index}`"
                                    v-model.number="line.goods_receipt_id"
                                    required
                                    :class="selectClass"
                                    @change="onReceiptChange(index)"
                                >
                                    <option :value="null" disabled>{{ t('Select a receipt…') }}</option>
                                    <option v-for="r in goodsReceipts" :key="r.id" :value="r.id">
                                        {{ t(':number — :amount uninvoiced', { number: r.grn_number, amount: r.remaining_amount }) }}
                                    </option>
                                </select>
                                <InputError :message="lineError(index, 'goods_receipt_id')" />
                            </div>
                        </template>

                        <template v-else>
                            <div class="grid gap-2">
                                <Label :for="`acct-${index}`">{{ t('Expense account') }}</Label>
                                <select
                                    :id="`acct-${index}`"
                                    v-model.number="line.debit_account_id"
                                    required
                                    :class="selectClass"
                                >
                                    <option :value="null" disabled>{{ t('Select an account…') }}</option>
                                    <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">
                                        {{ a.code }} — {{ a.name }}
                                    </option>
                                </select>
                                <InputError :message="lineError(index, 'debit_account_id')" />
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`desc-${index}`">{{ t('Description') }}</Label>
                                <Input :id="`desc-${index}`" v-model="line.description" maxlength="500" />
                            </div>
                        </template>

                        <div class="grid gap-2">
                            <Label :for="`amount-${index}`">{{ t('Amount (:currency)', { currency }) }}</Label>
                            <div class="flex gap-2">
                                <Input
                                    :id="`amount-${index}`"
                                    v-model="line.amount"
                                    type="number"
                                    step="0.000001"
                                    min="0"
                                    required
                                    class="text-right"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="form.lines.length <= 1"
                                    @click="removeLine(index)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                            <InputError :message="lineError(index, 'amount')" />
                        </div>
                    </div>

                    <div
                        v-if="line.line_type === 'GRNI_MATCH' && receiptFor(line)"
                        class="text-muted-foreground mt-3 grid gap-2 text-xs sm:grid-cols-3"
                    >
                        <div>
                            <span class="font-medium">{{ t('Original accrual') }}</span>
                            <span class="block font-mono">{{ receiptFor(line)?.original_accrual }}</span>
                        </div>
                        <div>
                            <span class="font-medium">{{ t('Already invoiced') }}</span>
                            <span class="block font-mono">{{ receiptFor(line)?.matched_amount }}</span>
                        </div>
                        <div>
                            <span class="font-medium">{{ t('Remaining') }}</span>
                            <span class="block font-mono">{{ receiptFor(line)?.remaining_amount }}</span>
                        </div>
                    </div>

                    <p v-if="exceedsRemaining(line)" class="text-destructive mt-2 text-xs">
                        {{ t('This is more than the receipt has left uninvoiced, and will be refused when you post.') }}
                    </p>
                </div>
            </div>

            <InputError :message="form.errors.lines" />

            <div class="border-sidebar-border/70 dark:border-sidebar-border flex items-center justify-between rounded-xl border p-4">
                <span class="text-sm font-medium">{{ t('Invoice total (:currency)', { currency }) }}</span>
                <span class="font-mono text-lg font-medium">{{ money(total) }}</span>
            </div>

            <p class="text-muted-foreground text-xs">
                {{ t("The credit side is Trade Payables, taken from Accounting Setup — it is never typed here. A goods-receipt line clears part of that receipt's accrual; a direct expense line charges the account you choose.") }}
            </p>
        </section>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="form.processing">{{ t(submitLabel) }}</Button>
            <Button variant="outline" as-child><a :href="cancelHref">{{ t('Cancel') }}</a></Button>
        </div>
    </form>
</template>
