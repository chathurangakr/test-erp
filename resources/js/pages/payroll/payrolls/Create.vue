<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index, show, store } from '@/routes/payrolls';
import type { AttendanceStatusValue, CashBankAccountOption, PaidDay, PaymentMethodOption, PayrollLineView } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    periodStart: string;
    periodEnd: string;
    today: string;
    employeeId: number | null;
    employees: { id: number; code: string; name: string; is_active: boolean }[];
    payDate: string;
    lines: PayrollLineView[];
    paidDays: Record<string, PaidDay[]>;
    accounts: CashBankAccountOption[];
    paymentMethods: PaymentMethodOption[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Salaries', href: index() }] } });

const { t } = useT();

/** The period and person being looked at. Changing either reloads the figures from the server. */
const scope = reactive({ from: props.periodStart, to: props.periodEnd, employee: props.employeeId ? String(props.employeeId) : '' });

function load(from: string, to: string, employee = scope.employee): void {
    router.get(create().url, { from, to, employee_id: employee || undefined }, { preserveState: false });
}

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayDate = new Date(`${props.today}T00:00:00`);
const shortcuts = computed(() => [
    { label: t('Today'), from: props.today, to: props.today },
    { label: t('This month'), from: iso(new Date(todayDate.getFullYear(), todayDate.getMonth(), 1)), to: props.today },
    { label: t('Last month'), from: iso(new Date(todayDate.getFullYear(), todayDate.getMonth() - 1, 1)), to: iso(new Date(todayDate.getFullYear(), todayDate.getMonth(), 0)) },
]);

/** How much advance to take back from each person — up to the most allowed, chosen each time. */
const takeBack = reactive<Record<number, string>>(Object.fromEntries(props.lines.map((line) => [line.employee_id, line.advances_deducted])));

const net = (line: PayrollLineView) => Number(line.gross_pay) - Number(takeBack[line.employee_id] || 0);
const tooMuch = (line: PayrollLineView) => Number(takeBack[line.employee_id] || 0) > Number(line.advances_deducted) || Number(takeBack[line.employee_id] || 0) < 0;

/** Who is being paid now — everyone ticked to start; untick anyone paid on another day. */
const chosen = reactive<Record<number, boolean>>(Object.fromEntries(props.lines.map((line) => [line.employee_id, true])));
const chosenLines = computed(() => props.lines.filter((line) => chosen[line.employee_id]));
const allChosen = computed(() => chosenLines.value.length === props.lines.length);

function chooseAll(value: boolean): void {
    props.lines.forEach((line) => (chosen[line.employee_id] = value));
}

const totals = computed(() => ({
    gross: chosenLines.value.reduce((sum, line) => sum + Number(line.gross_pay), 0),
    taken: chosenLines.value.reduce((sum, line) => sum + Number(takeBack[line.employee_id] || 0), 0),
    net: chosenLines.value.reduce((sum, line) => sum + net(line), 0),
}));

/** People in scope whose every recorded day in the period was already paid. */
const fullyPaid = computed(() =>
    Object.keys(props.paidDays)
        .map(Number)
        .filter((id) => !props.lines.some((line) => line.employee_id === id))
        .map((id) => ({ id, name: props.employees.find((e) => e.id === id)?.name ?? '', days: props.paidDays[id] })),
);

const statusShort: Record<AttendanceStatusValue, string> = { FULL_DAY: 'Full', HALF_DAY: 'Half', LEAVE: 'Leave' };
const dayLabel = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });

