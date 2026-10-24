<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Undo2 } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { index, reverse } from '@/routes/credit-notes';
import { show as journalShow } from '@/routes/journals';
import { show as invoiceShow } from '@/routes/sales-invoices';

const props = defineProps<{
    note: {
        id: number;
        credit_note_number: string;
        credit_date: string;
        customer: string | null;
        invoice_id: number;
        invoice_number: string | null;
        kind_label: string;
        amount: string;
        status: string;
        status_label: string;
        reason: string;
        created_by: string | null;
        posted_at: string | null;
        reversed_by: string | null;
        reversed_at: string | null;
        reversal_journal_number: string | null;
        can_reverse: boolean;
        journal: { id: number; journal_number: string; lines: { account_code: string; account_name: string; debit: string; credit: string }[] } | null;
    };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Credit notes', href: index() }, { title: 'Credit note', href: '#' }] } });

const { t } = useT();
const { can } = usePermissions();
const page = usePage();
const error = computed(() => (page.props.errors as Record<string, string | undefined>).status);

function undo(): void {
    if (window.confirm(t('Reverse credit note :reference? The invoice is owed the amount again.', { reference: props.note.credit_note_number }))) {
        router.patch(reverse(props.note.id).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="note.credit_note_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading :title="note.credit_note_number" :description="`${note.kind_label} · ${note.credit_date}`" />
            <Button v-if="note.can_reverse && can('finance.ar.credit_note_reverse')" variant="outline" @click="undo"><Undo2 class="size-4" />{{ t('Reverse') }}</Button>
        </div>
        <InputError :message="error" />

        <dl class="border-sidebar-border/70 dark:border-sidebar-border grid max-w-3xl gap-4 rounded-xl border p-4 text-sm sm:grid-cols-2">
            <div><dt class="text-muted-foreground">{{ t('Customer') }}</dt><dd class="font-medium">{{ note.customer }}</dd></div>
            <div>
                <dt class="text-muted-foreground">{{ t('Invoice') }}</dt>
                <dd><Link :href="invoiceShow(note.invoice_id)" class="font-mono underline">{{ note.invoice_number }}</Link></dd>
            </div>
            <div><dt class="text-muted-foreground">{{ t('Amount (:currency)', { currency }) }}</dt><dd class="font-mono text-lg font-semibold">{{ money(note.amount) }}</dd></div>
            <div><dt class="text-muted-foreground">{{ t('Status') }}</dt><dd><Badge :variant="note.status === 'REVERSED' ? 'destructive' : 'default'">{{ note.status_label }}</Badge></dd></div>
            <div class="sm:col-span-2"><dt class="text-muted-foreground">{{ t('Reason') }}</dt><dd>{{ note.reason }}</dd></div>
            <div><dt class="text-muted-foreground">{{ t('Recorded') }}</dt><dd>{{ t(':date by :user', { date: note.posted_at ?? '—', user: note.created_by ?? '—' }) }}</dd></div>
            <div v-if="note.reversed_at">
                <dt class="text-muted-foreground">{{ t('Reversed') }}</dt>
                <dd>{{ t(':date by :user', { date: note.reversed_at, user: note.reversed_by ?? '—' }) }} · {{ note.reversal_journal_number }}</dd>
            </div>
        </dl>

        <div v-if="note.journal" class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl overflow-x-auto rounded-xl border">
            <div class="bg-muted/50 px-4 py-2 text-sm font-medium">
                {{ t('Journal') }} <Link :href="journalShow(note.journal.id)" class="font-mono underline">{{ note.journal.journal_number }}</Link>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    <tr v-for="(line, i) in note.journal.lines" :key="i" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-2">{{ line.account_code }} {{ line.account_name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Number(line.debit) > 0 ? money(line.debit) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Number(line.credit) > 0 ? money(line.credit) : '' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
