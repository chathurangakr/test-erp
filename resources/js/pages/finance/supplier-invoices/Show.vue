<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import SupplierInvoiceActionController from '@/actions/App/Http/Controllers/Payables/SupplierInvoiceActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index as journalsIndex } from '@/routes/journals';
import { index } from '@/routes/supplier-invoices';
import { create as paymentCreate, index as paymentsIndex } from '@/routes/supplier-payments';
import type { SupplierInvoiceDetail } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{ invoice: SupplierInvoiceDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier invoices', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'PAID') return 'secondary';
    if (value === 'POSTED' || value === 'PARTIALLY_PAID') return 'default';
    return 'outline';
}

const { t } = useT();
</script>

<template>
    <Head :title="invoice.internal_invoice_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="invoice.internal_invoice_number"
                    :description="`${invoice.supplier_code} · ${invoice.supplier_invoice_number} · ${invoice.invoice_date}`"
                />
                <div class="flex flex-wrap gap-2">
                    <Badge :variant="badgeVariant(invoice.status)">{{ invoice.status_label }}</Badge>
                    <Badge v-if="invoice.is_overdue" variant="destructive">{{ t('Overdue') }}</Badge>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button
                    v-if="(invoice.status === 'POSTED' || invoice.status === 'PARTIALLY_PAID') && Number(invoice.outstanding) > 0 && can('finance.ap.payment_create')"
                    as-child
                >
                    <Link :href="paymentCreate({ query: { supplier_id: invoice.supplier_id, invoice_id: invoice.id } }).url">
                        {{ t('Record payment') }}
                    </Link>
                </Button>
                <Button v-if="invoice.can_edit && can('finance.ap.invoice_create')" variant="outline" as-child>
                    <Link :href="`${index().url}/${invoice.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>

                <Form
                    v-if="invoice.can_post && can('finance.ap.invoice_post')"
                    v-bind="SupplierInvoiceActionController.post.form(invoice.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">{{ t('Post invoice') }}</Button>
                </Form>

                <Form
                    v-if="invoice.can_reverse && can('finance.ap.invoice_reverse')"
                    v-bind="SupplierInvoiceActionController.reverse.form(invoice.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">{{ t('Reverse') }}</Button>
                </Form>

                <Form
                    v-if="invoice.can_cancel && can('finance.ap.invoice_cancel')"
                    v-bind="SupplierInvoiceActionController.cancel.form(invoice.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">{{ t('Cancel draft') }}</Button>
                </Form>
            </div>
        </div>

        <div
            v-if="invoice.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            {{ t('This invoice is a draft. Nothing is owed, no accrual has been cleared, and it appears in no aging report until it is posted.') }}
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg">{{ invoice.total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Paid') }}</p>
                <p class="mt-1 font-mono text-lg">{{ invoice.paid }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Outstanding') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ invoice.outstanding }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Due') }}</p>
                <p class="mt-1 text-lg">{{ invoice.due_date }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Type') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Against') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Description') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in invoice.lines"
                        :key="line.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">{{ line.line_type_label }}</td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ line.grn_number ?? `${line.account_code} ${line.account_name}` }}
                        </td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">{{ line.description ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.amount }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="invoice.journal"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">
                    {{ t('Journal') }}
                    <Link
                        v-if="can('finance.view')"
                        :href="`${journalsIndex().url}/${invoice.journal.id}`"
                        class="font-mono text-xs hover:underline"
                    >
                        {{ invoice.journal.journal_number }}
                    </Link>
                    <span v-else class="font-mono text-xs">{{ invoice.journal.journal_number }}</span>
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
                        v-for="(line, i) in invoice.journal.lines"
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

        <div
            v-if="invoice.payments.length"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">{{ t('Payments') }}</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Payment') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Allocated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="payment in invoice.payments"
                        :key="payment.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                        :class="{ 'text-muted-foreground': payment.status === 'REVERSED' }"
                    >
                        <td class="px-4 py-3 font-mono text-xs">
                            <Link
                                v-if="payment.payment_id"
                                :href="`${paymentsIndex().url}/${payment.payment_id}`"
                                class="hover:underline"
                            >
                                {{ payment.payment_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">{{ payment.payment_date }}</td>
                        <td class="px-4 py-3">{{ payment.status_label }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ payment.amount }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Created by') }}</dt>
                    <dd class="mt-1 text-sm">{{ invoice.created_by ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Posted') }}</dt>
                    <dd class="mt-1 text-sm">
                        {{ invoice.posted_at ? t(':date by :user', { date: invoice.posted_at, user: invoice.posted_by ?? '—' }) : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                    <dd class="mt-1 text-sm">
                        {{ invoice.reversed_at ? t(':date by :user', { date: invoice.reversed_at, user: invoice.reversed_by ?? '—' }) : '—' }}
                        <span v-if="invoice.reversal_journal_number" class="font-mono text-xs">
                            ({{ invoice.reversal_journal_number }})
                        </span>
                    </dd>
                </div>
                <div v-if="invoice.description" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Description') }}</dt>
                    <dd class="mt-1 text-sm">{{ invoice.description }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t("Posting this invoice changed no inventory: the goods receipt established what the stock is worth, and accounts payable only records who is owed for it. A goods-receipt line clears part of that receipt's accrual, and several suppliers may each clear a part — goods, transport and loading are commonly three different companies.") }}
        </p>
    </div>
</template>
