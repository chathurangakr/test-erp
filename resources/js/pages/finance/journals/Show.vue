<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import JournalEntryActionController from '@/actions/App/Http/Controllers/Accounting/JournalEntryActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/journals';
import type { JournalDetail } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{ entry: JournalDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Journal entries', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

const { t } = useT();
</script>

<template>
    <Head :title="entry.journal_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="entry.journal_number"
                    :description="`${entry.journal_date} · ${entry.fiscal_period ?? '—'} · ${entry.source}`"
                />
                <Badge :variant="badgeVariant(entry.status)" class="w-fit">{{ entry.status_label }}</Badge>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button v-if="entry.can_edit && can('finance.journal_create')" variant="outline" as-child>
                    <Link :href="`${index().url}/${entry.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>

                <Form
                    v-if="entry.can_post && can('finance.journal_post')"
                    v-bind="JournalEntryActionController.post.form(entry.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing || !entry.is_balanced">
                        {{ t('Post entry') }}
                    </Button>
                </Form>

                <Form
                    v-if="entry.can_reverse && can('finance.journal_reverse')"
                    v-bind="JournalEntryActionController.reverse.form(entry.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ t('Reverse') }}
                    </Button>
                </Form>

                <Form
                    v-if="entry.can_cancel && can('finance.journal_cancel')"
                    v-bind="JournalEntryActionController.cancel.form(entry.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ t('Cancel draft') }}
                    </Button>
                </Form>
            </div>
        </div>

        <div
            v-if="entry.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            {{ t('This entry is a draft. It affects no account balance and appears in no report until it is posted.') }}
            <span v-if="!entry.is_balanced" class="text-destructive">
                {{ t('It is currently out of balance by :difference and cannot be posted.', { difference: entry.difference }) }}
            </span>
        </div>

        <div
            v-if="entry.reversal_of || entry.reversed_by"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 text-sm"
        >
            <template v-if="entry.reversal_of">
                {{ t('This entry reverses') }}
                <Link :href="`${index().url}/${entry.reversal_of.id}`" class="font-mono hover:underline">
                    {{ entry.reversal_of.journal_number }}
                </Link>.
            </template>
            <template v-if="entry.reversed_by">
                {{ t('This entry was reversed by') }}
                <Link :href="`${index().url}/${entry.reversed_by.id}`" class="font-mono hover:underline">
                    {{ entry.reversed_by.journal_number }}
                </Link>. {{ t('Its own lines remain exactly as posted — both sides stay on the record.') }}
            </template>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <h3 class="text-sm font-medium">{{ entry.description }}</h3>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Account') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Description') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Debit') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in entry.lines"
                        :key="line.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ line.account_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ line.account_name }}</span>
                        </td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">{{ line.description ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ Number(line.debit) > 0 ? money(line.debit) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ Number(line.credit) > 0 ? money(line.credit) : '—' }}
                        </td>
                    </tr>
                </tbody>
                <tfoot class="bg-muted/30">
                    <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3 font-medium" colspan="2">{{ t('Totals (:currency)', { currency }) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(entry.debit_total) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(entry.credit_total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Created by') }}</dt>
                    <dd class="mt-1 text-sm">{{ entry.created_by ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Posted') }}</dt>
                    <dd class="mt-1 text-sm">
                        {{ entry.posted_at ? t(':date by :user', { date: entry.posted_at, user: entry.posted_by ?? '—' }) : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                    <dd class="mt-1 text-sm">
                        {{ entry.reversed_at ? t(':date by :user', { date: entry.reversed_at, user: entry.reversed_by_user ?? '—' }) : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Fiscal period') }}</dt>
                    <dd class="mt-1 text-sm">{{ entry.fiscal_period ?? '—' }} ({{ entry.period_status ?? '—' }})</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Source') }}</dt>
                    <dd class="mt-1 text-sm">{{ entry.source }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('A posted entry is permanent. Correcting one means posting a reversing entry, so both the original and its correction stay on the record — that is what makes the books auditable. Account balances are derived from these lines and are never stored.') }}
        </p>
    </div>
</template>
