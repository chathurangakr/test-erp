<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index, show, store } from '@/routes/payrolls';
import type { CashBankAccountOption, PaymentMethodOption, PayrollLineView } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    month: string;
    periodStart: string;
    periodEnd: string;
    payDate: string;
    lines: PayrollLineView[];
    totals: { gross_pay: string; advances_deducted: string; net_pay: string };
    paidPayroll: { id: number; payroll_number: string } | null;
    accounts: CashBankAccountOption[];
    paymentMethods: PaymentMethodOption[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Monthly salaries', href: index() }] } });

const { t } = useT();

const month = ref(props.month);
watch(month, (value) => {
    if (value) {
        router.get(create().url, { month: value }, { preserveState: false });
    }
});

const form = useForm({
    month: props.month,
    pay_date: props.payDate,
    payment_method: 'CASH',
    cash_bank_account_id: null as number | null,
    reference: '',
});

function submit(): void {
    if (confirm(t('Pay :amount in salaries for :month? The money leaves the chosen account now.', { amount: money(props.totals.net_pay), month: props.month }))) {
        form.submit(store());
    }
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head :title="t('Pay a month')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Pay a month" description="Check each person's days and pay, then pay everyone in one step. Advances still owed are taken back from this pay." />

        <div class="flex flex-wrap items-end gap-4">
            <div class="grid gap-2">
                <Label for="month">{{ t('Month') }}</Label>
                <Input id="month" v-model="month" type="month" class="w-48" />
            </div>
            <p class="text-muted-foreground pb-2 text-sm">{{ periodStart }} — {{ periodEnd }}</p>
        </div>

        <div v-if="paidPayroll" class="rounded-xl border border-dashed p-4 text-sm">
            {{ t('Salaries for this month are already paid.') }}
            <Link :href="show(paidPayroll.id)" class="font-mono hover:underline">{{ paidPayroll.payroll_number }}</Link>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Employee') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Full day') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Half day') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Leave') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Earned') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Advance owed') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Taken back') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('To pay') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in lines" :key="line.employee_id" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3">
                            {{ line.name }}
                            <span class="text-muted-foreground block font-mono text-xs">{{ line.code }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">{{ line.full_days }}</td>
                        <td class="px-4 py-3 text-right">{{ line.half_days }}</td>
                        <td class="px-4 py-3 text-right">{{ line.leave_days }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.gross_pay) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.advances_owed ?? '0') }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.advances_deducted) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(line.net_pay) }}</td>
                    </tr>
                    <tr v-if="lines.length === 0">
                        <td colspan="8" class="text-muted-foreground px-4 py-8 text-center">{{ t('No unpaid attendance in this month.') }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="lines.length > 0" class="bg-muted/30 font-medium">
                    <tr class="border-t">
                        <td class="px-4 py-3" colspan="4">{{ t('Total (:currency)', { currency }) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(totals.gross_pay) }}</td>
                        <td></td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(totals.advances_deducted) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(totals.net_pay) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="text-muted-foreground text-xs">
            {{ t('An advance a salary cannot fully cover is taken back as far as the salary allows; the rest is taken from the next month.') }}
        </p>

        <form v-if="!paidPayroll && lines.length > 0" class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="pay_date">{{ t('Paid on') }}</Label>
                    <Input id="pay_date" v-model="form.pay_date" type="date" :min="periodEnd" required />
                    <InputError :message="form.errors.pay_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="payment_method">{{ t('Paid by') }}</Label>
                    <select id="payment_method" v-model="form.payment_method" :class="selectClass">
                        <option v-for="m in paymentMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="cash_bank_account_id">{{ t('Paid from') }}</Label>
                    <select id="cash_bank_account_id" v-model="form.cash_bank_account_id" required :class="selectClass">
                        <option :value="null" disabled>{{ t('Select a cash or bank account…') }}</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }} ({{ a.kind }})</option>
                    </select>
                    <InputError :message="form.errors.cash_bank_account_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="reference">{{ t('Reference') }}</Label>
                    <Input id="reference" v-model="form.reference" maxlength="100" />
                </div>
            </div>

            <InputError :message="form.errors.month" />
            <InputError :message="(form.errors as Record<string, string | undefined>).wages_account_id" />
            <InputError :message="(form.errors as Record<string, string | undefined>).employee_advances_account_id" />

            <Button type="submit" :disabled="form.processing">{{ t('Pay salaries') }}</Button>
        </form>
    </div>
</template>
