<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import CustomerReceiptActionController from '@/actions/App/Http/Controllers/Receivables/CustomerReceiptActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as journalsIndex } from '@/routes/journals';
import { index as invoicesIndex } from '@/routes/sales-invoices';
import { index } from '@/routes/customer-receipts';
import type { CustomerReceiptDetail } from '@/types';

defineProps<{ receipt: CustomerReceiptDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Customer receipts', href: index() }] } });

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}
</script>

<template>
    <Head :title="receipt.receipt_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="receipt.receipt_number"
                    :description="`${receipt.customer_code} · ${receipt.receipt_date} · ${receipt.receipt_account ?? ''}`"
                />
                <Badge :variant="badgeVariant(receipt.status)" class="w-fit">{{ receipt.status_label }}</Badge>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Form
                    v-if="receipt.can_post"
                    v-bind="CustomerReceiptActionController.post.form(receipt.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">Post receipt</Button>
                </Form>

                <Form
                    v-if="receipt.can_reverse"
                    v-bind="CustomerReceiptActionController.reverse.form(receipt.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">Reverse</Button>
                </Form>

                <Form
                    v-if="receipt.can_cancel"
                    v-bind="CustomerReceiptActionController.cancel.form(receipt.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">Cancel draft</Button>
                </Form>
            </div>
        </div>

        <div
            v-if="receipt.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            This receipt is a <strong>draft</strong>. No money has left the bank and no invoice has
            been settled until it is posted.
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Amount ({{ currency }})</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ receipt.amount }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Allocated</p>
                <p class="mt-1 font-mono text-lg">{{ receipt.allocated }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Reference</p>
                <p class="mt-1 text-lg">{{ receipt.reference ?? '—' }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">Invoices settled</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Invoice</th>
                        <th class="px-4 py-3 font-medium">Due</th>
                        <th class="px-4 py-3 font-medium">Invoice status</th>
                        <th class="px-4 py-3 text-right font-medium">Allocated</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="allocation in receipt.allocations"
                        :key="allocation.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs">
                            <Link
                                v-if="allocation.invoice_id"
                                :href="`${invoicesIndex().url}/${allocation.invoice_id}`"
                                class="hover:underline"
                            >
                                {{ allocation.invoice_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">{{ allocation.due_date }}</td>
                        <td class="px-4 py-3">{{ allocation.invoice_status }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ allocation.amount }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="receipt.journal"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">
                    Journal
                    <Link
                        :href="`${journalsIndex().url}/${receipt.journal.id}`"
                        class="font-mono text-xs hover:underline"
                    >
                        {{ receipt.journal.journal_number }}
                    </Link>
                </h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 text-right font-medium">Debit</th>
                        <th class="px-4 py-3 text-right font-medium">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(line, i) in receipt.journal.lines"
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
                    <dt class="text-muted-foreground text-sm font-medium">Created by</dt>
                    <dd class="mt-1 text-sm">{{ receipt.created_by ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Posted</dt>
                    <dd class="mt-1 text-sm">
                        {{ receipt.posted_at ? `${receipt.posted_at} by ${receipt.posted_by ?? '—'}` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Reversed</dt>
                    <dd class="mt-1 text-sm">
                        {{ receipt.reversed_at ? `${receipt.reversed_at} by ${receipt.reversed_by ?? '—'}` : '—' }}
                        <span v-if="receipt.reversal_journal_number" class="font-mono text-xs">
                            ({{ receipt.reversal_journal_number }})
                        </span>
                    </dd>
                </div>
                <div v-if="receipt.notes" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">Notes</dt>
                    <dd class="mt-1 text-sm">{{ receipt.notes }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            A receipt settles invoices. It recognises no revenue — the invoice already did that —
            and it touches no inventory or cost of sales. Reversing it restores each invoice's
            outstanding balance without deleting anything: the allocations stay on the record and
            simply stop counting.
        </p>
    </div>
</template>
