<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { index, reverse, store } from '@/routes/bank-reconciliation';

type Line = { id: number; date: string; journal_number: string; source: string; description: string | null; money_in: string; money_out: string };

const props = defineProps<{
    accounts: { id: number; name: string }[];
    account: { id: number; name: string; book_balance: string; reconciled_balance: string; last_statement_date: string | null } | null;
    statementDate: string;
    lines: Line[];
    history: { id: number; statement_date: string; statement_balance: string; lines: number; status: string; by: string | null; can_undo: boolean }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Bank reconciliation', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const accountId = ref(props.account ? String(props.account.id) : '');
const date = ref(props.statementDate);
function reload(): void {
    router.get(index().url, { cash_bank_account_id: accountId.value || undefined, statement_date: date.value }, { preserveState: false });
}

const form = useForm({
    cash_bank_account_id: props.account?.id ?? 0,
    statement_date: props.statementDate,
    statement_balance: '',
    journal_entry_line_ids: [] as number[],
    note: '',
});

/** Cents, so the running figures never show floating-point dust. */
const cents = (value: string) => Math.round(Number(value) * 100);
const cleared = computed(() => {
    let total = cents(props.account?.reconciled_balance ?? '0');
    for (const line of props.lines) {
        if (form.journal_entry_line_ids.includes(line.id)) {
            total += cents(line.money_in) - cents(line.money_out);
        }
    }
    return total;
});
const difference = computed(() => (form.statement_balance === '' ? null : cents(form.statement_balance) - cleared.value));

function toggleAll(): void {
    form.journal_entry_line_ids = form.journal_entry_line_ids.length === props.lines.length ? [] : props.lines.map((l) => l.id);
}

function finish(): void {
    form.submit(store(), { preserveScroll: true });
}

function undo(id: number): void {
    if (window.confirm(t('Undo this reconciliation? Its lines can be ticked again.'))) {
        router.patch(reverse(id).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="t('Bank reconciliation')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Bank reconciliation" description="Agree the bank statement with the books: tick every line the statement shows. When the difference is zero, finish." />

        <p v-if="accounts.length === 0" class="text-muted-foreground text-sm">{{ t('No bank account is set up.') }}</p>

        <template v-else>
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid gap-1">
                    <Label>{{ t('Bank account') }}</Label>
                    <select v-model="accountId" class="border-input bg-background h-9 rounded-md border px-3 text-sm" @change="reload">
                        <option v-for="a in accounts" :key="a.id" :value="String(a.id)">{{ a.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1">
                    <Label>{{ t('Statement date') }}</Label>
                    <Input v-model="date" type="date" class="w-44" @change="reload" />
                </div>
            </div>

            <div v-if="account" class="grid max-w-4xl gap-4 sm:grid-cols-4">
                <div class="rounded-xl border p-3 text-sm"><div class="text-muted-foreground text-xs">{{ t('Books, at the statement date') }}</div><div class="font-mono text-lg">{{ money(account.book_balance) }}</div></div>
                <div class="rounded-xl border p-3 text-sm"><div class="text-muted-foreground text-xs">{{ t('Last statement') }}</div><div class="font-mono text-lg">{{ money(account.reconciled_balance) }}</div><div class="text-muted-foreground text-xs">{{ account.last_statement_date ?? t('none yet') }}</div></div>
                <div class="rounded-xl border p-3 text-sm"><div class="text-muted-foreground text-xs">{{ t('Ticked leaves') }}</div><div class="font-mono text-lg">{{ money(String(cleared / 100)) }}</div></div>
                <div class="rounded-xl border p-3 text-sm" :class="difference === 0 ? 'border-green-600' : ''">
                    <div class="text-muted-foreground text-xs">{{ t('Difference') }}</div>
                    <div class="font-mono text-lg" :class="difference === 0 ? 'text-green-700' : 'text-destructive'">{{ difference === null ? '—' : money(String(difference / 100)) }}</div>
                </div>
            </div>

            <form v-if="account && can('finance.cash.reconcile')" class="max-w-4xl space-y-4" @submit.prevent="finish">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="grid gap-1">
                        <Label for="statement_balance">{{ t('Statement closing balance (:currency)', { currency }) }}</Label>
                        <Input id="statement_balance" v-model="form.statement_balance" inputmode="decimal" class="w-56" />
                    </div>
                    <div class="grid flex-1 gap-1">
                        <Label for="note">{{ t('Note') }}</Label>
                        <Input id="note" v-model="form.note" maxlength="255" />
                    </div>
                    <Button type="submit" :disabled="form.processing || difference !== 0">{{ t('Finish') }}</Button>
                </div>
                <InputError :message="form.errors.statement_balance ?? form.errors.statement_date ?? form.errors.journal_entry_line_ids ?? form.errors.cash_bank_account_id" />

                <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-3 py-2"><input type="checkbox" :checked="lines.length > 0 && form.journal_entry_line_ids.length === lines.length" @change="toggleAll" /></th>
                                <th class="px-3 py-2 font-medium">{{ t('Date') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('Journal') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('Description') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ t('Money in') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ t('Money out') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in lines" :key="line.id" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                                <td class="px-3 py-2"><input v-model="form.journal_entry_line_ids" type="checkbox" :value="line.id" /></td>
                                <td class="px-3 py-2">{{ line.date }}</td>
                                <td class="px-3 py-2 font-mono">{{ line.journal_number }}</td>
                                <td class="px-3 py-2">{{ line.source }} — {{ line.description }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ Number(line.money_in) > 0 ? money(line.money_in) : '' }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ Number(line.money_out) > 0 ? money(line.money_out) : '' }}</td>
                            </tr>
                            <tr v-if="lines.length === 0">
                                <td colspan="6" class="text-muted-foreground px-3 py-6 text-center">{{ t('Every line up to this date is reconciled.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </form>

            <div v-if="history.length > 0" class="border-sidebar-border/70 dark:border-sidebar-border max-w-4xl overflow-x-auto rounded-xl border">
                <div class="bg-muted/50 px-4 py-2 text-sm font-medium">{{ t('Earlier statements') }}</div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="r in history" :key="r.id" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-2">{{ r.statement_date }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(r.statement_balance) }}</td>
                            <td class="px-4 py-2">{{ t(':count lines', { count: r.lines }) }} · {{ r.by }}</td>
                            <td class="px-4 py-2"><Badge :variant="r.status === 'COMPLETED' ? 'default' : 'outline'">{{ r.status === 'COMPLETED' ? t('Reconciled') : t('Undone') }}</Badge></td>
                            <td class="px-4 py-2 text-right"><Button v-if="r.can_undo && can('finance.cash.reconcile')" size="sm" variant="ghost" @click="undo(r.id)">{{ t('Undo') }}</Button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
