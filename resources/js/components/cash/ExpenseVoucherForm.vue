<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import type { CashBankAccountOption, OptionRef } from '@/types';
import { businessToday } from '@/lib/businessDate';
import { useT } from '@/lib/i18n';
import { money, moneyInput } from '@/lib/money';

type VoucherLine = {
    expense_account_id: number | string;
    description: string | null;
    amount: string;
};

const props = defineProps<{
    accounts: CashBankAccountOption[];
    expenseAccounts: { id: number; code: string; name: string }[];
    suppliers: OptionRef[];
    currency: string;
    submitUrl: string;
    method: 'post' | 'put';
    cancelUrl: string;
    submitLabel: string;
    initial?: {
        voucher_date: string | null;
        cash_bank_account_id: number | null;
        supplier_id: number | null;
        payee_name: string | null;
        reference: string | null;
        description: string | null;
        lines: VoucherLine[];
    };
}>();

const { t } = useT();

const today = businessToday();

const form = useForm({
    voucher_date: props.initial?.voucher_date ?? today,
    cash_bank_account_id: (props.initial?.cash_bank_account_id ?? '') as string | number,
    supplier_id: (props.initial?.supplier_id ?? '') as string | number,
    payee_name: props.initial?.payee_name ?? '',
    reference: props.initial?.reference ?? '',
    description: props.initial?.description ?? '',
    lines:
        props.initial?.lines?.length
            ? props.initial.lines.map((line) => ({
                  expense_account_id: line.expense_account_id,
                  description: line.description ?? '',
                  amount: moneyInput(line.amount),
              }))
            : [{ expense_account_id: '' as string | number, description: '', amount: '' }],
});

function amountOf(value: string): number {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

const total = computed(() => form.lines.reduce((sum, line) => sum + amountOf(line.amount), 0));

function addLine(): void {
    form.lines.push({ expense_account_id: '', description: '', amount: '' });
}

function removeLine(index: number): void {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1);
    }
}


function submit(): void {
    form.transform((data) => ({
        ...data,
        supplier_id: data.supplier_id === '' ? null : Number(data.supplier_id),
    }))[props.method](props.submitUrl, { preserveScroll: true });
}

</script>

<template>
    <form class="max-w-6xl space-y-8" @submit.prevent="submit">
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="grid gap-2">
                <Label for="voucher_date">{{ t('Date') }} <span class="text-destructive">*</span></Label>
                <Input id="voucher_date" v-model="form.voucher_date" type="date" required />
                <InputError :message="form.errors.voucher_date" />
            </div>

            <div class="grid gap-2">
                <Label for="cash_bank_account_id">{{ t('Paid from') }} <span class="text-destructive">*</span></Label>
                <SearchableSelect
                    id="cash_bank_account_id"
                    v-model="form.cash_bank_account_id"
                    required
                    :options="[...(accounts).map((a) => ({ value: a.id, label: `${a.code} — ${a.name} (${a.kind})` }))]"
                    :placeholder="t('Cash or bank…')"
                />
                <InputError :message="form.errors.cash_bank_account_id" />
            </div>

            <div class="grid gap-2">
                <Label for="reference">{{ t('Reference') }}</Label>
                <Input id="reference" v-model="form.reference" maxlength="100" :placeholder="t('Cheque or bill no')" />
                <InputError :message="form.errors.reference" />
            </div>

            <div class="grid gap-2">
                <Label for="supplier_id">{{ t('Supplier (optional)') }}</Label>
                <SearchableSelect
                    id="supplier_id"
                    v-model="form.supplier_id"
                    :options="[{ value: '', label: t('None') }, ...(suppliers).map((s) => ({ value: s.id, label: `${s.code} — ${s.name}` }))]"
                />
                <InputError :message="form.errors.supplier_id" />
                <p class="text-muted-foreground text-xs">{{ t('Recorded for reporting. This raises no payable.') }}</p>
            </div>

            <div class="grid gap-2">
                <Label for="payee_name">{{ t('Payee (if not a supplier)') }}</Label>
                <Input id="payee_name" v-model="form.payee_name" maxlength="150" />
                <InputError :message="form.errors.payee_name" />
            </div>

            <div class="grid gap-2">
                <Label for="description">{{ t('Description') }}</Label>
                <Input id="description" v-model="form.description" maxlength="500" />
                <InputError :message="form.errors.description" />
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium">{{ t('Expenses') }}</h3>
                <Button type="button" variant="outline" size="sm" @click="addLine">
                    <Plus class="size-4" />
                    {{ t('Add line') }}
                </Button>
            </div>

            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Expense account') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Description') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Amount') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(line, index) in form.lines"
                            :key="index"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                        >
                            <td class="px-4 py-3">
                                <SearchableSelect
                                    v-model="line.expense_account_id"
                                    required
                                    :options="[...(expenseAccounts).map((a) => ({ value: a.id, label: `${a.code} — ${a.name}` }))]"
                                    :placeholder="t('Select…')"
                                />
                                <InputError :message="form.errors[`lines.${index}.expense_account_id`]" />
                            </td>
                            <td class="px-4 py-3">
                                <Input v-model="line.description" maxlength="255" />
                            </td>
                            <td class="px-4 py-3">
                                <Input
                                    v-model="line.amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required
                                    class="text-right"
                                />
                                <InputError :message="form.errors[`lines.${index}.amount`]" />
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="form.lines.length === 1"
                                    @click="removeLine(index)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <InputError :message="form.errors.lines" />

            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(total) }}</p>
                <p class="text-muted-foreground mt-2 text-xs">
                    {{ t('This is what will be credited to the cash or bank account when the voucher is posted. Cost of sales cannot be charged here — it is derived from stock movements.') }}
                </p>
            </div>
        </section>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="form.processing">{{ t(submitLabel) }}</Button>
            <Button variant="outline" as-child><a :href="cancelUrl">{{ t('Cancel') }}</a></Button>
        </div>
    </form>
</template>