const form = useForm({
    pay_date: props.payDate,
    payment_method: 'CASH',
    cash_bank_account_id: null as number | null,
    reference: '',
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

function submit(): void {
    const names = chosenLines.value.map((line) => line.name).join(', ');

    if (!confirm(t('Pay :amount in salaries to :names for :from to :to? The money leaves the chosen account now.', { amount: money(totals.value.net), names, from: props.periodStart, to: props.periodEnd }))) {
        return;
    }

    form.transform((data) => ({
        ...data,
        period_start: props.periodStart,
        period_end: props.periodEnd,
        // Only the ticked people are paid; everyone else's days stay unpaid for another day.
        employee_ids: chosenLines.value.map((line) => line.employee_id),
        deductions: chosenLines.value.map((line) => ({ employee_id: line.employee_id, amount: takeBack[line.employee_id] || '0' })),
    })).submit(store());
}
</script>

<template>
    <Head :title="t('Pay salaries')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Pay salaries" description="Tick the people you are paying now — one, a few or everyone. Anyone left unticked can be paid on another day. Days already paid are shown and never paid again." />

        <div class="flex flex-wrap items-end gap-4">
            <div class="grid gap-2">
                <Label for="from">{{ t('From') }}</Label>
                <Input id="from" v-model="scope.from" type="date" :max="today" class="w-44" @change="load(scope.from, scope.to < scope.from ? scope.from : scope.to)" />
            </div>
            <div class="grid gap-2">
                <Label for="to">{{ t('To') }}</Label>
                <Input id="to" v-model="scope.to" type="date" :min="scope.from" :max="today" class="w-44" @change="load(scope.from, scope.to)" />
            </div>
            <div class="grid min-w-64 gap-2">
                <Label for="employee">{{ t('Employee') }}</Label>
                <SearchableSelect
                    id="employee"
                    v-model="scope.employee"
                    :options="[{ value: '', label: t('Everyone') }, ...employees.map((e) => ({ value: String(e.id), label: `${e.code} — ${e.name}` }))]"
                    @update:model-value="(value) => load(scope.from, scope.to, String(value ?? ''))"
                />
            </div>
            <div class="flex flex-wrap gap-2 pb-0.5">
                <Button v-for="s in shortcuts" :key="s.label" type="button" variant="outline" size="sm" @click="load(s.from, s.to)">{{ s.label }}</Button>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="w-10 px-4 py-3">
                            <input
                                type="checkbox"
                                class="size-4"
                                :checked="allChosen"
                                :aria-label="t('Tick everyone')"
                                @change="chooseAll(($event.target as HTMLInputElement).checked)"
                            />
                        </th>
                        <th class="px-4 py-3 font-medium">{{ t('Employee') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Full day') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Half day') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Earned') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Advance owed') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Take back') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('To pay') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="line in lines" :key="line.employee_id">
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t" :class="chosen[line.employee_id] ? '' : 'opacity-50'">
                            <td class="px-4 pt-3">
                                <input v-model="chosen[line.employee_id]" type="checkbox" class="size-4" :aria-label="t('Pay :name now', { name: line.name ?? '' })" />
                            </td>
                            <td class="px-4 pt-3">
                                {{ line.name }}
                                <span class="text-muted-foreground block font-mono text-xs">{{ line.code }}</span>
                            </td>
                            <td class="px-4 pt-3 text-right">{{ line.full_days }}</td>
                            <td class="px-4 pt-3 text-right">{{ line.half_days }}</td>
                            <td class="px-4 pt-3 text-right font-mono">{{ money(line.gross_pay) }}</td>
                            <td class="px-4 pt-3 text-right font-mono">{{ money(line.advances_owed ?? '0') }}</td>
                            <td class="px-4 pt-3 text-right">
                                <Input
                                    v-model="takeBack[line.employee_id]"
                                    inputmode="decimal"
                                    class="ml-auto w-28 text-right font-mono"
                                    :disabled="Number(line.advances_deducted) === 0"
                                    :aria-label="t('Advance to take back from :name', { name: line.name ?? '' })"
                                />
                                <span v-if="Number(line.advances_deducted) > 0" class="block text-xs" :class="tooMuch(line) ? 'text-destructive' : 'text-muted-foreground'">
                                    {{ t('up to :amount', { amount: money(line.advances_deducted) }) }}
                                </span>
                            </td>
                            <td class="px-4 pt-3 text-right font-mono font-medium">{{ money(net(line)) }}</td>
                        </tr>
                        <tr>
                            <td></td>
                            <td colspan="7" class="px-4 pt-2 pb-3">
                                <div class="flex flex-wrap gap-1.5 text-xs">
                                    <span v-for="day in line.days" :key="day.date" class="rounded border border-green-300 bg-green-50 px-2 py-0.5 text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-100">
                                        {{ dayLabel(day.date) }} · {{ t(statusShort[day.status]) }}
                                    </span>
                                    <Link
                                        v-for="day in paidDays[line.employee_id] ?? []"
                                        :key="`p-${day.date}`"
                                        :href="show(day.payroll_id)"
                                        class="text-muted-foreground rounded border border-dashed px-2 py-0.5 line-through decoration-1 hover:underline"
                                        :title="t('Already paid — :reference', { reference: day.payroll_number ?? '' })"
                                    >
                                        {{ dayLabel(day.date) }} · {{ t('Paid') }}
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-for="person in fullyPaid" :key="`paid-${person.id}`" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td></td>
                        <td class="px-4 py-3">{{ person.name }}</td>
                        <td colspan="6" class="text-muted-foreground px-4 py-3 text-xs">
                            {{ t('Every day in this period is already paid:') }}
                            <Link v-for="day in person.days" :key="day.date" :href="show(day.payroll_id)" class="ml-1 hover:underline">{{ dayLabel(day.date) }} ({{ day.payroll_number }})</Link>
                        </td>
                    </tr>
                    <tr v-if="lines.length === 0 && fullyPaid.length === 0">
                        <td colspan="8" class="text-muted-foreground px-4 py-8 text-center">{{ t('No unpaid attendance in this period.') }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="lines.length > 0" class="bg-muted/30 font-medium">
                    <tr class="border-t">
                        <td class="px-4 py-3" colspan="4">{{ t('Total for the ticked people (:currency)', { currency }) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(totals.gross) }}</td>
                        <td></td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(totals.taken) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(totals.net) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="text-muted-foreground text-xs">
            {{ t('Only days not yet paid are included. Choose how much advance to take back from each person; whatever is not taken back stays owed.') }}
        </p>

        <form v-if="lines.length > 0" class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="pay_date">{{ t('Paid on') }}</Label>
                    <Input id="pay_date" v-model="form.pay_date" type="date" :min="periodEnd" required />
                    <InputError :message="form.errors.pay_date" />
                </div>
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

            <InputError :message="errors.period_start" />
            <InputError :message="errors.period_end" />
            <InputError :message="errors.deductions" />
            <InputError :message="errors.wages_account_id" />
            <InputError :message="errors.employee_advances_account_id" />

            <Button type="submit" :disabled="form.processing || chosenLines.length === 0 || chosenLines.some(tooMuch)">
                {{ allChosen ? t('Pay everyone') : t('Pay :count ticked', { count: chosenLines.length }) }}
            </Button>
        </form>
    </div>
</template>
