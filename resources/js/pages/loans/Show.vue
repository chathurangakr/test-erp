<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import JournalTable from '@/components/payroll/JournalTable.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import { reverse as reversePaymentRoute } from '@/routes/loan-payments';
import { edit, index, reverse } from '@/routes/loans';
import { store as storePayment } from '@/routes/loans/payments';
import type { CashBankAccountOption, LoanDetail, PaymentMethodOption } from '@/types';
import { useT } from '@/lib/i18n';
import { money, moneyInput } from '@/lib/money';

const props = defineProps<{
    loan: LoanDetail;
    accounts: CashBankAccountOption[];
    paymentMethods: PaymentMethodOption[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Loans', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const form = useForm({
    payment_date: props.today,
    amount: moneyInput(props.loan.installment_amount),
    interest_amount: moneyInput(props.loan.suggested_interest),
    payment_method: 'BANK_TRANSFER',
    cash_bank_account_id: null as number | null,
    reference: '',
});

/** What reduces the loan: paid less interest. */
const principal = computed(() => Math.max(0, (Number(form.amount) || 0) - (Number(form.interest_amount) || 0)));

function pay(): void {
    form.submit(storePayment(props.loan.id), { preserveScroll: true, onSuccess: () => {
            form.reset('reference');
            // Less is owed now, so next month's suggested interest is lower.
            form.interest_amount = moneyInput(props.loan.suggested_interest);
        }, });
}

function reverseLoan(): void {
    if (confirm(t('Reverse this loan? It is taken off the books as if never recorded.'))) {
        router.patch(reverse(props.loan.id).url, {}, { preserveScroll: true });
    }
}

function reversePayment(id: number): void {
    if (confirm(t('Reverse this payment? The money goes back into the account and the loan owes it again.'))) {
        router.patch(reversePaymentRoute(id).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="loan.lender" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="loan.lender" :description="`${loan.loan_number} · ${loan.funding_label}${loan.purpose ? ' · ' + loan.purpose : ''}`" />
                <Badge :variant="loan.status === 'REVERSED' ? 'destructive' : loan.paid_off ? 'secondary' : 'default'" class="w-fit">
                    {{ loan.status === 'REVERSED' ? loan.status_label : loan.paid_off ? t('Paid off') : t('Being paid') }}
                </Badge>
            </div>
            <div class="flex gap-2">
                <Button v-if="can('loans.manage') && loan.status === 'POSTED'" variant="outline" as-child>
                    <Link :href="edit(loan.id)"><Pencil class="size-4" />{{ t('Edit') }}</Link>
                </Button>
                <Button v-if="loan.can_reverse && can('loans.reverse')" variant="outline" @click="reverseLoan">{{ t('Reverse') }}</Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Still owed (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-2xl font-semibold">{{ money(loan.outstanding) }}</p>
                <span class="text-muted-foreground text-xs">{{ t('of :amount', { amount: money(loan.amount) }) }}</span>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Next installment') }}</p>
                <p class="mt-1 text-lg font-medium">{{ loan.next_due_date ?? '—' }}</p>
                <span class="text-muted-foreground text-xs">{{ money(loan.installment_amount) }}<template v-if="loan.next_due_paid"> · {{ t('Paid') }}</template></span>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Paid off the loan') }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(loan.principal_paid) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Interest paid') }}</p>
                <p class="mt-1 font-mono text-lg">{{ loan.has_interest ? money(loan.interest_paid) : t('No interest') }}</p>
            </div>
        </div>

        <form
            v-if="loan.status === 'POSTED' && !loan.paid_off && can('loans.payment_record')"
            class="border-sidebar-border/70 dark:border-sidebar-border space-y-4 rounded-xl border p-4 sm:p-6"
            @submit.prevent="pay"
        >
            <h2 class="text-sm font-medium">{{ t('Record a payment') }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="payment_date">{{ t('Paid on') }}</Label>
                    <Input id="payment_date" v-model="form.payment_date" type="date" required />
                    <InputError :message="form.errors.payment_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="amount">{{ t('Amount paid (:currency)', { currency }) }}</Label>
                    <Input id="amount" v-model="form.amount" required inputmode="decimal" />
                    <InputError :message="form.errors.amount" />
                </div>
                <div v-if="loan.has_interest" class="grid gap-2">
                    <Label for="interest_amount">{{ t('Of which interest') }}</Label>
                    <Input id="interest_amount" v-model="form.interest_amount" inputmode="decimal" :placeholder="t('From the bank slip')" />
                    <p v-if="loan.suggested_interest !== null" class="text-muted-foreground text-xs">
                        {{ t(':rate% a year on :owed still owed — check it against the bank slip.', { rate: Number(loan.interest_rate), owed: money(loan.outstanding) }) }}
                    </p>
                    <InputError :message="form.errors.interest_amount" />
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
            <p class="text-sm">{{ t('Reduces the loan by :amount', { amount: money(principal) }) }}</p>
            <InputError :message="(form.errors as Record<string, string | undefined>).loan_interest_account_id" />
            <InputError :message="(form.errors as Record<string, string | undefined>).status" />
            <Button type="submit" :disabled="form.processing">{{ t('Record payment') }}</Button>
        </form>

        <section class="space-y-2">
            <h2 class="text-sm font-medium">{{ t('Payments') }}</h2>
            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Paid on') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Paid') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Interest') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Off the loan') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Paid from') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="payment in loan.payments" :key="payment.id" class="border-sidebar-border/70 dark:border-sidebar-border border-t" :class="payment.is_reversed ? 'text-muted-foreground line-through' : ''">
                            <td class="px-4 py-3">
                                {{ payment.payment_date }}
                                <span class="block font-mono text-xs">{{ payment.payment_number }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(payment.amount) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(payment.interest_amount) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(payment.principal_amount) }}</td>
                            <td class="px-4 py-3">{{ payment.paid_from }} <span class="text-muted-foreground text-xs">· {{ payment.payment_method_label }}</span></td>
                            <td class="px-4 py-3 text-right">
                                <Button v-if="!payment.is_reversed && can('loans.payment_reverse')" size="sm" variant="ghost" @click="reversePayment(payment.id)">{{ t('Reverse') }}</Button>
                                <span v-else-if="payment.is_reversed" class="text-xs no-underline">{{ payment.status_label }}</span>
                            </td>
                        </tr>
                        <tr v-if="loan.payments.length === 0">
                            <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No payments recorded yet.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <JournalTable v-if="loan.journal" :journal="loan.journal" />

        <dl class="grid gap-6 rounded-xl border p-4 sm:grid-cols-3 sm:p-6">
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Recorded') }}</dt>
                <dd class="mt-1 text-sm">{{ loan.posted_at ?? '—' }} · {{ loan.created_by ?? '—' }}</dd>
            </div>
            <div v-if="loan.received_into">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Paid into') }}</dt>
                <dd class="mt-1 text-sm">{{ loan.received_into }}</dd>
            </div>
            <div v-if="loan.reversed_at">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                <dd class="mt-1 text-sm">{{ loan.reversed_at }} · {{ loan.reversed_by ?? '—' }}</dd>
            </div>
            <div v-if="loan.notes" class="sm:col-span-3">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Notes') }}</dt>
                <dd class="mt-1 text-sm whitespace-pre-line">{{ loan.notes }}</dd>
            </div>
        </dl>
    </div>
</template>
