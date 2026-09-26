<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import ExpenseVoucherActionController from '@/actions/App/Http/Controllers/Cash/ExpenseVoucherActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { edit, index } from '@/routes/expense-vouchers';
import { index as journalsIndex } from '@/routes/journals';
import type { ExpenseVoucherDetail } from '@/types';

const props = defineProps<{ voucher: ExpenseVoucherDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Expense vouchers', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}
</script>

<template>
    <Head :title="props.voucher.voucher_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="props.voucher.voucher_number"
                    :description="`${props.voucher.voucher_date} · ${props.voucher.payment_account ?? ''}`"
                />
                <Badge :variant="badgeVariant(props.voucher.status)" class="w-fit">
                    {{ props.voucher.status_label }}
                </Badge>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button v-if="props.voucher.can_edit && can('finance.cash.expense_create')" variant="outline" as-child>
                    <Link :href="edit(props.voucher.id)">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>

                <Form
                    v-if="props.voucher.can_post && can('finance.cash.expense_post')"
                    v-bind="ExpenseVoucherActionController.post.form(props.voucher.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">Post voucher</Button>
                </Form>

                <Form
                    v-if="props.voucher.can_reverse && can('finance.cash.expense_reverse')"
                    v-bind="ExpenseVoucherActionController.reverse.form(props.voucher.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">Reverse</Button>
                </Form>

                <Form
                    v-if="props.voucher.can_cancel && can('finance.cash.expense_cancel')"
                    v-bind="ExpenseVoucherActionController.cancel.form(props.voucher.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">Cancel draft</Button>
                </Form>
            </div>
        </div>

        <div
            v-if="props.voucher.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            This voucher is a <strong>draft</strong>. No money has left the account and no
            journal exists until it is posted.
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Total ({{ currency }})</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ props.voucher.total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Payee</p>
                <p class="mt-1 text-lg">{{ props.voucher.payee ?? '—' }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Reference</p>
                <p class="mt-1 text-lg">{{ props.voucher.reference ?? '—' }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">Expenses charged</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Description</th>
                        <th class="px-4 py-3 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in props.voucher.lines"
                        :key="line.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ line.account_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ line.account_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ line.description ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.amount }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="props.voucher.journal"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">
                    Journal
                    <Link
                        v-if="can('finance.view')"
                        :href="`${journalsIndex().url}/${props.voucher.journal.id}`"
                        class="font-mono text-xs hover:underline"
                    >
                        {{ props.voucher.journal.journal_number }}
                    </Link>
                    <span v-else class="font-mono text-xs">{{ props.voucher.journal.journal_number }}</span>
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
                        v-for="(line, i) in props.voucher.journal.lines"
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
                    <dd class="mt-1 text-sm">{{ props.voucher.created_by ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Posted</dt>
                    <dd class="mt-1 text-sm">
                        {{ props.voucher.posted_at ? `${props.voucher.posted_at} by ${props.voucher.posted_by ?? '—'}` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Reversed</dt>
                    <dd class="mt-1 text-sm">
                        {{ props.voucher.reversed_at ? `${props.voucher.reversed_at} by ${props.voucher.reversed_by ?? '—'}` : '—' }}
                        <span v-if="props.voucher.reversal_journal_number" class="font-mono text-xs">
                            ({{ props.voucher.reversal_journal_number }})
                        </span>
                    </dd>
                </div>
                <div v-if="props.voucher.description" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">Description</dt>
                    <dd class="mt-1 text-sm">{{ props.voucher.description }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Posting debits each expense and credits the cash or bank account with the total, in
            one journal. Nothing becomes payable, and no stock, cost or inventory value moves.
            Naming a supplier records who was paid; it does not turn this into a credit purchase.
            Reversing mirrors the journal exactly and leaves both entries on the record.
        </p>
    </div>
</template>
