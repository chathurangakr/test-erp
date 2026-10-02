<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SupplierPaymentActionController from '@/actions/App/Http/Controllers/Payables/SupplierPaymentActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index as journalsIndex } from '@/routes/journals';
import { index as invoicesIndex } from '@/routes/supplier-invoices';
import { index } from '@/routes/supplier-payments';
import type { SupplierPaymentDetail } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{ payment: SupplierPaymentDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier payments', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

const { t } = useT();
</script>

<template>
    <Head :title="payment.payment_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="payment.payment_number"
                    :description="`${payment.supplier_code} · ${payment.payment_date} · ${payment.payment_account ?? ''}`"
                />
                <Badge :variant="badgeVariant(payment.status)" class="w-fit">{{ payment.status_label }}</Badge>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Form
                    v-if="payment.can_post && can('finance.ap.payment_post')"
                    v-bind="SupplierPaymentActionController.post.form(payment.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">{{ t('Post payment') }}</Button>
                </Form>

                <Form
                    v-if="payment.can_reverse && can('finance.ap.payment_reverse')"
                    v-bind="SupplierPaymentActionController.reverse.form(payment.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">{{ t('Reverse') }}</Button>
                </Form>

                <Form
                    v-if="payment.can_cancel && can('finance.ap.payment_cancel')"
                    v-bind="SupplierPaymentActionController.cancel.form(payment.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">{{ t('Cancel draft') }}</Button>
                </Form>
            </div>
        </div>

        <div
            v-if="payment.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            {{ t('This payment is a draft. No money has left the bank and no invoice has been settled until it is posted.') }}
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Amount (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ payment.amount }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Allocated') }}</p>
                <p class="mt-1 font-mono text-lg">{{ payment.allocated }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Reference') }}</p>
                <p class="mt-1 text-lg">{{ payment.reference ?? '—' }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">{{ t('Invoices settled') }}</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Invoice') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Due') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Invoice status') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Allocated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="allocation in payment.allocations"
                        :key="allocation.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs">
                            <Link
                                v-if="allocation.invoice_id"
                                :href="`${invoicesIndex().url}/${allocation.invoice_id}`"
                                class="hover:underline"
                            >
                                {{ allocation.internal_invoice_number }}
                            </Link>
                            <span class="text-muted-foreground block">{{ allocation.supplier_invoice_number }}</span>
                        </td>
                        <td class="px-4 py-3">{{ allocation.due_date }}</td>
                        <td class="px-4 py-3">{{ allocation.invoice_status }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ allocation.amount }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="payment.journal"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">
                    {{ t('Journal') }}
                    <Link
                        v-if="can('finance.view')"
                        :href="`${journalsIndex().url}/${payment.journal.id}`"
                        class="font-mono text-xs hover:underline"
                    >
                        {{ payment.journal.journal_number }}
                    </Link>
                    <span v-else class="font-mono text-xs">{{ payment.journal.journal_number }}</span>
                </h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Account') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Debit') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(line, i) in payment.journal.lines"
                        :key="i"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ line.account_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ line.account_name }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ Number(line.debit) > 0 ? line.debit : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ Number(line.credit) > 0 ? line.credit : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Created by') }}</dt>
                    <dd class="mt-1 text-sm">{{ payment.created_by ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Posted') }}</dt>
                    <dd class="mt-1 text-sm">
                        {{ payment.posted_at ? t(':date by :user', { date: payment.posted_at, user: payment.posted_by ?? '—' }) : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                    <dd class="mt-1 text-sm">
                        {{ payment.reversed_at ? t(':date by :user', { date: payment.reversed_at, user: payment.reversed_by ?? '—' }) : '—' }}
                        <span v-if="payment.reversal_journal_number" class="font-mono text-xs">
                            ({{ payment.reversal_journal_number }})
                        </span>
                    </dd>
                </div>
                <div v-if="payment.notes" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Notes') }}</dt>
                    <dd class="mt-1 text-sm">{{ payment.notes }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t("A payment settles invoices; it never touches Goods Received Not Invoiced, which the invoice already cleared, and it never touches inventory. Reversing it restores each invoice's outstanding balance without deleting anything — the allocations stay on the record and simply stop counting.") }}
        </p>
    </div>
</template>
