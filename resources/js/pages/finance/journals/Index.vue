<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/journals';
import type { JournalRow, Paginated, StatusOption } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    entries: Paginated<JournalRow>;
    filters: { search: string; status: string; fiscal_period_id: number | null } & ListControlValues;
    statuses: StatusOption[];
    periods: { id: number; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Journal entries', href: index() }] } });

const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    fiscal_period_id: props.filters.fiscal_period_id ? String(props.filters.fiscal_period_id) : '',
});

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}


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

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="grid gap-1 text-xs w-full sm:w-56">
                <span class="text-muted-foreground">{{ t('Search') }}</span>
                <Input v-model="filters.search" type="search" :placeholder="t('Search number or description')" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-48">
                <span class="text-muted-foreground">{{ t('Status') }}</span>
                <SearchableSelect v-model="filters.status" :options="[{ value: '', label: t('All statuses') }, ...statuses.map((s) => ({ value: s.value, label: s.label }))]" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-48">
                <span class="text-muted-foreground">{{ t('Fiscal period') }}</span>
                <SearchableSelect v-model="filters.fiscal_period_id" :options="[{ value: '', label: t('All periods') }, ...periods.map((p) => ({ value: String(p.id), label: p.name }))]" />
            </div>
        </ListControls>

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
                            <SortHeader label="Journal No" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Description" column="description" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Source" column="source" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Debit" column="debit" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Credit" column="credit" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
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

        </template>

        <ListPagination :page="entries" />
    </div>
</template>
