<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/journals';
import type { JournalRow, Paginated, StatusOption } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    entries: Paginated<JournalRow>;
    filters: { search: string; status: string; fiscal_period_id: number | null; from: string | null; to: string | null };
    statuses: StatusOption[];
    periods: { id: number; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Journal entries', href: index() }] } });

const { can } = usePermissions();

const status = ref(props.filters.status ?? '');
const periodId = ref(props.filters.fiscal_period_id ? String(props.filters.fiscal_period_id) : '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

function applyFilters(): void {
    router.get(
        index().url,
        {
            status: status.value || undefined,
            fiscal_period_id: periodId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([status, periodId, from, to], applyFilters);

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const { t } = useT();
</script>

<template>
    <Head :title="t('Journal entries')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Journal entries"
                :description="t('Double-entry journals in :currency. A draft affects nothing until it is posted.', { currency })"
            />
            <Button v-if="can('finance.journal_create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New journal entry') }}</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="grid gap-1.5">
                <label for="f-from" class="text-sm font-medium">{{ t('From') }}</label>
                <input id="f-from" v-model="from" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="f-to" class="text-sm font-medium">{{ t('To') }}</label>
                <input id="f-to" v-model="to" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="f-status" class="text-sm font-medium">{{ t('Status') }}</label>
                <select id="f-status" v-model="status" :class="controlClass">
                    <option value="">{{ t('All statuses') }}</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <label for="f-period" class="text-sm font-medium">{{ t('Fiscal period') }}</label>
                <select id="f-period" v-model="periodId" :class="controlClass">
                    <option value="">{{ t('All periods') }}</option>
                    <option v-for="p in periods" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                </select>
            </div>
        </div>

        <div
            v-if="entries.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No journal entries found') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ t('The books are empty. Create a manual entry, or open a fiscal period first.') }}
            </p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Journal No') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Description') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Source') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Debit') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in entries.data"
                            :key="row.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link :href="`${index().url}/${row.id}`" class="hover:underline">
                                    {{ row.journal_number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">{{ row.journal_date }}</td>
                            <td class="px-4 py-3">{{ row.description }}</td>
                            <td class="text-muted-foreground px-4 py-3 text-xs">{{ row.source }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(row.debit_total) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(row.credit_total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <Link
                    v-for="row in entries.data"
                    :key="`m-${row.id}`"
                    :href="`${index().url}/${row.id}`"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium">{{ row.journal_number }}</p>
                            <p class="text-muted-foreground mt-0.5 text-xs">{{ row.journal_date }}</p>
                        </div>
                        <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                    </div>
                    <p class="mt-2 text-sm">{{ row.description }}</p>
                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div><dt class="font-medium">{{ t('Debit') }}</dt><dd class="font-mono">{{ money(row.debit_total) }}</dd></div>
                        <div><dt class="font-medium">{{ t('Credit') }}</dt><dd class="font-mono">{{ money(row.credit_total) }}</dd></div>
                    </dl>
                </Link>
            </div>

            <div v-if="entries.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-muted-foreground text-sm">
                    {{ t('Showing :from–:to of :total', { from: entries.from ?? 0, to: entries.to ?? 0, total: entries.total }) }}
                </p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button
                        v-for="link in entries.links"
                        :key="link.label"
                        :variant="link.active ? 'default' : 'outline'"
                        size="sm"
                        :disabled="!link.url"
                        @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })"
                        v-html="link.label"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
