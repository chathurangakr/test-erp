<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Undo2 } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { show as journalShow } from '@/routes/journals';
import type { RouteDefinition } from '@/wayfinder';

type Application = { id: number; date: string; invoice: string | null; amount: string; status: string; status_label: string };

const props = defineProps<{
    partyLabel: string;
    advance: {
        id: number;
        advance_number: string;
        advance_date: string;
        party: string | null;
        amount: string;
        remaining: string;
        status: string;
        status_label: string;
        account: string | null;
        payment_method_label: string;
        cheque_number: string | null;
        cheque_date: string | null;
        cheque_bank: string | null;
        reference: string | null;
        note: string | null;
        journal_id: number | null;
        journal_number: string | null;
        created_by: string | null;
        posted_at: string | null;
        reversed_by: string | null;
        reversed_at: string | null;
        can_reverse: boolean;
        applications: Application[];
    };
    invoices: { id: number; number: string; date: string; outstanding: string }[];
    today: string;
    currency: string;
    canRecord: boolean;
    canReverse: boolean;
    applyAction: RouteDefinition<'post'>;
    reverseUrl: string;
    reverseApplicationUrl: (id: number) => string;
}>();

const { t } = useT();
const page = usePage();
const error = computed(() => (page.props.errors as Record<string, string | undefined>).status);

const form = useForm({ invoice_id: '', amount: '', application_date: props.today });
const invoice = computed(() => props.invoices.find((i) => String(i.id) === form.invoice_id) ?? null);

function suggest(): void {
    if (invoice.value) {
        const owed = Number(invoice.value.outstanding);
        const left = Number(props.advance.remaining);
        form.amount = Math.min(owed, left).toFixed(2);
    }
}

function apply(): void {
    form.submit(props.applyAction, { preserveScroll: true, onSuccess: () => form.reset('invoice_id', 'amount') });
}

function undo(): void {
    if (window.confirm(t('Reverse advance :reference?', { reference: props.advance.advance_number }))) {
        router.patch(props.reverseUrl, {}, { preserveScroll: true });
    }
}

function undoApplication(application: Application): void {
    if (window.confirm(t('Reverse this application? The invoice is owed :amount again.', { amount: money(application.amount) }))) {
        router.patch(props.reverseApplicationUrl(application.id), {}, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading :title="advance.advance_number" :description="`${advance.party ?? ''} · ${advance.advance_date}`" />
            <Button v-if="advance.can_reverse && canReverse" variant="outline" @click="undo"><Undo2 class="size-4" />{{ t('Reverse') }}</Button>
        </div>
        <InputError :message="error" />

        <dl class="border-sidebar-border/70 dark:border-sidebar-border grid max-w-3xl gap-4 rounded-xl border p-4 text-sm sm:grid-cols-3">
            <div><dt class="text-muted-foreground">{{ t('Amount (:currency)', { currency }) }}</dt><dd class="font-mono text-lg font-semibold">{{ money(advance.amount) }}</dd></div>
            <div><dt class="text-muted-foreground">{{ t('Not yet applied') }}</dt><dd class="font-mono text-lg font-semibold">{{ money(advance.remaining) }}</dd></div>
            <div><dt class="text-muted-foreground">{{ t('Status') }}</dt><dd><Badge :variant="advance.status === 'REVERSED' ? 'destructive' : 'default'">{{ advance.status_label }}</Badge></dd></div>
            <div><dt class="text-muted-foreground">{{ t(partyLabel) }}</dt><dd>{{ advance.party }}</dd></div>
            <div><dt class="text-muted-foreground">{{ t('Account') }}</dt><dd>{{ advance.account }} · {{ advance.payment_method_label }}</dd></div>
            <div v-if="advance.cheque_number"><dt class="text-muted-foreground">{{ t('Cheque') }}</dt><dd>#{{ advance.cheque_number }} · {{ advance.cheque_date }} · {{ advance.cheque_bank }}</dd></div>
            <div v-if="advance.reference"><dt class="text-muted-foreground">{{ t('Reference') }}</dt><dd>{{ advance.reference }}</dd></div>
            <div v-if="advance.note" class="sm:col-span-2"><dt class="text-muted-foreground">{{ t('Note') }}</dt><dd>{{ advance.note }}</dd></div>
            <div><dt class="text-muted-foreground">{{ t('Recorded') }}</dt><dd>{{ t(':date by :user', { date: advance.posted_at ?? '—', user: advance.created_by ?? '—' }) }}</dd></div>
            <div v-if="advance.journal_id">
                <dt class="text-muted-foreground">{{ t('Journal') }}</dt>
                <dd><Link :href="journalShow(advance.journal_id)" class="font-mono underline">{{ advance.journal_number }}</Link></dd>
            </div>
            <div v-if="advance.reversed_at"><dt class="text-muted-foreground">{{ t('Reversed') }}</dt><dd>{{ t(':date by :user', { date: advance.reversed_at, user: advance.reversed_by ?? '—' }) }}</dd></div>
        </dl>

        <form v-if="canRecord && invoices.length > 0" class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-4 rounded-xl border p-4" @submit.prevent="apply">
            <h2 class="font-semibold">{{ t('Apply to an invoice') }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2 sm:col-span-3">
                    <Label>{{ t('Invoice') }}</Label>
                    <SearchableSelect
                        v-model="form.invoice_id"
                        :options="invoices.map((i) => ({ value: String(i.id), label: `${i.number} · ${i.date} · ${t('owed :amount', { amount: money(i.outstanding) })}` }))"
                        @update:model-value="suggest"
                    />
                    <InputError :message="form.errors.invoice_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="apply_amount">{{ t('Amount (:currency)', { currency }) }}</Label>
                    <Input id="apply_amount" v-model="form.amount" inputmode="decimal" />
                    <InputError :message="form.errors.amount" />
                </div>
                <div class="grid gap-2">
                    <Label for="application_date">{{ t('Date') }}</Label>
                    <Input id="application_date" v-model="form.application_date" type="date" />
                    <InputError :message="form.errors.application_date" />
                </div>
                <div class="flex items-end">
                    <Button type="submit" :disabled="form.processing || !form.invoice_id">{{ t('Apply') }}</Button>
                </div>
            </div>
        </form>

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl overflow-x-auto rounded-xl border">
            <div class="bg-muted/50 px-4 py-2 text-sm font-medium">{{ t('Applied to invoices') }}</div>
            <table class="w-full text-sm">
                <tbody>
                    <tr v-for="application in advance.applications" :key="application.id" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-2">{{ application.date }}</td>
                        <td class="px-4 py-2 font-mono">{{ application.invoice }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ money(application.amount) }}</td>
                        <td class="px-4 py-2"><Badge :variant="application.status === 'REVERSED' ? 'destructive' : 'secondary'">{{ application.status_label }}</Badge></td>
                        <td class="px-4 py-2 text-right">
                            <Button v-if="application.status === 'POSTED' && canReverse" size="sm" variant="ghost" @click="undoApplication(application)">{{ t('Reverse') }}</Button>
                        </td>
                    </tr>
                    <tr v-if="advance.applications.length === 0">
                        <td colspan="5" class="text-muted-foreground px-4 py-4 text-center">{{ t('Not applied to any invoice yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
