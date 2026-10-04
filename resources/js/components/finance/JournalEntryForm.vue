<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AccountOption, JournalFormValues } from '@/types';
import { businessToday } from '@/lib/businessDate';
import { useT } from '@/lib/i18n';
import { money, moneyInput } from '@/lib/money';

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    accounts: AccountOption[];
    openPeriods: { id: number; name: string; start_date: string; end_date: string }[];
    currency: string;
    initial?: JournalFormValues | null;
}>();

const today = businessToday();

type Line = { account_id: number | null; description: string; debit: string; credit: string };

function blankLine(): Line {
    return { account_id: null, description: '', debit: '', credit: '' };
}

const form = useForm({
    journal_date: props.initial?.journal_date ?? today,
    description: props.initial?.description ?? '',
    lines: (props.initial?.lines?.length
        ? props.initial.lines.map((line) => ({
              account_id: line.account_id,
              description: line.description ?? '',
              debit: Number(line.debit) > 0 ? moneyInput(line.debit) : '',
              credit: Number(line.credit) > 0 ? moneyInput(line.credit) : '',
          }))
        : [blankLine(), blankLine()]) as Line[],
});

function addLine(): void {
    form.lines.push(blankLine());
}

function removeLine(index: number): void {
    // Two lines is the minimum a double entry can have.
    if (form.lines.length > 2) {
        form.lines.splice(index, 1);
    }
}

/** A line is a debit or a credit, never both — typing one clears the other. */
function onDebitInput(index: number): void {
    if (Number(form.lines[index].debit) > 0) {
        form.lines[index].credit = '';
    }
}

function onCreditInput(index: number): void {
    if (Number(form.lines[index].credit) > 0) {
        form.lines[index].debit = '';
    }
}

function amount(value: string): number {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

const debitTotal = computed(() => form.lines.reduce((sum, line) => sum + amount(line.debit), 0));
const creditTotal = computed(() => form.lines.reduce((sum, line) => sum + amount(line.credit), 0));
const difference = computed(() => debitTotal.value - creditTotal.value);

/** Balanced AND non-empty: an entry of nothing is not a journal. */
const isBalanced = computed(
    () => Math.abs(difference.value) < 0.0000005 && debitTotal.value > 0,
);

const periodFor = computed(() =>
    props.openPeriods.find(
        (period) => form.journal_date >= period.start_date && form.journal_date <= period.end_date,
    ),
);


function lineError(index: number, field: 'account_id' | 'debit' | 'credit'): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`lines.${index}.${field}`];
}

function submit(): void {
    form.submit(props.method, props.action, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

const { t } = useT();
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="journal_date">{{ t('Date') }} <span class="text-destructive">*</span></Label>
                    <Input id="journal_date" v-model="form.journal_date" type="date" required />
                    <p v-if="periodFor" class="text-muted-foreground text-xs">
                        {{ t('Posts into') }} <span class="font-medium">{{ periodFor.name }}</span>
                    </p>
                    <p v-else class="text-destructive text-xs">
                        {{ t('No open fiscal period covers this date.') }}
                    </p>
                    <InputError :message="form.errors.journal_date" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="description">{{ t('Description') }} <span class="text-destructive">*</span></Label>
                    <Input id="description" v-model="form.description" required maxlength="500" />
                    <InputError :message="form.errors.description" />
                </div>
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

            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-3 font-medium">{{ t('Account') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('Description') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ t('Debit') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ t('Credit') }}</th>
                            <th class="w-10 px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(line, index) in form.lines"
                            :key="index"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t align-top"
                        >
                            <td class="px-3 py-2">
                                <select v-model.number="line.account_id" required :class="selectClass">
                                    <option :value="null" disabled>{{ t('Select an account…') }}</option>
                                    <option v-for="account in accounts" :key="account.id" :value="account.id">
                                        {{ account.code }} — {{ account.name }}
                                    </option>
                                </select>
                                <InputError :message="lineError(index, 'account_id')" />
                            </td>
                            <td class="px-3 py-2">
                                <Input v-model="line.description" maxlength="500" />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="line.debit"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="text-right"
                                    @input="onDebitInput(index)"
                                />
                                <InputError :message="lineError(index, 'debit')" />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="line.credit"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="text-right"
                                    @input="onCreditInput(index)"
                                />
                                <InputError :message="lineError(index, 'credit')" />
                            </td>
                            <td class="px-3 py-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="form.lines.length <= 2"
                                    @click="removeLine(index)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-muted/30">
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-3 py-3 font-medium" colspan="2">{{ t('Totals (:currency)', { currency }) }}</td>
                            <td class="px-3 py-3 text-right font-mono font-medium">{{ money(debitTotal) }}</td>
                            <td class="px-3 py-3 text-right font-mono font-medium">{{ money(creditTotal) }}</td>
                            <td></td>
                        </tr>
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-3 py-3 font-medium" colspan="2">{{ t('Difference') }}</td>
                            <td
                                class="px-3 py-3 text-right font-mono font-medium"
                                :class="isBalanced ? 'text-muted-foreground' : 'text-destructive'"
                                colspan="2"
                            >
                                {{ money(difference) }}
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <InputError :message="form.errors.lines" />

            <p v-if="!isBalanced" class="text-destructive text-xs">
                {{ t('Debits and credits must be equal, and greater than zero, before this entry can be posted. You may still save it as a draft.') }}
            </p>
            <p v-else class="text-muted-foreground text-xs">
                {{ t('Balanced. A draft still affects no account until you post it.') }}
            </p>
        </section>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="form.processing">{{ t(submitLabel) }}</Button>
            <Button variant="outline" as-child><a :href="cancelHref">{{ t('Cancel') }}</a></Button>
        </div>
    </form>
</template>
