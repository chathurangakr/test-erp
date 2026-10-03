<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import SalesInvoiceActionController from '@/actions/App/Http/Controllers/Receivables/SalesInvoiceActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create as receiptCreate, index as receiptsIndex } from '@/routes/customer-receipts';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { index as journalsIndex } from '@/routes/journals';
import { index } from '@/routes/sales-invoices';
import type { SalesInvoiceDetail } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{ invoice: SalesInvoiceDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Sales invoices', href: index() }] } });

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
    <Head :title="invoice.invoice_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="invoice.invoice_number"
                    :description="`${invoice.customer_code} · ${invoice.invoice_date}${invoice.customer_reference ? ' · ' + invoice.customer_reference : ''}`"
                />
                <div class="flex flex-wrap gap-2">
                    <Badge :variant="badgeVariant(invoice.status)">{{ invoice.status_label }}</Badge>
                    <Badge v-if="invoice.is_overdue" variant="destructive">{{ t('Overdue') }}</Badge>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button
                    v-if="(invoice.status === 'POSTED' || invoice.status === 'PARTIALLY_PAID') && Number(invoice.outstanding) > 0 && can('finance.ar.receipt_create')"
                    as-child
                >
                    <Link :href="receiptCreate({ query: { customer_id: invoice.customer_id, invoice_id: invoice.id } }).url">
                        {{ t('Record payment') }}
                    </Link>
                </Button>
                <Button v-if="invoice.can_edit && can('finance.ar.invoice_create')" variant="outline" as-child>
                    <Link :href="`${index().url}/${invoice.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>

                <Form
                    v-if="invoice.can_post && can('finance.ar.invoice_post')"
                    v-bind="SalesInvoiceActionController.post.form(invoice.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">{{ t('Post invoice') }}</Button>
                </Form>

                <Form
                    v-if="invoice.can_reverse && can('finance.ar.invoice_reverse')"
                    v-bind="SalesInvoiceActionController.reverse.form(invoice.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">{{ t('Reverse') }}</Button>
                </Form>

                <Form
                    v-if="invoice.can_cancel && can('finance.ar.invoice_cancel')"
                    v-bind="SalesInvoiceActionController.cancel.form(invoice.id)"
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
            {{ t('This invoice is a draft. No revenue has been recognised, nothing is owed, and the delivered value it bills is still shown as unbilled.') }}
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg">{{ invoice.total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Received') }}</p>
                <p class="mt-1 font-mono text-lg">{{ invoice.received }}</p>
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
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">{{ t('Delivered lines billed') }}</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Delivery') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Product') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Delivered') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Delivered value') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Billed here') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in invoice.lines"
                        :key="line.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs">
                            <Link
                                v-if="line.delivery_id && can('sales.view')"
                                :href="`${deliveriesIndex().url}/${line.delivery_id}`"
                                class="hover:underline"
                            >
                                {{ line.delivery_number }}
                            </Link>
                            <template v-else-if="line.delivery_id">{{ line.delivery_number }}</template>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ line.product_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ line.product_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ line.delivery_date }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.delivered_value ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ line.amount }}</td>
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
            v-if="invoice.receipts.length"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">{{ t('Receipts') }}</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Receipt') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Allocated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="receipt in invoice.receipts"
                        :key="receipt.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                        :class="{ 'text-muted-foreground': receipt.status === 'REVERSED' }"
                    >
                        <td class="px-4 py-3 font-mono text-xs">
                            <Link
                                v-if="receipt.receipt_id"
                                :href="`${receiptsIndex().url}/${receipt.receipt_id}`"
                                class="hover:underline"
                            >
                                {{ receipt.receipt_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">{{ receipt.receipt_date }}</td>
                        <td class="px-4 py-3">{{ receipt.status_label }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ receipt.amount }}</td>
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
            {{ t('Posting this invoice changed no inventory and added no cost of sales: the delivery already recorded what the goods cost when they left. It bills the value the delivery stored, so nothing is repriced here. Reversal is an audit correction, not a credit note — a commercial return or allowance is a separate document that is not built yet.') }}
        </p>
    </div>
</template>
