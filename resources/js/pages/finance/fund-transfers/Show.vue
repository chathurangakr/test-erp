<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import FundTransferActionController from '@/actions/App/Http/Controllers/Cash/FundTransferActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/fund-transfers';
import { index as journalsIndex } from '@/routes/journals';
import type { FundTransferDetail } from '@/types';

const props = defineProps<{ transfer: FundTransferDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Fund transfers', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}
</script>

<template>
    <Head :title="props.transfer.transfer_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="props.transfer.transfer_number" :description="props.transfer.transfer_date ?? ''" />
                <Badge :variant="badgeVariant(props.transfer.status)" class="w-fit">
                    {{ props.transfer.status_label }}
                </Badge>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Form
                    v-if="props.transfer.can_post && can('finance.cash.transfer_post')"
                    v-bind="FundTransferActionController.post.form(props.transfer.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">Post transfer</Button>
                </Form>

                <Form
                    v-if="props.transfer.can_reverse && can('finance.cash.transfer_reverse')"
                    v-bind="FundTransferActionController.reverse.form(props.transfer.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">Reverse</Button>
                </Form>

                <Form
                    v-if="props.transfer.can_cancel && can('finance.cash.transfer_cancel')"
                    v-bind="FundTransferActionController.cancel.form(props.transfer.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">Cancel draft</Button>
                </Form>
            </div>
        </div>

        <div
            v-if="props.transfer.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            This transfer is a <strong>draft</strong>. No money has moved and no journal exists
            until it is posted.
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-muted-foreground text-xs font-medium">From</p>
                    <p class="mt-1 text-lg font-medium">{{ props.transfer.from_name }}</p>
                    <p class="text-muted-foreground font-mono text-xs">{{ props.transfer.from_code }}</p>
                </div>

                <ArrowRight class="text-muted-foreground size-6 shrink-0" />

                <div>
                    <p class="text-muted-foreground text-xs font-medium">To</p>
                    <p class="mt-1 text-lg font-medium">{{ props.transfer.to_name }}</p>
                    <p class="text-muted-foreground font-mono text-xs">{{ props.transfer.to_code }}</p>
                </div>

                <div class="sm:text-right">
                    <p class="text-muted-foreground text-xs font-medium">Amount ({{ currency }})</p>
                    <p class="mt-1 font-mono text-lg font-medium">{{ props.transfer.amount }}</p>
                </div>
            </div>
        </div>

        <div
            v-if="props.transfer.journal"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">
                    Journal
                    <Link
                        v-if="can('finance.view')"
                        :href="`${journalsIndex().url}/${props.transfer.journal.id}`"
                        class="font-mono text-xs hover:underline"
                    >
                        {{ props.transfer.journal.journal_number }}
                    </Link>
                    <span v-else class="font-mono text-xs">{{ props.transfer.journal.journal_number }}</span>
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
                        v-for="(line, i) in props.transfer.journal.lines"
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
                    <dd class="mt-1 text-sm">{{ props.transfer.created_by ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Posted</dt>
                    <dd class="mt-1 text-sm">
                        {{ props.transfer.posted_at ? `${props.transfer.posted_at} by ${props.transfer.posted_by ?? '—'}` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Reversed</dt>
                    <dd class="mt-1 text-sm">
                        {{ props.transfer.reversed_at ? `${props.transfer.reversed_at} by ${props.transfer.reversed_by ?? '—'}` : '—' }}
                        <span v-if="props.transfer.reversal_journal_number" class="font-mono text-xs">
                            ({{ props.transfer.reversal_journal_number }})
                        </span>
                    </dd>
                </div>
                <div v-if="props.transfer.description" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">Description</dt>
                    <dd class="mt-1 text-sm">{{ props.transfer.description }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            The debit and the credit are the same figure used twice, so the two legs cannot drift
            apart. Nothing reaches profit or loss, and the combined cash-and-bank total is
            unchanged. Reversing puts the money back where it started and leaves both journals on
            the record.
        </p>
    </div>
</template>
